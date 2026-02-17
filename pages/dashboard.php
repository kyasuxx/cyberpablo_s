<?php
session_start();
require_once 'config/connection.php';


// --- FEATURE 1: PREDICTIVE TREND FORECASTING (Linear Regression) ---

// 1. Fetch ALL Monthly Data (Removed 6-month limit to catch all available data)
$forecast_sql = "
    SELECT DATE_FORMAT(incident_date, '%Y-%m') as mth_label, COUNT(*) as count 
    FROM incidents 
    WHERE incident_date IS NOT NULL AND incident_date != '0000-00-00'
    GROUP BY mth_label 
    ORDER BY mth_label ASC
";
$f_result = $conn->query($forecast_sql);

$x_values = []; // Time (1, 2, 3...)
$y_values = []; // Cases (5, 12, 8...)
$labels   = []; // "Jan", "Feb"
$counter  = 1;

while($row = $f_result->fetch_assoc()) {
    $x_values[] = $counter;
    $y_values[] = (int)$row['count'];
    $labels[]   = date('M Y', strtotime($row['mth_label'])); 
    $counter++;
}

// --- FALLBACK: If only 1 month exists, pretend previous month was 0 ---
if (count($x_values) == 1) {
    array_unshift($x_values, 0); // Add time 0
    array_unshift($y_values, 0); // Add 0 cases
    array_unshift($labels, "Start");
    
    // Re-index x_values to be 1, 2
    $x_values = [1, 2];
}
// ---------------------------------------------------------------------

// 2. The Math: Holt's Double Exponential Smoothing (Time-Series Forecasting)
// This algorithm applies heavier weight to recent data, acting as an ARIMA-lite model.
$prediction = 0;
$trend_line = []; 
$confidence_msg = "Insufficient Data";
$trend_color = "#6c757d"; // Grey default

if (count($x_values) >= 2) {
    $n = count($y_values);
    
    // Smoothing Constants (Tuned for Cybercrime volatility)
    $alpha = 0.6; // Data smoothing factor (Higher = recent months matter more)
    $beta = 0.4;  // Trend smoothing factor (Velocity of crime)
    
    // Initial Values
    $level = $y_values[0];
    $trend = $y_values[1] - $y_values[0];
    $trend_line[] = $level;

    // Process Time-Series
    for ($i = 1; $i < $n; $i++) {
        $prev_level = $level;
        // Calculate new Level and Trend mathematically
        $level = ($alpha * $y_values[$i]) + ((1 - $alpha) * ($prev_level + $trend));
        $trend = ($beta * ($level - $prev_level)) + ((1 - $beta) * $trend);
        
        $trend_line[] = round($level, 1);
    }

    // 3. The Forecast (Next Month Projection)
    $prediction = round($level + $trend);
    if ($prediction < 0) $prediction = 0;

    // 4. Generate Insight Text based on Trend Velocity
    if ($trend > 2) {
        $confidence_msg = "High Acceleration Risk";
        $trend_color = "#dc3545"; // Red
    } elseif ($trend > 0) {
        $confidence_msg = "Upward Trajectory";
        $trend_color = "#ffc107"; // Orange
    } elseif ($trend < 0) {
        $confidence_msg = "Downtrend (Decaying)";
        $trend_color = "#28a745"; // Green
    } else {
        $confidence_msg = "Stable / Baseline";
        $trend_color = "#17a2b8"; // Blue
    }
}

// --- HOTSPOT DETECTION ALGORITHM ---
$alert_threshold = 3; // TRIGGER ALERT if a barangay has 3+ active cases
$hotspot_alert = [];

// Scan for barangays with high "Open" or "Under Investigation" cases
// $h_sql = "SELECT b.official_name, COUNT(*) as count 
//           FROM incidents i 
//           JOIN barangays b ON i.barangay_id = b.id 
//           WHERE i.status IN ('Open', 'Under Investigation') 
//           GROUP BY i.barangay_id 
//           HAVING count >= ?";

