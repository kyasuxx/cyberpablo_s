<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// --- PART 1: ANALYTICS DATA FETCHING (Aggregates) ---

// A. Monthly Trend (Last 6 Months)
$trend_labels = [];
$trend_data = [];
$month_sql = "SELECT DATE_FORMAT(incident_date, '%M') as month_name, COUNT(*) as count 
              FROM incidents 
              WHERE incident_date IS NOT NULL
            --   WHERE incident_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(incident_date, '%Y-%m')
              ORDER BY incident_date ASC";
$m_result = $conn->query($month_sql);
while($row = $m_result->fetch_assoc()) {
    $trend_labels[] = $row['month_name'];
    $trend_data[] = $row['count'];
}

// B. Top 5 Hotspot Barangays
$brgy_labels = [];
$brgy_data = [];
$b_sql = "SELECT official_name, COUNT(*) as count 
          FROM incidents 
          JOIN barangays ON incidents.barangay_id = barangays.id 
          GROUP BY barangay_id 
          ORDER BY count DESC LIMIT 5";
$b_result = $conn->query($b_sql);
while($row = $b_result->fetch_assoc()) {
    $brgy_labels[] = $row['official_name'];
    $brgy_data[] = $row['count'];
}

// C. Crime Type Distribution
$type_labels = [];
$type_data = [];
$t_sql = "SELECT incident_type, COUNT(*) as count FROM incidents GROUP BY incident_type";
$t_result = $conn->query($t_sql);
while($row = $t_result->fetch_assoc()) {
    $type_labels[] = $row['incident_type'];
    $type_data[] = $row['count'];
}

// --- PART 2: LINKING ALGORITHMS (The original logic) ---

// Algorithm 1: Repeat Offender (Phonetic)
$suspects = [];
$suspect_links = [];
$result = $conn->query("SELECT id, case_no, accused FROM incidents WHERE accused IS NOT NULL AND accused != '' AND accused != 'Unknown'");
while ($row = $result->fetch_assoc()) $suspects[] = $row;

$count = count($suspects);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $name1 = strtolower(trim($suspects[$i]['accused']));
        $name2 = strtolower(trim($suspects[$j]['accused']));
        if ($name1 === $name2) continue;
        
        $sound_match = metaphone($name1) == metaphone($name2);
        $dist = levenshtein($name1, $name2);
        $len = max(strlen($name1), strlen($name2));
        $ratio = ($len > 0) ? (1 - ($dist / $len)) * 100 : 0;

        if ($sound_match || $ratio > 80) {
            $suspect_links[] = [
                'name_a' => $suspects[$i]['accused'],
                'case_a' => $suspects[$i]['case_no'],
                'name_b' => $suspects[$j]['accused'],
                'case_b' => $suspects[$j]['case_no'],
                'method' => $sound_match ? 'Phonetic Match' : 'Spelling Variation'
            ];
        }
    }
}

// Algorithm 2: Modus Clustering
$modus_clusters = [];
$cases = [];
$result = $conn->query("SELECT case_no, modus_operandi FROM incidents WHERE modus_operandi IS NOT NULL");
$stop_words = ['the', 'and', 'is', 'in', 'at', 'of', 'to', 'a', 'was', 'via', 'sent', 'using', 'link', 'specific', 'crime'];

while ($row = $result->fetch_assoc()) {
    $clean = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $tokens = array_diff(explode(' ', $clean), $stop_words);
    $cases[] = ['case_no' => $row['case_no'], 'tokens' => array_unique($tokens)];
}

for ($i = 0; $i < count($cases); $i++) {
    for ($j = $i + 1; $j < count($cases); $j++) {
        $intersection = array_intersect($cases[$i]['tokens'], $cases[$j]['tokens']);
        if (count($intersection) >= 3) {
            $modus_clusters[] = [
                'case_a' => $cases[$i]['case_no'],
                'case_b' => $cases[$j]['case_no'],
                'keywords' => implode(', ', $intersection)
            ];
        }
    }
}

// Algorithm 3: Serial Victim
$serial_victims = [];
$v_sql = "SELECT hashed_victim_id, COUNT(*) as count 
          FROM incidents 
          WHERE hashed_victim_id IS NOT NULL 
          AND complainant IS NOT NULL 
          AND complainant != '' 
          AND complainant != 'Unknown'
          GROUP BY hashed_victim_id 
          HAVING count > 1 
          ORDER BY count DESC";
$v_result = $conn->query($v_sql);
while ($row = $v_result->fetch_assoc()) {
    $id = $row['hashed_victim_id'];
    $c_sql = "SELECT case_no, complainant FROM incidents WHERE hashed_victim_id = '$id'";
    $c_res = $conn->query($c_sql);
    $cases_list = [];
    $victim_name = "Unknown";
    while ($c = $c_res->fetch_assoc()) {
        $cases_list[] = $c['case_no'];
        if (!empty($c['complainant'])) $victim_name = $c['complainant'];
    }
    $serial_victims[] = [
        'name' => $victim_name,
        'count' => $row['count'],
        'cases' => implode(', ', $cases_list)
    ];
}

// Algorithm 4: Hard Identifier Linking (Phone Number Only)
$hard_links = [];

// 1. SELECT: Only fetch case_no and accused_contact
$h_sql = "SELECT case_no, accused_contact FROM incidents WHERE accused_contact IS NOT NULL AND accused_contact != ''"; 

$incidents_data = [];
$result = $conn->query($h_sql);

if ($result) {
    while($row = $result->fetch_assoc()) {
        $incidents_data[] = $row;
    }
}

// 2. PROCESS: Check for duplicates
$suspect_phones = [];

