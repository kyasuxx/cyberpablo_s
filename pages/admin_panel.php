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

// --- PART 2: UNIFIED INTELLIGENCE ENGINE ---

$cases = [];
// Comprehensive list of placeholders to ignore
$invalid_identifiers = [
    'unidentified', 'unknown', 'n/a', 'none', 'unknown suspect', 
    'pending', '0', 'null', 'a certain unidentified suspect', 'alias'
];

// Fetch cases
$result = $conn->query("SELECT id, case_no, incident_type, incident_date, status, accused, accused_contact, modus_operandi FROM incidents");
while ($row = $result->fetch_assoc()) {
    $raw_accused = strtolower(trim($row['accused']));
    
    // 1. Tag cases that lack real suspect data
    $row['is_unidentified'] = in_array($raw_accused, $invalid_identifiers) || empty($raw_accused) || strlen($raw_accused) < 3;
    
    // 2. Tokenize Modus Operandi for behavioral matching
    $clean_modus = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $words = explode(' ', $clean_modus);
    $row['tokens'] = array_unique(array_filter($words, function($w) {
        return strlen($w) > 3;
    }));

    // 3. Clean Phone numbers for exact matching
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

        // FACTOR 1: PHONE MATCH (60 pts)
        if (!empty($c1['clean_phone']) && strlen($c1['clean_phone']) >= 7 && $c1['clean_phone'] === $c2['clean_phone']) {
            $score += 60;
            $reasons[] = "Shared Phone: " . htmlspecialchars($c1['accused_contact']);
        }

        // FACTOR 2: SUSPECT NAME MATCH (50 pts / 30 pts)
        // Only run if BOTH are not unidentified
        if (!$c1['is_unidentified'] && !$c2['is_unidentified']) {
            $name1 = strtolower(trim($c1['accused']));
            $name2 = strtolower(trim($c2['accused']));
            
            if ($name1 === $name2) {
                $score += 50;
                $reasons[] = "Exact Suspect Match: " . ucwords($name1);
            } else {
                // Fuzzy/Phonetic match for typos
                if (metaphone($name1) == metaphone($name2) || levenshtein($name1, $name2) <= 2) {
                    $score += 30;
                    $reasons[] = "Similar Name/Alias Detected";
                }
            }
        }

        // FACTOR 3: BEHAVIORAL MODUS MATCH (20-40 pts)
        $intersection = array_intersect($c1['tokens'], $c2['tokens']);
        $match_count = count($intersection);
        if ($match_count >= 3) {
            $score += ($match_count >= 5) ? 40 : 20;
            $reasons[] = "Behavioral Patterns ($match_count matching keywords)";
        }

        // Only save high-confidence links
        if ($score >= 50) {
            $syndicate_links[] = [
                'score' => min(100, $score),
                'case_a' => $c1,
                'case_b' => $c2,
                'reasons' => $reasons
            ];
        }
    }
}