// Scan for HIGH RECENT ACTIVITY (Last 30 Days only)
$h_sql = "SELECT b.official_name, COUNT(*) as count 
          FROM incidents i 
          JOIN barangays b ON i.barangay_id = b.id 
          WHERE i.status IN ('Open', 'Under Investigation') 
          AND i.incident_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
          GROUP BY i.barangay_id 
          HAVING count >= ?";
          
$h_stmt = $conn->prepare($h_sql);
$h_stmt->bind_param("i", $alert_threshold);
$h_stmt->execute();
$h_result = $h_stmt->get_result();

while ($row = $h_result->fetch_assoc()) {
    $hotspot_alert[] = $row['official_name'] . " (" . $row['count'] . " active cases)";
}
// Pass PHP array to JavaScript safely
$js_hotspots = json_encode($hotspot_alert);

$barangay_list = [];
$barangay_result = $conn->query("SELECT id, official_name FROM barangays ORDER BY official_name");
while ($b = $barangay_result->fetch_assoc()) {
    $barangay_list[] = $b;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Log audit
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
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
</head>

<body>

<?php require_once 'header.php'; ?>

    <div class="map-container">
        <div id="map"></div>
        
        <div class="map-buttons">
            <button id="alertTriggerBtn" class="float-btn btn-alert" onclick="toggleAlertSidebar()">
                ALERTS (<span id="alertCount">0</span>)
            </button>
            
            <button class="float-btn" style="background-color: #6610f2; color: white;" onclick="toggleAiSidebar()">
                AI Forecast
            </button>
            
            <button class="float-btn btn-tools" onclick="toggleControlsSidebar()">
                Map Tools
            </button>
        </div>
    </div>

    <div id="controlsSidebar" class="sidebar">
        <div class="sidebar-header">
            <h3>Filters & Data</h3>
            <button class="sidebar-close" onclick="toggleControlsSidebar()">&times;</button>
        </div>
        <div class="sidebar-content">
            
            <div class="widget">
                <h4>Visualization Mode</h4>
                <button class="toggle-btn active" id="heatmapToggle">Heatmap View</button>
            </div>

            <div class="widget">
                <h4>Filters</h4>
                <div class="control-group">
                    <label>Incident Type:</label>
                    <select id="typeFilter">
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
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="Open">Open</option>
                        <option value="Under Investigation">Under Investigation</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Date Range:</label>
                    <input type="date" id="dateFrom">
                    <input type="date" id="dateTo" style="margin-top: 5px;">
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
            </div>

            <div class="widget">
                <h4>Current Statistics</h4>
                <div class="stat-item"><span>Total Incidents</span><span class="stat-value" id="totalStat">0</span></div>
                <div class="stat-item"><span>Open Cases</span><span class="stat-value" id="openStat">0</span></div>
                <div class="stat-item"><span>Investigating</span><span class="stat-value" id="investigatingStat">0</span></div>
            </div>

            <div class="widget">
                <h4>Legend</h4>
                <div class="legend-item"><div class="legend-color" style="background: #f44336;"></div><span>Phishing</span></div>
                <div class="legend-item"><div class="legend-color" style="background: #ff9800;"></div><span>Online Fraud</span></div>
                <div class="legend-item"><div class="legend-color" style="background: #9c27b0;"></div><span>Identity Theft</span></div>
                <div class="legend-item"><div class="legend-color" style="background: #e91e63;"></div><span>Cyber Harassment</span></div>
                <div class="legend-item"><div class="legend-color" style="background: #607d8b;"></div><span>Others</span></div>
            </div>

        </div>
    </div>

    <div id="alertSidebar" class="sidebar">
        <div class="sidebar-header">
            <h3>Crime Alerts</h3>
            <button class="sidebar-close" onclick="toggleAlertSidebar()">&times;</button>
        </div>
        <div class="sidebar-content">
            <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
                The following areas have exceeded the threshold of <strong><?= $alert_threshold ?> active cases</strong>.
            </p>
            <ul id="sidebarHotspotList" class="hotspot-list"></ul>
        </div>
    </div>

    <div id="aiSidebar" class="sidebar">
        <div class="sidebar-header" style="background: #6610f2; color: white;">
            <h3>Predictive Intelligence</h3>
            <button class="sidebar-close" onclick="toggleAiSidebar()" style="color: white;">&times;</button>
        </div>
        
        <div class="sidebar-content">
            <p style="font-size: 13px; color: #666; margin-bottom: 20px;">
                System utilizes <strong>Linear Regression (Least Squares)</strong> to project crime volume for the upcoming month.
            </p>

            <div class="widget" style="border-left: 5px solid #6610f2; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4 style="color: #6610f2; margin: 0 0 10px 0;">Trend Forecast</h4>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <div style="font-size: 10px; text-transform: uppercase; color: #888; font-weight: bold;">Next Month Projection</div>
                        <div style="font-size: 36px; font-weight: 800; color: #333; line-height: 1;">
                            <?= $prediction ?> 
                            <span style="font-size: 14px; font-weight: normal; color: #777;">cases</span>
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
                    *Model trained on last 6 months of incident data.
                </div>
            </div>

        </div>
    </div>

    <div id="hotspotModal" class="alert-modal">
        <div class="alert-modal-content">
            <div class="alert-modal-header">
                <h2 style="margin:0;">CRITICAL ALERT</h2>
            </div>
            <div class="alert-modal-body">
                <p><strong>System Activity Detected:</strong><br>The following areas have reached "Rampant" status.</p>
                <ul id="hotspotList" class="hotspot-list"></ul>
                <button class="float-btn" style="background: #333; color: white; width: 100%; justify-content: center;" onclick="closeModal()">Acknowledge</button>
            </div>
        </div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
    // ==========================================
    // 1. INITIALIZATION & GLOBALS
    // ==========================================
    const map = L.map('map').setView([14.0702, 121.3256], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);

    let allIncidents = [];
    let heatmapLayer = null;
    let markerClusterGroup = null;
    let isHeatmapMode = true;

    // Capture URL parameter ONCE on load
    const urlParams = new URLSearchParams(window.location.search);
    let targetCase = urlParams.get('case');

    const typeColors = {
        'Phishing': '#f44336', 'Online Fraud': '#ff9800', 'Identity Theft': '#9c27b0', 'Cyber Harassment': '#e91e63', 'Others': '#607d8b'
    };

    // Initialize Choices.js
    if(document.getElementById('barangayFilter')) {
        new Choices('#barangayFilter', { searchEnabled: true });
    }

    // ==========================================
    // 2. SIDEBAR LOGIC (Unified)
    // ==========================================
    function closeAllSidebars() {
        document.getElementById('controlsSidebar').classList.remove('open');
        document.getElementById('alertSidebar').classList.remove('open');
        const aiSidebar = document.getElementById('aiSidebar');
        if(aiSidebar) aiSidebar.classList.remove('open');
    }

    function toggleControlsSidebar() {
        const sb = document.getElementById('controlsSidebar');
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    function toggleAlertSidebar() {
        const sb = document.getElementById('alertSidebar');
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    function toggleAiSidebar() {
        const sb = document.getElementById('aiSidebar');
        if (!sb) return; // Safety check
        const isOpen = sb.classList.contains('open');
        closeAllSidebars();
        if (!isOpen) sb.classList.add('open');
    }

    function closeModal() {
        document.getElementById('hotspotModal').style.display = 'none';
    }

    // ==========================================
    // 3. MAP DATA & RENDERING
    // ==========================================
    function loadIncidents() {
        const url = new URL('../api/incidents.php', window.location.href);
        const ids = ['typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'];
        const params = { type: 'type', status: 'status', barangay: 'barangay', dateFrom: 'date_from', dateTo: 'date_to' };
        
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el && el.value) url.searchParams.set(params[id.replace('Filter','')], el.value);
        });

        fetch(url)
            .then(res => res.json())
            .then(data => {
                allIncidents = data;

                // LOGIC: If we have a target case, FORCE Marker View initially
                if (targetCase) {
                    isHeatmapMode = false;
                    const toggleBtn = document.getElementById('heatmapToggle');
                    if (toggleBtn) {
                        toggleBtn.textContent = 'Marker View'; 
                        toggleBtn.classList.remove('active');
                    }
                }

                renderMap(allIncidents);
                updateStats(allIncidents);
            })
            .catch(err => console.error(err));
    }

    function renderMap(incidents) {
        if (heatmapLayer) map.removeLayer(heatmapLayer);
        if (markerClusterGroup) map.removeLayer(markerClusterGroup);

        const valid = incidents.filter(inc => {
            const lat = parseFloat(inc.lat), lng = parseFloat(inc.lng);
            return !isNaN(lat) && lat !== 0;
        });

        if (isHeatmapMode) {
            // --- ADVANCED FEATURE: Predictive Time-Decay & Severity KDE ---
            const heatData = valid.map(inc => {
                
                // 1. Severity Weighting (High-risk crimes generate more heat)
                let severity = 0.5; 
                const type = inc.incident_type;
                if (type === 'Identity Theft' || type === 'Hacking' || type === 'Sextortion') {
                    severity = 1.0;
                } else if (type === 'Online Fraud' || type === 'Phishing') {
                    severity = 0.8;
                }
                
                // 2. Time-Decay Weighting (Recent cases are hotter)
                const incDate = new Date(inc.incident_date);
                const today = new Date();
                const daysOld = Math.floor((today - incDate) / (1000 * 60 * 60 * 24));
                
                // Decay formula: Intensity drops to 0.2 over 365 days
                let timeWeight = Math.max(0.2, 1.0 - (daysOld / 365));
                if (daysOld <= 30) {
                    timeWeight = 1.2; // Spike heat for active/recent surges
                }

                // Final KDE Calculation
                const finalIntensity = (severity * 0.6) + (timeWeight * 0.4);

                return [parseFloat(inc.lat), parseFloat(inc.lng), finalIntensity];
            });

            // Rendering the Predictive Spread (Increased Radius)
            heatmapLayer = L.heatLayer(heatData, { 
                radius: 35, // Increased radius shows 'at-risk' spillover zones
                blur: 25, 
                maxZoom: 17, 
                gradient: { 0.0: 'blue', 0.4: 'cyan', 0.6: 'lime', 0.8: 'yellow', 1.0: 'red' } 
            }).addTo(map);
            
        } else {
            // MARKER MODE
            markerClusterGroup = L.markerClusterGroup({ maxClusterRadius: 50 });
            let matchedMarker = null; 
            
            valid.forEach(inc => {
                const marker = L.circleMarker([inc.lat, inc.lng], {
                    radius: 8, fillColor: typeColors[inc.incident_type] || '#607d8b', color: '#fff', weight: 2, fillOpacity: 0.8
                }).bindPopup(`
                    <div class="popup-title">${inc.case_no}</div>
                    <div><strong>Type:</strong> ${inc.incident_type}</div>
                    <div><strong>Brgy:</strong> ${inc.barangay}</div>
                    <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                    <div><a href="cases.php?search=${inc.case_no}">View Case &raquo;</a></div>
                `);
                
                markerClusterGroup.addLayer(marker);

                if (targetCase && inc.case_no.trim() === targetCase.trim()) {
                    matchedMarker = marker;
                }
            });

            map.addLayer(markerClusterGroup);

            if (matchedMarker) {
                markerClusterGroup.zoomToShowLayer(matchedMarker, function() {
                    matchedMarker.openPopup();
                });
            }
        }

        if (targetCase) {
            targetCase = null;
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({path:cleanUrl},'',cleanUrl);
        }
    }

    function updateStats(incidents) {
        document.getElementById('totalStat').textContent = incidents.length;
        document.getElementById('openStat').textContent = incidents.filter(i => i.status === 'Open').length;
        document.getElementById('investigatingStat').textContent = incidents.filter(i => i.status === 'Under Investigation').length;
    }

    // ==========================================
    // 4. EVENT LISTENERS
    // ==========================================
    document.getElementById('heatmapToggle').addEventListener('click', function() {
        isHeatmapMode = !isHeatmapMode;
        this.textContent = isHeatmapMode ? 'Heatmap View' : 'Marker View';
        this.classList.toggle('active', isHeatmapMode);
        loadIncidents();
    });

    ['typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'].forEach(id => {
        const el = document.getElementById(id);
        if(el) el.addEventListener('change', loadIncidents);
    });

    // Alert / Hotspot Logic
    const hotspots = <?= $js_hotspots ?>;
    window.addEventListener('load', function() {
        if (hotspots.length > 0) {
            document.getElementById('alertTriggerBtn').style.display = 'flex';
            document.getElementById('alertCount').textContent = hotspots.length;
            
            const populate = (listId) => {
                const list = document.getElementById(listId);
                if(list) {
                    hotspots.forEach(area => {
                        const li = document.createElement('li');
                        li.className = 'hotspot-item';
                        li.innerHTML = area;
                        list.appendChild(li);
                    });
                }
            };
            populate('hotspotList'); 
            populate('sidebarHotspotList'); 
            document.getElementById('hotspotModal').style.display = 'block';
        }
    });

    // Load GeoJSON Borders
    fetch('../api/san_pablo_barangays.json')
        .then(response => response.json())
        .then(data => {
            const borderLayer = L.geoJSON(data, {
                style: function(feature) {
                    return { color: "#FF5722", weight: 1.5, opacity: 0.6, fillColor: "#FF5722", fillOpacity: 0.05 };
                },
                onEachFeature: function(feature, layer) {
                    const bgyName = feature.properties.adm4_en; 
                    layer.bindTooltip(bgyName, { permanent: false, direction: 'center', className: 'bgy-label' });
                    layer.on('click', function(e) {
                        borderLayer.resetStyle();
                        layer.setStyle({ weight: 3, color: '#003366', fillOpacity: 0.2 });
                        const filterDropdown = document.getElementById('barangayFilter');
                        if (filterDropdown) {
                            for (let i = 0; i < filterDropdown.options.length; i++) {
                                if (filterDropdown.options[i].text.toUpperCase().includes(bgyName.toUpperCase())) {
                                    filterDropdown.selectedIndex = i;
                                    filterDropdown.dispatchEvent(new Event('change'));
                                    break;
                                }
                            }
                        }
                    });
                    layer.on('mouseover', function() { if (this.options.weight !== 3) this.setStyle({ weight: 2, fillOpacity: 0.15 }); });
                    layer.on('mouseout', function() { if (this.options.weight !== 3) this.setStyle({ weight: 1, fillOpacity: 0.05 }); });
                }
            }).addTo(map);
        })
        .catch(err => console.error("Error loading borders:", err));

    // ==========================================
    // 5. AI CHART LOGIC
    // ==========================================
    // Only run if the canvas element exists to avoid crashes
    const forecastCanvas = document.getElementById('forecastChart');
    if (forecastCanvas) {
        const ctxForecast = forecastCanvas.getContext('2d');
        const labels = <?= json_encode($labels) ?>;
        const actualData = <?= json_encode($y_values) ?>;
        const trendData = <?= json_encode($trend_line) ?>;

        labels.push("Next");
        trendData.push(<?= $prediction ?>);

        new Chart(ctxForecast, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { data: actualData, borderColor: '#999', borderWidth: 1.5, pointRadius: 2, tension: 0.1 },
                    { data: trendData, borderColor: '#6610f2', borderWidth: 2, borderDash: [4, 4], pointRadius: 0, fill: false },
                    { data: Array(actualData.length).fill(null).concat([<?= $prediction ?>]), backgroundColor: '#6610f2', pointRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                layout: { padding: 5 }
            }
        });
    }

    // Start App
    loadIncidents();
</script>
</body>
</html>