foreach ($incidents_data as $case) {
    // Clean the number (remove dashes/spaces/parentheses)
    $clean_phone = preg_replace('/[^0-9]/', '', $case['accused_contact']);
    
    // Validation: Ignore short/invalid numbers
    if (strlen($clean_phone) > 6) { 
        if (isset($suspect_phones[$clean_phone])) {
            // FOUND A MATCH!
            $hard_links[] = [
                'type' => 'Suspect Phone',
                'value' => $case['accused_contact'], // Show original format
                'case_a' => $suspect_phones[$clean_phone],
                'case_b' => $case['case_no']
            ];
        } else {
            // Store first occurrence
            $suspect_phones[$clean_phone] = $case['case_no'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Intelligence Analytics - CyberPablo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding: 30px;}
        .back-btn { text-decoration: none; color: #003366; font-weight: bold; }
        
        /* Grid Layouts */
        .chart-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; padding: 30px;}
        .bottom-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; padding: 30px; }
        
        /* Cards */
        .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; color: #444; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        
        /* Lists */
        .alert-list { list-style: none; padding: 0; max-height: 350px; overflow-y: auto; }
        .alert-item { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        
        .tag { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .tag.red { background: #ffebee; color: #c62828; }
        .tag.orange { background: #fff3e0; color: #ef6c00; }
        .tag.blue { background: #e3f2fd; color: #1565c0; }
    </style>
</head>
<body>
<?php require_once 'header.php'; ?>
<div class="header">
    <div>
        <h1 style="color: #003366; margin: 5px 0;">Intelligence & Analytics</h1>
        <p style="color: #666; margin: 0; font-size: 14px;">Real-time data visualization and relational mapping algorithms.</p>
    </div>
    <div style="text-align: right;">
        <span style="font-size: 24px; font-weight: bold; color: #003366;"><?= array_sum($trend_data) ?></span>
        <div style="font-size: 12px; color: #777;">Cases in Analysis Period</div>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <h3>Monthly Crime Trend & Hotspots</h3>
        <div style="height: 250px; display: flex; gap: 20px;">
            <div style="flex: 2;">
                <canvas id="trendChart"></canvas>
            </div>
            <div style="flex: 1; border-left: 1px solid #eee; padding-left: 20px;">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h3>Crime Distribution</h3>
        <div style="height: 250px; position: relative;">
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<h2 style="color: #003366; font-size: 18px; margin-bottom: 15px; padding: 0 30px;">Automated Link Analysis</h2>
<div class="bottom-grid">
    
    <div class="card" style="border-top: 4px solid #c62828;">
        <h3 style="color: #c62828;">Repeat Offenders</h3>
        <?php if (empty($suspect_links)): ?>
            <p style="color: #999; font-style: italic;">No phonetic links detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($suspect_links as $link): ?>
                <li class="alert-item">
                    <span class="tag red">Alias Detected</span><br>
                    <strong><?= $link['name_a'] ?></strong> ↔ <strong><?= $link['name_b'] ?></strong><br>
                    <span style="color: #777;">Method: <?= $link['method'] ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card" style="border-top: 4px solid #f57f17;">
        <h3 style="color: #f57f17;">Modus Pattern Clusters</h3>
        <?php if (empty($modus_clusters)): ?>
            <p style="color: #999; font-style: italic;">No common patterns found.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($modus_clusters as $cluster): ?>
                <li class="alert-item">
                    <span class="tag orange">Linked Script</span><br>
                    <?= $cluster['case_a'] ?> ↔ <?= $cluster['case_b'] ?><br>
                    <span style="color: #555; font-size: 11px;">"<?= $cluster['keywords'] ?>"</span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card" style="border-top: 4px solid #1565c0;">
        <h3 style="color: #1565c0;">Recurring Targets</h3>
        <?php if (empty($serial_victims)): ?>
            <p style="color: #999; font-style: italic;">No serial victims detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($serial_victims as $victim): ?>
                <li class="alert-item">
                    <span class="tag blue">High Risk</span><br>
                    <strong><?= htmlspecialchars($victim['name']) ?></strong><br>
                    <?= $victim['count'] ?> incidents reported.
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div> <div class="card" style="border-top: 4px solid #2e7d32;">
        <h3 style="color: #2e7d32;">Hard Evidence Links</h3>
        <?php if (empty($hard_links)): ?>
            <p style="color: #999; font-style: italic;">No shared contact info detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($hard_links as $link): ?>
                <li class="alert-item">
                    <span class="tag" style="background: #e8f5e9; color: #2e7d32;">Shared <?= $link['type'] ?></span><br>
                    <strong><?= htmlspecialchars($link['value']) ?></strong><br>
                    Linked: <?= $link['case_a'] ?> ↔ <?= $link['case_b'] ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>

<script>
    // 1. Trend Chart
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_labels) ?>,
            datasets: [{
                label: 'New Cases',
                data: <?= json_encode($trend_data) ?>,
                borderColor: '#003366',
                tension: 0.3,
                fill: true,
                backgroundColor: 'rgba(0, 51, 102, 0.1)'
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // 2. Hotspot Bar Chart (Top 5 Barangays)
    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($brgy_labels) ?>,
            datasets: [{
                label: 'Incidents',
                data: <?= json_encode($brgy_data) ?>,
                backgroundColor: ['#c62828', '#ef6c00', '#f9a825', '#1565c0', '#607d8b']
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            indexAxis: 'y', // Horizontal Bar
            plugins: { legend: { display: false } }
        }
    });

    // 3. Crime Type Pie Chart
    new Chart(document.getElementById('pieChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($type_labels) ?>,
            datasets: [{
                data: <?= json_encode($type_data) ?>,
                backgroundColor: [
                    '#f44336', '#9c27b0', '#3f51b5', '#009688', '#ff9800', '#795548'
                ]
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { 
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } 
            } 
        }
    });
</script>

</body>
</html>