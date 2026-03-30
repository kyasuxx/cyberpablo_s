<?php
session_start();
require_once 'config/connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// =========================================================================
// FEATURE 1: PREDICTIVE INTELLIGENCE & FORECASTING
// =========================================================================

// 1A. Global Trend Forecasting (Holt's Exponential Smoothing)
$forecast_sql = "
    SELECT DATE_FORMAT(incident_date, '%Y-%m') as mth_label, COUNT(*) as count 
    FROM incidents 
    WHERE incident_date IS NOT NULL AND incident_date != '0000-00-00'
    GROUP BY mth_label 
    ORDER BY mth_label ASC
";
$f_result = $conn->query($forecast_sql);

$x_values = []; 
$y_values = []; 
$labels   = []; 
$counter  = 1;

while($row = $f_result->fetch_assoc()) {
    $x_values[] = $counter;
    $y_values[] = (int)$row['count'];
    $labels[]   = date('M Y', strtotime($row['mth_label'])); 
    $counter++;
}

if (count($x_values) == 1) {
    array_unshift($x_values, 0); 
    array_unshift($y_values, 0); 
    array_unshift($labels, "Start");
    $x_values = [1, 2];
}

$prediction = 0;
$trend_line = []; 
$confidence_msg = "Insufficient Data";
$trend_color = "#6c757d"; 

if (count($x_values) >= 2) {
    $n = count($y_values);
    $alpha = 0.6; 
    $beta = 0.4;  
    
    $level = $y_values[0];
    $trend = $y_values[1] - $y_values[0];
    $trend_line[] = $level;

    for ($i = 1; $i < $n; $i++) {
        $prev_level = $level;
        $level = ($alpha * $y_values[$i]) + ((1 - $alpha) * ($prev_level + $trend));
        $trend = ($beta * ($level - $prev_level)) + ((1 - $beta) * $trend);
        $trend_line[] = round($level, 1);
    }

    $prediction = round($level + $trend);
    if ($prediction < 0) $prediction = 0;

    if ($trend > 2) {
        $confidence_msg = "High Acceleration Risk";
        $trend_color = "#dc3545"; 
    } elseif ($trend > 0) {
        $confidence_msg = "Upward Trajectory";
        $trend_color = "#ffc107"; 
    } elseif ($trend < 0) {
        $confidence_msg = "Downtrend (Decaying)";
        $trend_color = "#28a745"; 
    } else {
        $confidence_msg = "Stable / Baseline";
        $trend_color = "#17a2b8"; 
    }
}
$labels[] = "Next Month"; // Add label for the prediction point
$trend_line[] = $prediction; // Add prediction to the graph line

