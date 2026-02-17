<?php
session_start();
require_once 'config/connection.php';

// --- PART 1: ANALYTICS DATA FETCHING (Aggregates) ---

// A. Monthly Trend
$trend_labels = [];
$trend_data = [];
$month_sql = "SELECT DATE_FORMAT(incident_date, '%M') as month_name, COUNT(*) as count 
              FROM incidents 
              WHERE incident_date IS NOT NULL
              GROUP BY DATE_FORMAT(incident_date, '%Y-%m')
              ORDER BY incident_date ASC";
$m_result = $conn->query($month_sql);
while($row = $m_result->fetch_assoc()) {
    $trend_labels[] = $row['month_name'];
    $trend_data[] = $row['count'];
}

// B. Top Hotspots
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

// C. Crime Distribution
$type_labels = [];
$type_data = [];
$t_sql = "SELECT incident_type, COUNT(*) as count FROM incidents GROUP BY incident_type";
$t_result = $conn->query($t_sql);
while($row = $t_result->fetch_assoc()) {
    $type_labels[] = $row['incident_type'];
    $type_data[] = $row['count'];
}

// --- PART 2: LINK ANALYSIS (Multi-Factor Engine) ---

$cases = [];
$stop_words = ['the', 'and', 'is', 'in', 'at', 'of', 'to', 'a', 'was', 'via', 'sent', 'using', 'link', 'specific', 'crime', 'for', 'on', 'with', 'by', 'that', 'it', 'as', 'an', 'or', 'be', 'from', 'suspect', 'victim', 'accused', 'complainant', 'reported', 'incident', 'person', 'unknown', 'stated', 'allegedly', 'investigation', 'police', 'barangay'];

$result = $conn->query("SELECT case_no, accused, accused_contact, modus_operandi FROM incidents");
while ($row = $result->fetch_assoc()) {
    // Clean Modus
    $clean_modus = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $words = explode(' ', $clean_modus);
    $tokens = array_filter($words, function($w) use ($stop_words) {
        return !empty($w) && !in_array($w, $stop_words) && strlen($w) > 3;
    });
    $row['tokens'] = array_unique($tokens);
    
    // Clean phone
    $row['clean_phone'] = preg_replace('/[^0-9]/', '', $row['accused_contact']);
    
    $cases[] = $row;
}

$syndicate_links = [];
$count = count($cases);

for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $c1 = $cases[$i];
        $c2 = $cases[$j];
        
        $score = 0;
        $reasons = [];

        // 1. Check Phone (50 pts)
        if (!empty($c1['clean_phone']) && !empty($c2['clean_phone']) && strlen($c1['clean_phone']) >= 7) {
            if ($c1['clean_phone'] === $c2['clean_phone']) {
                $score += 50;
                $reasons[] = "Shared Phone Number (" . $c1['accused_contact'] . ")";
            }
        }

        // 2. Check Suspect Name (Exact = 50 pts, Similar = 30 pts)
        if (!empty($c1['accused']) && !empty($c2['accused']) && strtolower($c1['accused']) !== 'unknown' && strtolower($c2['accused']) !== 'unknown') {
            $name1 = strtolower(trim($c1['accused']));
            $name2 = strtolower(trim($c2['accused']));
            
            if ($name1 === $name2) {
                $score += 50;
                $reasons[] = "Exact Suspect Match (" . $c1['accused'] . ")";
            } else {
                $sound_match = metaphone($name1) == metaphone($name2);
                $dist = levenshtein($name1, $name2);
                if ($sound_match || ($dist <= 2 && strlen($name1) > 5)) {
                    $score += 30;
                    $reasons[] = "Phonetic Alias Detected (" . $c1['accused'] . " / " . $c2['accused'] . ")";
                }
            }
        }

        // 3. Check Modus (20 pts)
        $intersection = array_intersect($c1['tokens'], $c2['tokens']);
        if (count($intersection) >= 3) {
            $score += 20;
            $reasons[] = "Similar Modus (" . implode(', ', array_slice($intersection, 0, 3)) . "...)";
        }

        // Threshold = 50. This stops dummy data from repeating!
        // To link, they MUST have a shared phone, an exact name, OR a similar name + similar modus.
        if ($score >= 50) {
            $syndicate_links[] = [
                'case_a' => $c1['case_no'],
                'case_b' => $c2['case_no'],
                'reasons' => $reasons
            ];
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
    <link rel="stylesheet" href="../assets/css/link_analysis.css">
    <style>
        .link-card { 
            background: white; 
            border-radius: 8px; 
            padding: 20px; 
            margin-bottom: 15px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
            border-left: 5px solid #003366; 
        }
        .reason-tag { 
            display: inline-block; 
            background: #e9ecef; 
            color: #333; 
            padding: 5px 10px; 
            border-radius: 4px; 
            font-size: 12px; 
            margin-right: 8px; 
            margin-top: 8px; 
        }
    </style>
</head>
<body>
<?php require_once 'header.php'; ?>

<div class="header">
    <div>
        <h1 class="page-title">Intelligence Analytics</h1>
        <p class="page-subtitle">Multi-Factor Entity Resolution</p>
    </div>
    <div class="header-stat-box">
        <span class="stat-number"><?= count($syndicate_links) ?></span>
        <div class="stat-label">Connections Found</div>
        <a href="export_intelligence.php" class="btn-export">Export Report</a>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <h3>Monthly Crime Trend</h3>
        <div class="trend-container" style="height: 300px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    
    <div class="card">
        <h3>Crime Distribution</h3>
        <div class="pie-container" style="height: 300px;">
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title">Case Connections Detected</h2>
<p style="margin-bottom: 20px; color: #555;">The system has analyzed the database to find overlapping identifiers, suggesting linked criminal activity.</p>

<div style="display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 40px;">
    
    <?php if (empty($syndicate_links)): ?>
        <div class="card"><p class="empty-msg" style="color: #666; padding: 20px;">No connections detected in the current dataset.</p></div>
    <?php else: ?>
        <?php foreach (array_slice($syndicate_links, 0, 30) as $link): ?>
        <div class="link-card">
            <h3 style="margin-top: 0; margin-bottom: 5px; color: #003366;">
                Case <?= $link['case_a'] ?> &mdash; Case <?= $link['case_b'] ?>
            </h3>
            
            <div>
                <strong style="font-size: 11px; color: #999; text-transform: uppercase;">Points of Intersection:</strong><br>
                <?php foreach ($link['reasons'] as $reason): ?>
                    <span class="reason-tag"><?= htmlspecialchars($reason) ?></span>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 15px; text-align: right;">
                <a href="cases.php?search=<?= urlencode($link['case_a']) ?>" class="btn-sm" style="background: #003366; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; display: inline-block;">Review Case A</a>
                <a href="cases.php?search=<?= urlencode($link['case_b']) ?>" class="btn-sm" style="background: #003366; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; display: inline-block; margin-left: 5px;">Review Case B</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

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

    // 2. Crime Type Pie Chart
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
            plugins: { legend: { position: 'right' } } 
        } 
    });
</script>
</body>
</html>