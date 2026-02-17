<?php
session_start();
require_once 'config/connection.php';



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
        
        // 1. Check for Exact Match
        $is_exact = ($name1 === $name2);

        // 2. Check for Sound/Spelling (only if not exact)
        $sound_match = metaphone($name1) == metaphone($name2);
        $dist = levenshtein($name1, $name2);
        $len = max(strlen($name1), strlen($name2));
        $ratio = ($len > 0) ? (1 - ($dist / $len)) * 100 : 0;

        // Condition: Exact Match OR Sound Match OR High Spelling Similarity
        if ($is_exact || $sound_match || $ratio > 80) {
            
            // Determine the label
            if ($is_exact) {
                $method_label = "Exact Match";
            } elseif ($sound_match) {
                $method_label = "Phonetic Match";
            } else {
                $method_label = "Spelling Variation";
            }

            $suspect_links[] = [
                'name_a' => $suspects[$i]['accused'],
                'case_a' => $suspects[$i]['case_no'],
                'name_b' => $suspects[$j]['accused'],
                'case_b' => $suspects[$j]['case_no'],
                'method' => $method_label
            ];
        }
    }
}

// Algorithm 2: Modus Clustering (Improved)
$modus_clusters = [];
$cases = [];
$result = $conn->query("SELECT case_no, modus_operandi FROM incidents WHERE modus_operandi IS NOT NULL");

// 1. IMPROVED STOP WORDS: Added common police jargon to ignore
$stop_words = [
    'the', 'and', 'is', 'in', 'at', 'of', 'to', 'a', 'was', 'via', 'sent', 
    'using', 'link', 'specific', 'crime', 'for', 'on', 'with', 'by', 'that', 
    'it', 'as', 'an', 'or', 'be', 'from',
    // Domain specific noise (Add these to prevent false alarms):
    'suspect', 'victim', 'accused', 'complainant', 'reported', 'incident', 
    'person', 'unknown', 'stated', 'allegedly', 'investigation', 'police', 'barangay'
];

while ($row = $result->fetch_assoc()) {
    // Clean: Lowercase -> Remove non-alphanumeric -> Remove extra spaces
    $clean = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    
    // Split by space
    $words = explode(' ', $clean);
    
    // Filter: Remove stop words AND empty strings
    $tokens = array_filter($words, function($w) use ($stop_words) {
        return !empty($w) && !in_array($w, $stop_words) && strlen($w) > 2;
    });

    // Store unique meaningful words
    if (!empty($tokens)) {
        $cases[] = ['case_no' => $row['case_no'], 'tokens' => array_unique($tokens)];
    }
}

for ($i = 0; $i < count($cases); $i++) {
    for ($j = $i + 1; $j < count($cases); $j++) {
        $intersection = array_intersect($cases[$i]['tokens'], $cases[$j]['tokens']);
        
        // Threshold: Match if they share 3 or more UNIQUE keywords
        if (count($intersection) >= 3) {
            $modus_clusters[] = [
                'case_a' => $cases[$i]['case_no'],
                'case_b' => $cases[$j]['case_no'],
                'keywords' => implode(', ', $intersection)
            ];
        }
    }
}
// Serial Victim
$serial_victims = [];
$v_sql = "SELECT complainant, COUNT(*) as count 
          FROM incidents 
          WHERE complainant IS NOT NULL 
          AND complainant != '' 
          AND complainant != 'Unknown'
          GROUP BY complainant 
          HAVING count > 1 
          ORDER BY count DESC";

$v_result = $conn->query($v_sql);