// 1B. Per-Barangay AI Trend Detection (Momentum Algorithm)
$ai_barangay_spikes = [];
$brgy_trend_sql = "
    SELECT * FROM (
        SELECT b.official_name,
               SUM(CASE WHEN i.incident_date >= DATE_SUB((SELECT MAX(incident_date) FROM incidents), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_30,
               SUM(CASE WHEN i.incident_date >= DATE_SUB((SELECT MAX(incident_date) FROM incidents), INTERVAL 60 DAY) AND i.incident_date < DATE_SUB((SELECT MAX(incident_date) FROM incidents), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as prev_30
        FROM incidents i
        JOIN barangays b ON i.barangay_id = b.id
        GROUP BY i.barangay_id, b.official_name
    ) AS trend_data
    WHERE recent_30 > prev_30 AND recent_30 > 0
    ORDER BY (recent_30 - prev_30) DESC
    LIMIT 4
";
$trend_res = $conn->query($brgy_trend_sql);
while ($tr = $trend_res->fetch_assoc()) {
    $spike_pct = $tr['prev_30'] > 0 ? round((($tr['recent_30'] - $tr['prev_30']) / $tr['prev_30']) * 100) : 100;
    $ai_barangay_spikes[] = [
        'name' => $tr['official_name'],
        'spike' => "+{$spike_pct}%",
        'cases' => $tr['recent_30']
    ];
}

// =========================================================================
// FEATURE 2: TIERED HOTSPOT DETECTION (Per Barangay)
// =========================================================================
$critical_hotspots = [];
$monitored_areas = [];
$total_critical_cases = 0;

$h_sql = "SELECT b.id, b.official_name, COUNT(*) as count 
          FROM incidents i 
          JOIN barangays b ON i.barangay_id = b.id 
          WHERE i.status IN ('Open', 'Under Investigation') 
          GROUP BY i.barangay_id 
          ORDER BY count DESC";
          
$h_result = $conn->query($h_sql);

while ($row = $h_result->fetch_assoc()) {
    if ($row['count'] >= 3) {
        $critical_hotspots[] = $row;
        $total_critical_cases++;
    } elseif ($row['count'] > 0) {
        $monitored_areas[] = $row;
    }
}
$js_hotspots = json_encode($critical_hotspots);

// =========================================================================
// DATA FETCHING
// =========================================================================
$barangay_list = [];
$barangay_result = $conn->query("SELECT id, official_name FROM barangays ORDER BY official_name");
while ($b = $barangay_result->fetch_assoc()) {
    $barangay_list[] = $b;
}

// --- RECENT ACTIVITY FEED LOGIC (ADMIN ONLY) ---
$activities = [];
if ($role === 'admin') {
    $activity_sql = "
        SELECT al.action, al.timestamp AS log_time, u.username 
        FROM audit_log al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.timestamp DESC 
        LIMIT 10
    ";
    $activity_result = $conn->query($activity_sql);

    if ($activity_result) {
        while ($row = $activity_result->fetch_assoc()) {
            $raw_action = $row['action'];
            $clean_action = $raw_action;
            $action_type = "default";
            
            if ($raw_action === 'excel_import') {
                $clean_action = "Imported new cases via batch upload.";
                $action_type = "import";
            } elseif (strpos($raw_action, 'edit_cases_') === 0) {
                $case_id = str_replace('edit_cases_', '', $raw_action);
                $clean_action = "Updated case file details for " . $case_id . ".";
                $action_type = "update";
            } elseif (strpos($raw_action, 'Uploaded Evidence') !== false) {
                $clean_action = $raw_action . ".";
                $action_type = "evidence";
            } elseif ($raw_action === 'login') {
                $clean_action = "Logged into the system.";
                $action_type = "auth";
            } elseif ($raw_action === 'view_dashboard') {
                continue; 
            }
            
            $activities[] = [
                'user' => $row['username'] ? strtoupper($row['username']) : 'SYSTEM',
                'action' => $clean_action,
                'time' => date('M d, Y - h:i A', strtotime($row['log_time'])),
                'type' => $action_type
            ];
        }
    }
}

$ip = $_SERVER['REMOTE_ADDR'];
$stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'view_dashboard', ?)");
$stmt->bind_param("is", $user_id, $ip);
$stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberPablo Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

    
</head>

<body>

<?php require_once 'header.php'; ?>

    <div class="map-container">
        <div id="map"></div>
        
        <div class="map-buttons-modern">
            <?php if (!empty($critical_hotspots)): ?>
                <button id="alertTriggerBtn" class="modern-float-btn alert-critical" onclick="toggleAlertSidebar()">
                    <span class="icon"></span> Critical Alert 
                    <span class="badge"><?= count($critical_hotspots) ?></span>
                </button>
            <?php elseif (!empty($monitored_areas)): ?>
                <button id="alertTriggerBtn" class="modern-float-btn" style="border-left: 4px solid #f57c00;" onclick="toggleAlertSidebar()">
                    <span class="icon"></span> Monitored
                    <span class="badge" style="background:#f57c00;"><?= count($monitored_areas) ?></span>
                </button>
            <?php else: ?>
                <button id="alertTriggerBtn" class="modern-float-btn alert-safe" onclick="toggleAlertSidebar()">
                    <span class="icon"></span> City Safe
                </button>
            <?php endif; ?>
            
            <button class="modern-float-btn ai-forecast" onclick="toggleAiSidebar()">
                <span class="icon"></span> AI Forecast
            </button>
            
            <button class="modern-float-btn map-tools" onclick="toggleControlsSidebar()">
                <span class="icon"></span> Map Tools
            </button>

            <button class="modern-float-btn" style="border-left: 4px solid #6f42c1; color: #6f42c1;" onclick="window.location.href='manage_pins.php'">
                <span class="icon"></span> Manage Pins
            </button>

            <?php if ($role === 'admin'): ?>
            <button class="modern-float-btn sys-activity" onclick="toggleActivitySidebar()">
                <span class="icon"></span> System Log
            </button>
            <?php endif; ?>
        </div>

        <div class="map-mode-controls">
            <button type="button" id="btnHeatmapMode" class="map-mode-btn active" title="Heatmap View"><h2 style="margin:0; pointer-events:none;"><i class="fa-solid fa-map"></i></h2></button>
            <button type="button" id="btnMarkerMode" class="map-mode-btn" title="Marker View"><h2 style="margin:0; pointer-events:none;"><i class="fa-solid fa-map-pin"></i></h2></button>
            <button type="button" id="btnPinMode" class="map-mode-btn" title="Tactical Pins View"><h2 style="margin:0; pointer-events:none; color: #d32f2f;"><i class="fa-solid fa-location-crosshairs"></i></h2></button>
        </div>

        <div class="map-legend-overlay" id="mapLegend">
            <h4>Incident Legend</h4>
            <div class="legend-item"><div class="legend-color" style="background: #f44336;"></div><span>Phishing</span></div>
            <div class="legend-item"><div class="legend-color" style="background: #ff9800;"></div><span>Online Fraud</span></div>
            <div class="legend-item"><div class="legend-color" style="background: #9c27b0;"></div><span>Identity Theft</span></div>
            <div class="legend-item"><div class="legend-color" style="background: #e91e63;"></div><span>Cyber Harassment</span></div>
            <div class="legend-item"><div class="legend-color" style="background: #607d8b;"></div><span>Others</span></div>
        </div>
    </div>

    <div id="controlsSidebar" class="sidebar">
        <div class="sidebar-header">
            <h3>Filters & Data</h3>
            <button class="sidebar-close" onclick="toggleControlsSidebar()">&times;</button>
        </div>
        <div class="sidebar-content">
            <div class="widget">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0;">Filters</h4>
                    <button onclick="clearMapFilters()" style="background: none; border: none; color: #d32f2f; font-size: 12px; cursor: pointer; text-decoration: underline; font-weight: bold;">Clear All</button>
                </div>
                <div class="control-group">
                    <label>Search Keyword (Case, Name, Modus):</label>
                    <input type="text" id="searchFilter" placeholder="Type and wait..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; margin-top: 5px;">
                </div>
                <div class="control-group">
                    <label>Incident Type:</label>
                    <select id="typeFilter" style="width: 100%; padding: 8px; margin-top: 5px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">All Types</option>
                        <option value="Phishing">Phishing</option>
                        <option value="Online Fraud">Online Fraud</option>
                        <option value="Identity Theft">Identity Theft</option>
                        <option value="Cyber Harassment">Cyber Harassment</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Status:</label>
                    <select id="statusFilter" style="width: 100%; padding: 8px; margin-top: 5px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">All Status</option>
                        <option value="Open">Open</option>
                        <option value="Under Investigation">Under Investigation</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Barangay:</label>
                    <select id="barangayFilter">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangay_list as $barangay): ?>
                            <option value="<?= $barangay['id'] ?>"><?= htmlspecialchars($barangay['official_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <label>Date Range:</label>
                    <div style="display:flex; gap:5px; margin-top: 5px;">
                        <input type="date" id="dateFrom" style="width: 50%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
                        <input type="date" id="dateTo" style="width: 50%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
                    </div>
                </div>
            </div>
            <div class="widget">
                <h4>Current Statistics</h4>
                <div class="stat-item"><span>Total Incidents</span><span class="stat-value" id="totalStat">0</span></div>
                <div class="stat-item"><span>Open Cases</span><span class="stat-value" id="openStat">0</span></div>
                <div class="stat-item"><span>Investigating</span><span class="stat-value" id="investigatingStat">0</span></div>
            </div>
        </div>
    </div>

    <div id="alertSidebar" class="sidebar">
        <div class="sidebar-header" style="background: <?= $total_critical_cases > 0 ? '#dc3545' : '#28a745' ?>; color: white;">
            <h3>Barangay Alerts</h3>
            <button class="sidebar-close" onclick="toggleAlertSidebar()" style="color:white;">&times;</button>
        </div>
        <div class="sidebar-content">
            
            <?php if (empty($critical_hotspots) && empty($monitored_areas)): ?>
                <div style="text-align: center; padding: 30px 10px;">
                    <h1 style="font-size: 40px; margin: 0;"></h1>
                    <h3 style="color: #28a745;">City is Stable</h3>
                    <p style="color: #666; font-size: 13px;">No active hotspots detected in any barangay.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($critical_hotspots)): ?>
                <h4 style="color: #dc3545; border-bottom: 1px solid #ffcdd2; padding-bottom: 5px;">CRITICAL HOTSPOTS (3+ Cases)</h4>
                <ul style="list-style: none; padding: 0; margin: 0 0 20px 0;">
                    <?php foreach ($critical_hotspots as $c): ?>
                        <li style="margin-bottom: 8px; border-radius: 4px;">
                            <a href="cases.php?barangay=<?= $c['id'] ?>" style="padding: 10px; background: #fff5f5; border-left: 4px solid #dc3545; display: flex; justify-content: space-between; text-decoration: none; color: inherit; border-radius: 4px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                <strong><?= htmlspecialchars($c['official_name']) ?></strong>
                                <span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: bold;"><?= $c['count'] ?> Active &raquo;</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($monitored_areas)): ?>
                <h4 style="color: #f57c00; border-bottom: 1px solid #ffe0b2; padding-bottom: 5px;">🟡 MONITORED AREAS (1-2 Cases)</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($monitored_areas as $m): ?>
                        <li style="margin-bottom: 5px; border-radius: 4px;">
                            <a href="cases.php?barangay=<?= $m['id'] ?>" style="padding: 8px 10px; background: #fff8e1; border-left: 4px solid #f57c00; display: flex; justify-content: space-between; font-size: 14px; text-decoration: none; color: inherit; border-radius: 4px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                <span><?= htmlspecialchars($m['official_name']) ?></span>
                                <span style="color: #f57c00; font-weight: bold;"><?= $m['count'] ?> Case(s) &raquo;</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </div>
    </div>

    <div id="aiSidebar" class="sidebar">
        <div class="sidebar-header" style="background: #6610f2; color: white;">
            <h3>Predictive Intelligence</h3>
            <button class="sidebar-close" onclick="toggleAiSidebar()" style="color: white;">&times;</button>
        </div>
        
        <div class="sidebar-content">
            <div class="widget" style="border-left: 5px solid #6610f2; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4 style="color: #6610f2; margin: 0 0 10px 0;">City-Wide Trend Forecast</h4>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <div style="font-size: 10px; text-transform: uppercase; color: #888; font-weight: bold;">Next Month Projection</div>
                        <div style="font-size: 36px; font-weight: 800; color: #333; line-height: 1;">
                            <?= $prediction ?> <span style="font-size: 14px; font-weight: normal; color: #777;">cases</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 11px; font-weight: bold; color: <?= $trend_color ?>; background: rgba(0,0,0,0.05); padding: 5px 10px; border-radius: 4px; display: inline-block;">
                            <?= $confidence_msg ?>
                        </div>
                    </div>
                </div>

                <div style="height: 150px; width: 100%; margin-top: 10px;">
                    <canvas id="forecastChart"></canvas>
                </div>
                <div style="font-size: 10px; color: #999; margin-top: 15px; border-top: 1px solid #eee; padding-top: 5px; font-style: italic;">
                </div>
            </div>

            <div class="widget" style="margin-top: 20px;">
                <h4 style="margin: 0 0 10px 0; color: #333;"><i class="fa-solid fa-arrow-trend-up"></i> Emerging Target Areas</h4>
                <p style="font-size: 12px; color: #666; margin-top:0;">Barangays with the highest spike in momentum compared to last month.</p>
                
                <?php if (empty($ai_barangay_spikes)): ?>
                    <div style="padding: 10px; background: #e8f5e9; color: #2e7d32; border-radius: 4px; font-size: 13px; text-align: center;">
                        No specific barangays show dangerous upward momentum at this time.
                    </div>
                <?php else: ?>
                    <?php foreach($ai_barangay_spikes as $spike): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: white; border: 1px solid #eee; margin-bottom: 5px; border-radius: 4px;">
                            <div>
                                <strong style="color: #003366; font-size: 14px;"><?= $spike['name'] ?></strong>
                                <div style="font-size: 11px; color: #888;">Recent Volume: <?= $spike['cases'] ?> cases</div>
                            </div>
                            <div style="background: #ffebee; color: #c62828; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px;">
                                <?= $spike['spike'] ?> Spike
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php if ($role === 'admin'): ?>
    <div id="activitySidebar" class="sidebar">
        <div class="sidebar-header" style="background: #212529; color: white;">
            <h3>System Activity Log</h3>
            <button class="sidebar-close" onclick="toggleActivitySidebar()" style="color: white;">&times;</button>
        </div>
        <div class="sidebar-content" style="padding: 0;">
            <div style="max-height: 100%; overflow-y: auto; padding: 20px;">
                <?php if (empty($activities)): ?>
                    <p style="color: #999; font-size: 13px; font-style: italic; text-align: center;">No recent activity recorded.</p>
                <?php else: ?>
                    <div style="border-left: 2px solid #dee2e6; margin-left: 10px; padding-left: 15px;">
                        <?php foreach ($activities as $log): ?>
                            <?php 
                                $dot_color = "#003366"; 
                                if ($log['type'] === 'import') $dot_color = "#28a745"; 
                                if ($log['type'] === 'update') $dot_color = "#f39c12"; 
                                if ($log['type'] === 'evidence') $dot_color = "#d94c23"; 
                                if ($log['type'] === 'auth') $dot_color = "#6c757d"; 
                            ?>
                            <div style="position: relative; margin-bottom: 20px;">
                                <div style="position: absolute; left: -22px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: <?= $dot_color ?>; border: 2px solid white;"></div>
                                <div style="font-size: 11px; color: #888; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;"><?= $log['time'] ?></div>
                                <div style="font-size: 13px; margin-top: 3px; color: #333; line-height: 1.4;">
                                    <strong style="color: #003366;"><?= htmlspecialchars($log['user']) ?></strong><br>
                                    <?= htmlspecialchars($log['action']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="hotspotToast" style="display:none; position: fixed; top: 90px; right: 20px; width: 320px; background: white; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-left: 5px solid #dc3545; z-index: 9999; animation: slideInRight 0.5s ease-out;">
        <div style="padding: 15px; position: relative;">
            <button onclick="dismissToast()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 20px; color: #999; cursor: pointer; line-height: 1;">&times;</button>
            <h4 style="margin: 0 0 8px 0; color: #dc3545; font-size: 15px;">Critical Hotspots Detected</h4>
            <p style="margin: 0 0 10px 0; font-size: 12px; color: #666;">The following areas require immediate attention:</p>
            
            <ul style="margin: 0; padding-left: 0; list-style: none; font-size: 13px;">
                <?php foreach ($critical_hotspots as $c): ?>
                    <li style="margin-bottom: 5px; border-radius: 4px;">
                        <a href="cases.php?barangay=<?= $c['id'] ?>" style="background: #fff5f5; padding: 6px 10px; border-radius: 4px; display: flex; justify-content: space-between; text-decoration: none; color: inherit; border: 1px solid transparent; transition: border 0.2s;" onmouseover="this.style.borderColor='#dc3545'" onmouseout="this.style.borderColor='transparent'">
                            <strong><?= htmlspecialchars($c['official_name']) ?></strong> 
                            <span style="color: #dc3545; font-weight: bold; text-decoration: underline;">View <?= $c['count'] ?> cases &raquo;</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
    // ==========================================
    // 1. INITIALIZATION & GLOBALS
    // ==========================================
    const urlParams = new URLSearchParams(window.location.search);
    
    let targetCases = [];
    if (urlParams.get('case')) targetCases.push(urlParams.get('case').trim());
    if (urlParams.get('case_a')) targetCases.push(urlParams.get('case_a').trim());
    if (urlParams.get('case_b')) targetCases.push(urlParams.get('case_b').trim());
    
    let hasAutoZoomed = false; 
    
    // NEW MAP STATE LOGIC ('heatmap', 'marker', 'pin')
    let currentMapMode = (targetCases.length > 0) ? 'marker' : 'heatmap';

    const initialLat = targetCases.length > 0 ? (parseFloat(urlParams.get('lat')) || 14.0702) : 14.0702;
    const initialLng = targetCases.length > 0 ? (parseFloat(urlParams.get('lng')) || 121.3256) : 121.3256;
    const initialZoom = targetCases.length > 0 ? 18 : 13;

    const map = L.map('map').setView([initialLat, initialLng], initialZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);

    let allIncidents = [];
    let heatmapLayer = null;
    let markerClusterGroup = null;
    let tacticalPinLayer = L.layerGroup().addTo(map); // NEW LAYER FOR PINS
    
    let markersArray = [];
    const typeColors = {
        'Phishing': '#f44336', 'Online Fraud': '#ff9800', 'Identity Theft': '#9c27b0', 'Cyber Harassment': '#e91e63', 'Others': '#607d8b'
    };

    let brgyChoices = null;
    if(document.getElementById('barangayFilter')) {
        brgyChoices = new Choices('#barangayFilter', { searchEnabled: true });
    }


    // Helper for Tactical Pins (UPDATED FOR SVG HEX COLORS)
    function getPinIcon(hexColor) {
        const svgIcon = `
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="30" height="45">
            <path fill="${hexColor}" stroke="#ffffff" stroke-width="2" d="M12 0C5.373 0 0 5.373 0 12c0 8.4 12 24 12 24s12-15.6 12-24c0-6.627-5.373-12-12-12z"/>
            <circle cx="12" cy="11" r="5" fill="#ffffff" fill-opacity="0.8"/>
        </svg>`;

        return L.divIcon({
            html: svgIcon,
            className: '', // Removes Leaflet's default white square styling
            iconSize: [30, 45],
            iconAnchor: [15, 45],
            popupAnchor: [0, -40]
        });
    }

    // ==========================================
    // 2. CHART.JS RENDER LOGIC
    // ==========================================
    const ctx = document.getElementById('forecastChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                {
                    label: 'Actual Cases',
                    data: <?= json_encode($y_values) ?>,
                    borderColor: '#6c757d',
                    backgroundColor: '#6c757d',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 3
                },
                {
                    label: 'AI Trend Line',
                    data: <?= json_encode($trend_line) ?>,
                    borderColor: '<?= $trend_color ?>',
                    backgroundColor: 'rgba(102, 16, 242, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { display: true, ticks: { font: { size: 10 } } },
                y: { display: true, beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });

    // ==========================================
    // 3. SIDEBAR LOGIC
    // ==========================================
    function closeAllSidebars() {
        document.getElementById('controlsSidebar').classList.remove('open');
        document.getElementById('alertSidebar').classList.remove('open');
        const aiSidebar = document.getElementById('aiSidebar');
        if(aiSidebar) aiSidebar.classList.remove('open');

        const actSidebar = document.getElementById('activitySidebar');
        if(actSidebar) actSidebar.classList.remove('open');
    }

    window.toggleControlsSidebar = function() {
        const sb = document.getElementById('controlsSidebar');
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    window.toggleAlertSidebar = function() {
        const sb = document.getElementById('alertSidebar');
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    window.toggleAiSidebar = function() {
        const sb = document.getElementById('aiSidebar');
        if (!sb) return; 
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    window.toggleActivitySidebar = function() {
        const sb = document.getElementById('activitySidebar');
        if (!sb) return; 
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    window.dismissToast = function() {
        document.getElementById('hotspotToast').style.display = 'none';
        sessionStorage.setItem('cyberpablo_alert_dismissed', 'true');
    }

    // ==========================================
    // 4. MAP DATA & RENDERING
    // ==========================================
    function loadIncidents() {
        const url = new URL('../api/incidents.php', window.location.origin + window.location.pathname);
        const ids = ['searchFilter', 'typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'];
        const params = { searchFilter: 'search', typeFilter: 'type', statusFilter: 'status', barangayFilter: 'barangay', dateFrom: 'date_from', dateTo: 'date_to' };
        
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) url.searchParams.set(params[id], el.value);
        });

        url.searchParams.set('_t', new Date().getTime());

        fetch(url, { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                allIncidents = data;
                renderMap(allIncidents);
                updateStats(allIncidents);
            })
            .catch(err => console.error("Map Fetch Error:", err));
    }
    
    function renderMap(incidents) {
        // Clear all layers
        if (heatmapLayer) map.removeLayer(heatmapLayer);
        if (markerClusterGroup) map.removeLayer(markerClusterGroup);
        tacticalPinLayer.clearLayers();
        
        markersArray = []; 

        const valid = incidents.filter(inc => {
            const lat = parseFloat(inc.lat), lng = parseFloat(inc.lng);
            return !isNaN(lat) && lat !== 0;
        });

        const btnH = document.getElementById('btnHeatmapMode');
        const btnM = document.getElementById('btnMarkerMode');
        const btnP = document.getElementById('btnPinMode');
        const legend = document.getElementById('mapLegend');

        // Reset UI Classes
        if (btnH) btnH.classList.remove('active');
        if (btnM) btnM.classList.remove('active');
        if (btnP) btnP.classList.remove('active');
        if (legend) legend.style.display = 'none';

        if (currentMapMode === 'heatmap') {
            if (btnH) btnH.classList.add('active');

            const heatData = valid.map(inc => {
                let severity = 0.5; 
                const type = inc.incident_type || "";
                if (type.includes('Identity Theft') || type.includes('Hacking') || type.includes('Sextortion')) { severity = 1.0; } 
                else if (type.includes('Online Fraud') || type.includes('Phishing')) { severity = 0.8; }
                
                const incDate = new Date(inc.incident_date);
                const daysOld = Math.abs(Math.floor((new Date() - incDate) / (1000 * 60 * 60 * 24)));
                let timeWeight = Math.max(0.2, 1.0 - (daysOld / 365));
                if (daysOld <= 30) timeWeight = 1.2;

                return [parseFloat(inc.lat), parseFloat(inc.lng), (severity * 0.6) + (timeWeight * 0.4)];
            });

            heatmapLayer = L.heatLayer(heatData, { radius: 35, blur: 25, maxZoom: 17, max: 1.0, gradient: { 0.2: 'blue', 0.4: 'cyan', 0.6: 'lime', 0.8: 'orange', 1.0: 'red' } }).addTo(map);
            
        } else if (currentMapMode === 'marker') {
            if (btnM) btnM.classList.add('active');
            if (legend) legend.style.display = 'block';

            markerClusterGroup = L.markerClusterGroup({ 
                maxClusterRadius: 50,
                iconCreateFunction: function(cluster) {
                    let childCount = cluster.getChildCount();
                    let c = ' marker-cluster-';
                    if (childCount < 10) { c += 'small'; } 
                    else if (childCount < 100) { c += 'medium'; } 
                    else { c += 'large'; }

                    let hasTarget = false;
                    let children = cluster.getAllChildMarkers();
                    for (let i = 0; i < children.length; i++) {
                        if (targetCases.includes(children[i].incidentCaseNo)) {
                            hasTarget = true;
                            break;
                        }
                    }

                    if (hasTarget) {
                        return new L.DivIcon({
                            html: '<div style="background-color: #dc3545; color: white;"><span>' + childCount + '</span></div>',
                            className: 'marker-cluster' + c + ' target-cluster-pulse',
                            iconSize: new L.Point(40, 40)
                        });
                    }

                    return new L.DivIcon({
                        html: '<div><span>' + childCount + '</span></div>',
                        className: 'marker-cluster' + c,
                        iconSize: new L.Point(40, 40)
                    });
                }
            });

            let matchedMarkers = []; 
            
            valid.forEach(inc => {
                let colorKey = 'Others';
                for (let key in typeColors) {
                    if (inc.incident_type && inc.incident_type.includes(key)) { colorKey = key; break; }
                }

                let isTarget = targetCases.includes((inc.case_no || '').trim());
                
                let circleOpts = {
                    radius: isTarget ? 14 : 8, 
                    fillColor: typeColors[colorKey] || '#607d8b',
                    color: isTarget ? '#000' : '#fff', 
                    weight: isTarget ? 3 : 2,
                    fillOpacity: isTarget ? 1.0 : 0.8,
                    className: isTarget ? 'pulsing-target-marker' : '' 
                };

                let popupOpts = isTarget ? { autoClose: false, closeOnClick: false } : {};

                const marker = L.circleMarker([inc.lat, inc.lng], circleOpts).bindPopup(`
                    <div class="popup-title" style="margin-bottom: 5px; font-weight: bold; color: #003366;">${inc.case_no}</div>
                    <div style="font-size: 11px; margin-bottom: 3px;"><strong>Type:</strong> ${inc.incident_type}</div>
                    <div style="font-size: 11px; margin-bottom: 3px;"><strong>Brgy:</strong> ${inc.barangay}</div>
                    
                    <div style="background: #f8f9fa; padding: 5px; border-radius: 4px; margin-top: 5px; border: 1px solid #eee;">
                        <div style="font-size: 11px; color: #2e7d32; margin-bottom: 2px;">
                            <strong>Victim:</strong> ${inc.complainant || '<i style="color:#999;">Not Specified</i>'}
                        </div>
                        <div style="font-size: 11px; color: #c62828;">
                            <strong>Offender:</strong> ${inc.accused || '<i style="color:#999;">Not Specified</i>'}
                        </div>
                    </div>
                    
                    <hr style="margin:8px 0; border:0; border-top:1px solid #ddd;">
                    <div style="text-align: center;">
                        <a href="cases.php?search=${inc.case_no}" style="font-weight: bold; text-decoration: none;">View Full Details &raquo;</a>
                    </div>
                `, popupOpts);
                
                marker.incidentCaseNo = inc.case_no;
                markersArray.push(marker);
                markerClusterGroup.addLayer(marker); 

                if (isTarget) { matchedMarkers.push(marker); }
            });

            markerClusterGroup.on('spiderfied', function(e) {
                matchedMarkers.forEach(m => {
                    if (e.markers.includes(m)) {
                        m.openPopup();
                        let el = m.getElement();
                        if (el) { el.style.animation = 'none'; setTimeout(() => el.style.animation = '', 10); }
                    }
                });
            });

            markerClusterGroup.on('animationend', function(e) {
                matchedMarkers.forEach(m => {
                    if (map.hasLayer(m)) {
                        if (!m.isPopupOpen()) m.openPopup();
                        let el = m.getElement();
                        if (el) { el.style.animation = 'none'; setTimeout(() => el.style.animation = '', 10); }
                    }
                });
            });

            map.addLayer(markerClusterGroup);

            if (matchedMarkers.length > 0) {
                if (!hasAutoZoomed) {
                    if (matchedMarkers.length > 1) {
                        let group = new L.featureGroup(matchedMarkers);
                        map.fitBounds(group.getBounds().pad(0.2), {maxZoom: 18});
                        matchedMarkers.forEach(m => {
                            markerClusterGroup.zoomToShowLayer(m, function() { m.openPopup(); });
                        });
                    } else {
                        markerClusterGroup.zoomToShowLayer(matchedMarkers[0], function () {
                            matchedMarkers[0].openPopup();
                        });
                    }
                    hasAutoZoomed = true; 
                    
                    const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    window.history.replaceState({path:cleanUrl},'',cleanUrl);
                } else {
                    matchedMarkers.forEach(m => { if (map.hasLayer(m)) m.openPopup(); });
                }
            }
        } else if (currentMapMode === 'pin') {
            // NEW PIN MODE LOGIC
            if (btnP) btnP.classList.add('active');
            
            fetch('api_pin.php')
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    data.data.forEach(pin => {
                        L.marker([pin.lat, pin.lng], {icon: getPinIcon(pin.color)})
                         .addTo(tacticalPinLayer)
                         .bindPopup(`<strong style="font-size:14px; color:#333;">${pin.title}</strong><br><small style="color:#666;">Placed by: ${pin.username}</small>`);
                    });
                }
            });
        }
    }

    function updateStats(incidents) {
        document.getElementById('totalStat').textContent = incidents.length;
        document.getElementById('openStat').textContent = incidents.filter(i => i.status === 'Open').length;
        document.getElementById('investigatingStat').textContent = incidents.filter(i => i.status === 'Under Investigation').length;
    }

    // ==========================================
    // 5. EVENT LISTENERS
    // ==========================================
    const btnHeatmap = document.getElementById('btnHeatmapMode');
    const btnMarker = document.getElementById('btnMarkerMode');
    const btnPin = document.getElementById('btnPinMode');

    if (btnHeatmap && btnMarker && btnPin) {
        L.DomEvent.disableClickPropagation(btnHeatmap);
        L.DomEvent.disableClickPropagation(btnMarker);
        L.DomEvent.disableClickPropagation(btnPin);

        btnHeatmap.addEventListener('click', function(e) {
            e.preventDefault();
            if (currentMapMode !== 'heatmap') { currentMapMode = 'heatmap'; renderMap(allIncidents); }
        });

        btnMarker.addEventListener('click', function(e) {
            e.preventDefault();
            if (currentMapMode !== 'marker') { currentMapMode = 'marker'; renderMap(allIncidents); }
        });

        btnPin.addEventListener('click', function(e) {
            e.preventDefault();
            if (currentMapMode !== 'pin') { currentMapMode = 'pin'; renderMap(allIncidents); }
        });
    }

    ['typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'].forEach(id => {
        const el = document.getElementById(id);
        if(el) el.addEventListener('change', loadIncidents);
    });

    let searchTimeout = null;
    const searchInput = document.getElementById('searchFilter');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { loadIncidents(); }, 500);
        });
    }

    window.clearMapFilters = function() {
        if(document.getElementById('searchFilter')) document.getElementById('searchFilter').value = '';
        if(document.getElementById('typeFilter')) document.getElementById('typeFilter').value = '';
        if(document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
        if(document.getElementById('dateFrom')) document.getElementById('dateFrom').value = '';
        if(document.getElementById('dateTo')) document.getElementById('dateTo').value = '';
        if (brgyChoices) { brgyChoices.setChoiceByValue(''); } 
        else if (document.getElementById('barangayFilter')) { document.getElementById('barangayFilter').value = ''; }
        loadIncidents(); 
    };

    // --- SMART TOAST ALERT LOGIC ---
    const criticalCount = <?= $total_critical_cases ?>;
    
    window.addEventListener('load', function() {
        const isDismissed = sessionStorage.getItem('cyberpablo_alert_dismissed');
        if (criticalCount > 0 && !isDismissed) {
            document.getElementById('hotspotToast').style.display = 'block';
        }
    });

    fetch('../api/san_pablo_barangays.json')
        .then(response => response.json())
        .then(data => {
            const borderLayer = L.geoJSON(data, {
                style: function() { return { color: "#003366", weight: 2, opacity: 0.6, fillColor: "#003366", fillOpacity: 0.05, dashArray: '5, 5' }; },
                onEachFeature: function(feature, layer) {
                    const bgyName = feature.properties.adm4_en; 
                    layer.bindTooltip(bgyName, { permanent: false, direction: 'center', className: 'bgy-label' });
                    layer.on('click', function() {
                        borderLayer.resetStyle();
                        layer.setStyle({ weight: 3, color: '#FF5722', fillOpacity: 0.2 });
                        if (brgyChoices) {
                            const choices = brgyChoices._currentState.choices;
                            const match = choices.find(c => c.label.toUpperCase().includes(bgyName.toUpperCase()));
                            if(match) { brgyChoices.setChoiceByValue(match.value); loadIncidents(); }
                        }
                    });
                    layer.on('mouseover', function() { if (this.options.weight !== 3) this.setStyle({ weight: 2, fillOpacity: 0.15 }); });
                    layer.on('mouseout', function() { if (this.options.weight !== 3) this.setStyle({ weight: 2, fillOpacity: 0.05 }); });
                }
            }).addTo(map);
        })
        .catch(err => console.error("Error loading borders:", err));

    window.addEventListener('DOMContentLoaded', function() {
        if(document.getElementById('searchFilter')) document.getElementById('searchFilter').value = '';
        if(document.getElementById('typeFilter')) document.getElementById('typeFilter').value = '';
        if(document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
        if(document.getElementById('dateFrom')) document.getElementById('dateFrom').value = '';
        if(document.getElementById('dateTo')) document.getElementById('dateTo').value = '';
        if (brgyChoices) brgyChoices.setChoiceByValue(''); 
        loadIncidents();
    });
</script>
</body>
</html>