// Sort by Highest Confidence Score
usort($syndicate_links, function($a, $b) {
    return $b['score'] <=> $a['score'];
});

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Intelligence Analytics - CyberPablo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/link_analysis.css">
    <style>
        /* UPGRADED INTELLIGENCE DOSSIER STYLES */
        .dossier-card { 
            background: white; 
            border-radius: 10px; 
            padding: 20px; 
            margin-bottom: 20px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
            border: 1px solid #e0e0e0;
            border-left: 6px solid #003366; 
        }
        .dossier-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .confidence-badge {
            font-weight: bold;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .conf-high { background: #ffebee; color: #c62828; }
        .conf-med { background: #fff8e1; color: #f57c00; }
        
        .reason-tag { 
            display: inline-block; 
            background: #f1f3f5; 
            color: #495057; 
            padding: 4px 10px; 
            border-radius: 4px; 
            font-size: 12px; 
            margin-right: 8px; 
            font-weight: 500;
        }

        /* Side-by-Side Comparison Layout */
        .case-comparison {
            display: flex;
            align-items: stretch;
            gap: 15px;
        }
        .case-box {
            flex: 1;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            position: relative;
        }
        .case-box h4 {
            margin: 0 0 10px 0;
            color: #003366;
            font-size: 16px;
            border-bottom: 2px solid #d94c23;
            padding-bottom: 5px;
            display: inline-block;
        }
        .case-meta {
            font-size: 13px;
            color: #555;
            margin: 5px 0;
            display: flex;
            justify-content: space-between;
        }
        .case-status {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .status-open { background: #e3f2fd; color: #d32f2f; }
        .status-investigating { background: #fff3e0; color: #f57c00; }
        .status-closed { background: #e8f5e9; color: #388e3c; }

        .link-connector {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 24px;
        }

        .btn-review {
            display: block;
            text-align: center;
            background: #003366;
            color: white;
            text-decoration: none;
            padding: 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-top: 15px;
            transition: 0.2s;
        }
        .btn-review:hover {
            background: #002244;
        }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="header">
        <div>
            <h1 class="page-title">Intelligence Analytics</h1>
            <p class="page-subtitle">Multi-Factor Entity Resolution & Serial Offender Tracking</p>
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

    <h2 class="section-title">Serial Offender Dossiers</h2>
    <!-- <p style="margin-bottom: 20px; color: #555;">
        The algorithm has detected overlapping identifiers (names, phone numbers, and modus operandi keywords) suggesting repeat criminal activity. High-confidence matches are pushed to the top.
    </p> -->

    <div style="margin-bottom: 40px;">
        
        <?php if (empty($syndicate_links)): ?>
            <div class="card"><p class="empty-msg" style="color: #666; padding: 20px; text-align:center;">No cross-case connections detected in the current database.</p></div>
        <?php else: ?>
            <?php foreach (array_slice($syndicate_links, 0, 30) as $link): 
                $conf_class = $link['score'] >= 80 ? 'conf-high' : 'conf-med';
                $conf_text = $link['score'] >= 80 ? 'HIGH MATCH' : 'PROBABLE MATCH';
            ?>
            <div class="dossier-card">
                <div class="dossier-header">
                    <div>
                        <strong style="font-size: 11px; color: #888; text-transform: uppercase; display:block; margin-bottom: 5px;">Points of Intersection Detected:</strong>
                        <?php foreach ($link['reasons'] as $reason): ?>
                            <span class="reason-tag">🔗 <?= $reason ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="confidence-badge <?= $conf_class ?>">
                        <?= $conf_text ?>
                    </div>
                </div>

                <div class="case-comparison">
                    <div class="case-box">
                        <h4><?= htmlspecialchars($link['case_a']['case_no']) ?></h4>
                        
                        <?php 
                            $statusA = strtolower($link['case_a']['status']);
                            $badgeA = str_contains($statusA, 'open') ? 'status-open' : (str_contains($statusA, 'investigation') ? 'status-investigating' : 'status-closed');
                        ?>
                        <div class="case-meta">
                            <span><strong>Type:</strong> <?= htmlspecialchars(str_replace('Republic Act No. 10175 ', '', $link['case_a']['incident_type'])) ?></span>
                            <span class="case-status <?= $badgeA ?>"><?= htmlspecialchars($link['case_a']['status']) ?></span>
                        </div>
                        <div class="case-meta">
                            <span><strong>Date:</strong> <?= htmlspecialchars(date('M d, Y', strtotime($link['case_a']['incident_date']))) ?></span>
                        </div>
                        
                        <a href="cases.php?search=<?= urlencode($link['case_a']['case_no']) ?>" class="btn-review">Open Case File &raquo;</a>
                    </div>

                    <div class="link-connector">
                        &#8644;
                    </div>

                    <div class="case-box">
                        <h4><?= htmlspecialchars($link['case_b']['case_no']) ?></h4>
                        
                        <?php 
                            $statusB = strtolower($link['case_b']['status']);
                            $badgeB = str_contains($statusB, 'open') ? 'status-open' : (str_contains($statusB, 'investigation') ? 'status-investigating' : 'status-closed');
                        ?>
                        <div class="case-meta">
                            <span><strong>Type:</strong> <?= htmlspecialchars(str_replace('Republic Act No. 10175 ', '', $link['case_b']['incident_type'])) ?></span>
                            <span class="case-status <?= $badgeB ?>"><?= htmlspecialchars($link['case_b']['status']) ?></span>
                        </div>
                        <div class="case-meta">
                            <span><strong>Date:</strong> <?= htmlspecialchars(date('M d, Y', strtotime($link['case_b']['incident_date']))) ?></span>
                        </div>

                        <a href="cases.php?search=<?= urlencode($link['case_b']['case_no']) ?>" class="btn-review">Open Case File &raquo;</a>
                    </div>
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