while ($row = $v_result->fetch_assoc()) {
    $name = $conn->real_escape_string($row['complainant']);
    
    // Get the specific case numbers for this person
    $c_sql = "SELECT case_no FROM incidents WHERE complainant = '$name'";
    $c_res = $conn->query($c_sql);
    
    $cases_list = [];
    while ($c = $c_res->fetch_assoc()) {
        $cases_list[] = $c['case_no'];
    }

    $serial_victims[] = [
        'name' => $row['complainant'],
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

// --- PREPARE DATA FOR NETWORK GRAPH ---
$nodes = [];
$edges = [];
$added_nodes = [];

// Helper to add node if not exists
function addNode(&$nodes, &$added_nodes, $id, $label, $group) {
    if (!in_array($id, $added_nodes)) {
        $nodes[] = ['id' => $id, 'label' => $label, 'group' => $group];
        $added_nodes[] = $id;
    }
}

// Process Hard Links (Green)
foreach ($hard_links as $link) {
    // Node A (Case 1)
    addNode($nodes, $added_nodes, $link['case_a'], $link['case_a'], 'case');
    // Node B (Case 2)
    addNode($nodes, $added_nodes, $link['case_b'], $link['case_b'], 'case');
    // The Edge
    $edges[] = ['from' => $link['case_a'], 'to' => $link['case_b'], 'label' => $link['type'], 'color' => '#28a745'];
}

// Process Suspect Links (Red)
foreach ($suspect_links as $link) {
    // Here we might want to link Person Name to Person Name instead of Case
    // But let's stick to Case-to-Case for consistency, or mix them.
    // Let's visualize Suspect Names as nodes here:
    $id_a = "S_" . md5($link['name_a']);
    $id_b = "S_" . md5($link['name_b']);
    
    addNode($nodes, $added_nodes, $id_a, $link['name_a'], 'suspect');
    addNode($nodes, $added_nodes, $id_b, $link['name_b'], 'suspect');
    
    $edges[] = ['from' => $id_a, 'to' => $id_b, 'label' => 'Alias/Same', 'color' => '#dc3545'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Intelligence Analytics - CyberPablo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/link_analysis.css">
    <script type="text/javascript" src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
    <style>
        #network-container { height: 500px; border: 1px solid #ddd; background: white; border-radius: 8px; }
    </style>
</head>
<body>
<?php require_once 'header.php'; ?>

<div class="header">
    <div>
        <h1 class="page-title">Intelligence & Analytics</h1>
        <p class="page-subtitle">Real-time data visualization and relational mapping algorithms.</p>
    </div>
    <div class="header-stat-box">
        <span class="stat-number"><?= array_sum($trend_data) ?></span>
        <div class="stat-label">Cases in Analysis Period</div>
        <a href="export_intelligence.php" class="btn-export">
            Export Intelligence Report
        </a>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <h3>Monthly Crime Trend & Hotspots</h3>
        <div class="trend-container">
            <div class="chart-main">
                <canvas id="trendChart"></canvas>
            </div>
            <div class="chart-side">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h3>Crime Distribution</h3>
        <div class="pie-container">
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 30px;">
    <h3>CRIMINAL NETWORK MAP</h3>
    <!-- <div id="network-container"></div> -->
</div>

<h2 class="section-title">Automated Link Analysis</h2>
<div class="bottom-grid">
    
    <div class="card card-border-red">
        <h3 class="text-red">Repeat Offenders</h3>
        <?php if (empty($suspect_links)): ?>
            <p class="empty-msg">No phonetic links detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($suspect_links as $link): ?>
                <li class="alert-item">
                    <div class="alert-content">
                        <span class="tag red">Alias Detected</span>
                        <div style="margin-top: 5px;">
                            <strong><?= $link['name_a'] ?></strong> <span class="case-ref">(<?= $link['case_a'] ?>)</span>
                            <br>
                            <strong><?= $link['name_b'] ?></strong> <span class="case-ref">(<?= $link['case_b'] ?>)</span>
                        </div>
                        <span class="link-method">Method: <?= $link['method'] ?></span>
                    </div>
                    <a href="cases.php?search=<?= urlencode($link['case_a']) ?>" class="btn-sm" target="_blank">
                        Investigate
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card card-border-orange">
        <h3 class="text-orange">Modus Pattern Clusters</h3>
        <?php if (empty($modus_clusters)): ?>
            <p class="empty-msg">No common patterns found.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($modus_clusters as $cluster): ?>
                <li class="alert-item">
                    <div class="alert-content">
                        <span class="tag orange">Linked Script</span>
                        <div style="margin-top: 5px;">
                            <?= $cluster['case_a'] ?> ↔ <?= $cluster['case_b'] ?>
                        </div>
                        <span class="cluster-keywords">"<?= $cluster['keywords'] ?>"</span>
                    </div>
                    <?php 
                        // Pick the first keyword for the search to be safe
                        $first_keyword = explode(',', $cluster['keywords'])[0]; 
                    ?>
                    <a href="cases.php?search=<?= urlencode(trim($first_keyword)) ?>" class="btn-sm" target="_blank">
                        Find Pattern
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card card-border-blue">
        <h3 class="text-blue">Recurring Targets</h3>
        <?php if (empty($serial_victims)): ?>
            <p class="empty-msg">No serial victims detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($serial_victims as $victim): ?>
                <li class="alert-item">
                    <div class="alert-content">
                        <span class="tag blue">High Risk</span>
                        <div style="margin-top: 5px;">
                            <strong><?= htmlspecialchars($victim['name']) ?></strong>
                        </div>
                        <span class="case-ref"><?= $victim['count'] ?> incidents reported.</span>
                    </div>
                    <a href="cases.php?search=<?= urlencode($victim['name']) ?>" class="btn-sm" target="_blank">
                        View History
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
        
    <div class="card card-border-green">
        <h3 class="text-green">Hard Evidence Links</h3>
        <?php if (empty($hard_links)): ?>
            <p class="empty-msg">No shared contact info detected.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($hard_links as $link): ?>
                <li class="alert-item">
                    <div class="alert-content">
                        <span class="tag green">Shared <?= $link['type'] ?></span>
                        <div style="margin-top: 5px;">
                            <strong><?= htmlspecialchars($link['value']) ?></strong>
                        </div>
                        <span class="case-ref">Linked: <?= $link['case_a'] ?> ↔ <?= $link['case_b'] ?></span>
                    </div>
                    <a href="cases.php?search=<?= urlencode($link['value']) ?>" class="btn-sm" target="_blank">
                        Check
                    </a>
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

    // 2. Hotspot Bar Chart
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
            indexAxis: 'y', 
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
                backgroundColor: ['#f44336', '#9c27b0', '#3f51b5', '#009688', '#ff9800', '#795548']
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

    var nodes = new vis.DataSet(<?= json_encode($nodes) ?>);
    var edges = new vis.DataSet(<?= json_encode($edges) ?>);
    var container = document.getElementById('network-container');
    var data = { nodes: nodes, edges: edges };
    var options = {
        groups: {
            case: {shape: 'box', color: '#003366', font: {color:'white'}},
            suspect: {shape: 'ellipse', color: '#dc3545', font: {color:'white'}}
        },
        physics: { stabilization: false }
    };
    var network = new vis.Network(container, data, options);
</script>
</body>
</html>