<?php
session_start();
require_once 'config/connection.php';

// --- HOTSPOT DETECTION ALGORITHM ---
$alert_threshold = 3; // TRIGGER ALERT if a barangay has 3+ active cases
$hotspot_alert = [];

// Scan for barangays with high "Open" or "Under Investigation" cases
$h_sql = "SELECT b.official_name, COUNT(*) as count 
          FROM incidents i 
          JOIN barangays b ON i.barangay_id = b.id 
          WHERE i.status IN ('Open', 'Under Investigation') 
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; }
        
        /* Remove the ugly square focus box on clicked map shapes */
        path.leaflet-interactive:focus {
            outline: none;
        }

        /* Optional: Ensure the cursor looks like a pointer when hovering over barangays */
        path.leaflet-interactive {
            cursor: pointer;
            transition: fill-opacity 0.2s, stroke-width 0.2s; /* Smooth animation */
        }


        .header {
            background: linear-gradient(135deg, #003366 0%, #004d99 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1000;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .nav-links a {
            color: #ffcc00;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
            margin: 0 10px;
        }
        
        .nav-links a:hover { color: #ffd700; }
        
        .logout {
            background: #d32f2f;
            color: white !important;
            padding: 8px 20px;
            border-radius: 6px;
            transition: all 0.3s;
        }
        
        .logout:hover {
            background: #b71c1c;
            transform: translateY(-2px);
        }
        
        .map-container {
            position: relative;
            height: calc(100vh - 70px);
            overflow: hidden; /* Prevent horizontal scroll when sidebar opens */
        }
        
        #map {
            height: 100%;
            width: 100%;
        }
        
        /* --- FLOATING CONTROLS (BUTTONS ONLY) --- */
        .map-buttons {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
        }
        
        .float-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 50px; /* Pill shape */
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .float-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }

        /* Tools Button (Blue) */
        .btn-tools {
            background: white;
            color: #003366;
            border: 2px solid #003366;
        }
        .btn-tools:hover { background: #f0f8ff; }

        /* Alert Button (Red Pulse) */
        .btn-alert {
            background: #d32f2f;
            color: white;
            display: none; /* Hidden by default */
            animation: alertPulse 2s infinite;
        }
        @keyframes alertPulse {
            0% { box-shadow: 0 0 0 0 rgba(211, 47, 47, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(211, 47, 47, 0); }
            100% { box-shadow: 0 0 0 0 rgba(211, 47, 47, 0); }
        }

        /* --- SHARED SIDEBAR STYLES --- */
        .sidebar {
            position: fixed;
            top: 0;
            right: -360px; /* Hidden off-screen */
            width: 340px;
            height: 100%;
            background: white;
            z-index: 2000;
            box-shadow: -4px 0 15px rgba(0,0,0,0.2);
            transition: right 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }
        .sidebar.open { right: 0; }

        .sidebar-header {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
        }
        .sidebar-header h3 { margin: 0; font-size: 18px; }
        .sidebar-close {
            background: none; border: none; font-size: 24px; cursor: pointer; color: white;
        }
        .sidebar-content {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            background: #f9f9f9;
        }

        /* Red Sidebar (Alerts) */
        #alertSidebar { z-index: 2002; }
        #alertSidebar .sidebar-header { background: #d32f2f; color: white; }

        /* Blue Sidebar (Controls) */
        #controlsSidebar { z-index: 2001; }
        #controlsSidebar .sidebar-header { background: #003366; color: white; }

        /* --- CONTROL WIDGETS (Inside Sidebar) --- */
        .widget {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        .widget h4 { margin: 0 0 12px 0; color: #003366; font-size: 15px; border-bottom: 2px solid #f0f0f0; padding-bottom: 8px; }

        .control-group { margin-bottom: 12px; }
        .control-group label { display: block; color: #555; font-size: 13px; margin-bottom: 5px; font-weight: 500; }
        .control-group select, .control-group input {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;
        }

        .toggle-btn {
            padding: 10px; border: none; border-radius: 6px; width: 100%; cursor: pointer; font-weight: 600;
        }
        .toggle-btn.active { background: #003366; color: white; }
        .toggle-btn:not(.active) { background: #e0e0e0; color: #666; }

        /* Stats & Legend Items */
        .stat-item { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
        .stat-value { font-weight: bold; color: #003366; }
        
        .legend-item { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
        .legend-color { width: 18px; height: 18px; border-radius: 4px; }

        /* --- MODAL --- */
        .alert-modal {
            display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; 
            background-color: rgba(0,0,0,0.6); backdrop-filter: blur(2px);
        }
        .alert-modal-content {
            background-color: #fff; margin: 10% auto; border: 1px solid #d32f2f; width: 90%; max-width: 500px;
            border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); animation: slideDown 0.4s ease-out;
        }
        .alert-modal-header { background: #d32f2f; color: white; padding: 15px 20px; border-radius: 10px 10px 0 0; }
        .alert-modal-body { padding: 25px 20px; text-align: center; }
        
        .hotspot-list { list-style: none; padding: 0; margin: 15px 0; }
        .hotspot-item { 
            background: #ffebee; color: #c62828; padding: 10px; margin-bottom: 5px; 
            border-radius: 4px; font-weight: bold; text-align: left; border-left: 4px solid #c62828; 
        }

        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* Leaflet Popup Fixes */
        .popup-title { font-weight: bold; color: #003366; margin-bottom: 5px; }
    </style>
</head>

<body>

<?php require_once 'header.php'; ?>

    <div class="map-container">
        <div id="map"></div>
        
        <div class="map-buttons">
            <button id="alertTriggerBtn" class="float-btn btn-alert" onclick="toggleAlertSidebar()">
                ⚠️ ALERTS (<span id="alertCount">0</span>)
            </button>
            
            <button class="float-btn btn-tools" onclick="toggleControlsSidebar()">
                🛠️ Map Tools
            </button>
        </div>
    </div>

    <div id="controlsSidebar" class="sidebar">
        <div class="sidebar-header">
            <h3>🛠️ Filters & Data</h3>
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
            <h3>⚠️ Rampant Areas</h3>
            <button class="sidebar-close" onclick="toggleAlertSidebar()">&times;</button>
        </div>
        <div class="sidebar-content">
            <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
                The following areas have exceeded the threshold of <strong><?= $alert_threshold ?> active cases</strong>.
            </p>
            <ul id="sidebarHotspotList" class="hotspot-list"></ul>
        </div>
    </div>

    <div id="hotspotModal" class="alert-modal">
        <div class="alert-modal-content">
            <div class="alert-modal-header">
                <h2 style="margin:0;">⚠️ CRITICAL ALERT</h2>
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
    const map = L.map('map').setView([14.0702, 121.3256], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);

    // Sidebar Toggles
    function toggleControlsSidebar() {
        document.getElementById('controlsSidebar').classList.toggle('open');
        // Close alert sidebar if open to avoid overlap
        document.getElementById('alertSidebar').classList.remove('open');
    }

    function toggleAlertSidebar() {
        document.getElementById('alertSidebar').classList.toggle('open');
        // Close controls sidebar if open
        document.getElementById('controlsSidebar').classList.remove('open');
    }

    function closeModal() {
        document.getElementById('hotspotModal').style.display = 'none';
    }

    // Initialize Choices.js
    new Choices('#barangayFilter', { searchEnabled: true });

    let allIncidents = [];
    let heatmapLayer = null;
    let markerClusterGroup = null;
    let isHeatmapMode = true;

    const typeColors = {
        'Phishing': '#f44336', 'Online Fraud': '#ff9800', 'Identity Theft': '#9c27b0', 'Cyber Harassment': '#e91e63', 'Others': '#607d8b'
    };

    function loadIncidents() {
        const url = new URL('../api/incidents.php', window.location.href);
        const ids = ['typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'];
        const params = { type: 'type', status: 'status', barangay: 'barangay', dateFrom: 'date_from', dateTo: 'date_to' };
        
        ids.forEach(id => {
            const val = document.getElementById(id).value;
            if (val) url.searchParams.set(params[id.replace('Filter','')], val);
        });

        fetch(url)
            .then(res => res.json())
            .then(data => {
                allIncidents = data;
                renderMap(allIncidents);
                updateStats(allIncidents);
            })
            .catch(err => console.error(err));
    }

    loadIncidents();

    function renderMap(incidents) {
        if (heatmapLayer) map.removeLayer(heatmapLayer);
        if (markerClusterGroup) map.removeLayer(markerClusterGroup);

        const valid = incidents.filter(inc => {
            const lat = parseFloat(inc.lat), lng = parseFloat(inc.lng);
            return !isNaN(lat) && lat !== 0;
        });

        if (isHeatmapMode) {
            const heatData = valid.map(inc => [inc.lat, inc.lng, 0.8]);
            heatmapLayer = L.heatLayer(heatData, { radius: 25, blur: 15, maxZoom: 17, gradient: { 0.0: 'blue', 0.5: 'lime', 0.7: 'yellow', 1.0: 'red' } }).addTo(map);
        } else {
            markerClusterGroup = L.markerClusterGroup({ maxClusterRadius: 50 });
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
            });
            map.addLayer(markerClusterGroup);
        }
    }

    function updateStats(incidents) {
        document.getElementById('totalStat').textContent = incidents.length;
        document.getElementById('openStat').textContent = incidents.filter(i => i.status === 'Open').length;
        document.getElementById('investigatingStat').textContent = incidents.filter(i => i.status === 'Under Investigation').length;
    }

    // Event Listeners
    document.getElementById('heatmapToggle').addEventListener('click', function() {
        isHeatmapMode = !isHeatmapMode;
        this.textContent = isHeatmapMode ? 'Heatmap View' : 'Marker View';
        this.classList.toggle('active', isHeatmapMode);
        loadIncidents();
    });

    ['typeFilter', 'statusFilter', 'barangayFilter', 'dateFrom', 'dateTo'].forEach(id => {
        document.getElementById(id).addEventListener('change', loadIncidents);
    });

    // Alert Logic
    const hotspots = <?= $js_hotspots ?>;
    window.addEventListener('load', function() {
        if (hotspots.length > 0) {
            document.getElementById('alertTriggerBtn').style.display = 'flex';
            document.getElementById('alertCount').textContent = hotspots.length;
            
            const populate = (listId) => {
                const list = document.getElementById(listId);
                hotspots.forEach(area => {
                    const li = document.createElement('li');
                    li.className = 'hotspot-item';
                    li.innerHTML = '🔥 ' + area;
                    list.appendChild(li);
                });
            };
            
            populate('hotspotList'); // Modal list
            populate('sidebarHotspotList'); // Sidebar list
            document.getElementById('hotspotModal').style.display = 'block';
        }
    });

    // --- NEW: Load Barangay Borders (Offline Reverse Geocoding) ---
    fetch('../api/san_pablo_barangays.json')
        .then(response => response.json())
        .then(data => {
            // Add the GeoJSON layer to the map
            const borderLayer = L.geoJSON(data, {
                style: function(feature) {
                    return {
                        color: "#FF5722",       // Orange borders
                        weight: 1.5,              // Thin lines
                        opacity: 0.6,
                        fillColor: "#FF5722",   // Slight fill
                        fillOpacity: 0.05       // Very transparent fill
                    };
                },
                onEachFeature: function(feature, layer) {
                    // Get Barangay Name from the JSON properties
                    // Note: Your file uses 'adm4_en' for the name
                    const bgyName = feature.properties.adm4_en; 

                    // 1. Show Name on Hover (Tooltip)
                    layer.bindTooltip(bgyName, {
                        permanent: false,
                        direction: 'center',
                        className: 'bgy-label' // We can style this in CSS
                    });

                    // 2. Click to Filter (The "Reverse Geocoding" Interaction)
                    layer.on('click', function(e) {
                        // Highlight the clicked barangay
                        borderLayer.resetStyle(); // Reset others
                        layer.setStyle({
                            weight: 3,
                            color: '#003366',
                            fillOpacity: 0.2
                        });

                        // Alert user (or auto-fill a form)
                        // alert("You selected: " + bgyName); 
                        
                        // AUTO-FILTER: If you want clicking the map to filter the dashboard!
                        const filterDropdown = document.getElementById('barangayFilter');
                        if (filterDropdown) {
                            // Try to match the dropdown value
                            // (You might need to ensure dropdown names match 'adm4_en' exactly)
                            // loop options to find match...
                            for (let i = 0; i < filterDropdown.options.length; i++) {
                                if (filterDropdown.options[i].text.toUpperCase().includes(bgyName.toUpperCase())) {
                                    filterDropdown.selectedIndex = i;
                                    filterDropdown.dispatchEvent(new Event('change')); // Trigger reload
                                    break;
                                }
                            }
                        }
                    });
                    
                    // Highlight on Hover
                    layer.on('mouseover', function() {
                        if (this.options.weight !== 3) { // Don't override click style
                            this.setStyle({ weight: 2, fillOpacity: 0.15 });
                        }
                    });
                    layer.on('mouseout', function() {
                        if (this.options.weight !== 3) {
                            this.setStyle({ weight: 1, fillOpacity: 0.05 });
                        }
                    });
                }
            }).addTo(map);
            
            // Optional: Fit map to San Pablo borders
            map.fitBounds(borderLayer.getBounds());
        })
        .catch(err => console.error("Error loading borders:", err));
</script>
</body>
</html>