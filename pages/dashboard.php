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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; }
        
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
        }
        
        #map {
            height: 100%;
            width: 100%;
        }
        
        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .control-panel {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 280px;
        }
        
        .control-panel h3 {
            color: #003366;
            margin-bottom: 12px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .control-group {
            margin-bottom: 12px;
        }
        
        .control-group label {
            display: block;
            color: #555;
            font-size: 13px;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .control-group select,
        .control-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .control-group select:focus,
        .control-group input:focus {
            outline: none;
            border-color: #003366;
        }
        
        .toggle-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .toggle-btn.active {
            background: #003366;
            color: white;
        }
        
        .toggle-btn:not(.active) {
            background: #e0e0e0;
            color: #666;
        }
        
        .toggle-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stats-panel {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .stat-item:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            color: #666;
            font-size: 13px;
        }
        
        .stat-value {
            font-weight: 600;
            color: #003366;
            font-size: 16px;
        }
        
        .legend {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .legend-label {
            font-size: 13px;
            color: #555;
        }
        
        .leaflet-popup-content {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .popup-title {
            font-weight: 600;
            color: #003366;
            margin-bottom: 8px;
            font-size: 15px;
        }
        
        .popup-detail {
            margin: 5px 0;
            font-size: 13px;
            color: #555;
        }
        
        .popup-detail strong {
            color: #333;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>CYBERPABLO</h1>
        <div class="header-right">
            <div class="nav-links">
                <a href="cases.php">Cases</a>
                <?php if ($role === 'admin'): ?>
                    <a href="import_cases.php">Import</a>
                <?php endif; ?>
            </div>
            <div>
                <span>👤 <strong><?= htmlspecialchars($username) ?></strong> (<?= $role ?>)</span>
            </div>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>

    <div class="map-container">
        <div id="map"></div>
        
        <div class="map-controls">
            <!-- Visualization Controls -->
            <div class="control-panel">
                <h3>Visualization</h3>
                <button class="toggle-btn active" id="heatmapToggle">
                    Heatmap Mode
                </button>
            </div>
            
            <!-- Filters -->
            <div class="control-panel">
                <h3>Filters</h3>
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
            </div>
            
            <!-- Stats -->
            <div class="stats-panel">
                <h3 style="color: #003366; margin-bottom: 10px;">Statistics</h3>
                <div class="stat-item">
                    <span class="stat-label">Total Incidents</span>
                    <span class="stat-value" id="totalStat">0</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Open Cases</span>
                    <span class="stat-value" id="openStat">0</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Investigating</span>
                    <span class="stat-value" id="investigatingStat">0</span>
                </div>
            </div>
            
            <!-- Legend -->
            <div class="legend">
                <h3 style="color: #003366; margin-bottom: 10px;">🗺️ Legend</h3>
                <div class="legend-item">
                    <div class="legend-color" style="background: #f44336;"></div>
                    <span class="legend-label">Phishing</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #ff9800;"></div>
                    <span class="legend-label">Online Fraud</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #9c27b0;"></div>
                    <span class="legend-label">Identity Theft</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #e91e63;"></div>
                    <span class="legend-label">Cyber Harassment</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #607d8b;"></div>
                    <span class="legend-label">Others</span>
                </div>
            </div>
        </div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
    const map = L.map('map').setView([14.0702, 121.3256], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    let allIncidents = [];
    let heatmapLayer = null;
    let markerClusterGroup = null;
    let isHeatmapMode = true;

    const typeColors = {
        'Phishing': '#f44336',
        'Online Fraud': '#ff9800',
        'Identity Theft': '#9c27b0',
        'Cyber Harassment': '#e91e63',
        'Others': '#607d8b'
    };



    // Load incidents with optional filters
    function loadIncidents() {
        const url = new URL('../api/incidents.php', window.location.href);
        
        const type = document.getElementById('typeFilter').value;
        const status = document.getElementById('statusFilter').value;

        if (type) url.searchParams.set('type', type);
        if (status) url.searchParams.set('status', status);

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then(data => {
                allIncidents = data;
                filterIncidents(); // Apply date filters
            })
            .catch(err => {
                console.error('API Error:', err);
                alert('Failed to load data. Check API path.');
            });
    }

    // Initial load
    loadIncidents();

    function renderMap(incidents) {
    // Clear existing layers
    if (heatmapLayer) map.removeLayer(heatmapLayer);
    if (markerClusterGroup) map.removeLayer(markerClusterGroup);

    // Filter valid incidents
    const validIncidents = incidents.filter(inc => {
        const lat = parseFloat(inc.lat);
        const lng = parseFloat(inc.lng);
        return !isNaN(lat) && !isNaN(lng) && 
               lat !== 0 && lng !== 0 && 
               lat >= -90 && lat <= 90 && 
               lng >= -180 && lng <= 180;
    });

    if (validIncidents.length === 0) {
        // Optional: Show message
        alert('No incidents with valid GPS coordinates.');
        return;
    }

    if (isHeatmapMode) {
        const heatData = validIncidents.map(inc => [inc.lat, inc.lng, 0.8]);
        heatmapLayer = L.heatLayer(heatData, {
            radius: 25,
            blur: 15,
            maxZoom: 17,
            gradient: { 0.0: 'blue', 0.5: 'lime', 0.7: 'yellow', 1.0: 'red' }
        }).addTo(map);
    } else {
        markerClusterGroup = L.markerClusterGroup({
            maxClusterRadius: 50,
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false
        });

        validIncidents.forEach(inc => {
            const marker = L.circleMarker([inc.lat, inc.lng], {
                radius: 8,
                fillColor: typeColors[inc.incident_type] || '#607d8b',
                color: '#fff',
                weight: 2,
                fillOpacity: 0.8
            }).bindPopup(`
                <div class="popup-title">${inc.case_no}</div>
                <div class="popup-detail"><strong>Type:</strong> ${inc.incident_type}</div>
                <div class="popup-detail"><strong>Barangay:</strong> ${inc.barangay}</div>
                <div class="popup-detail"><a href="cases.php?search=${inc.case_no}" style="color: #003366;">View Details</a></div>
            `);
            markerClusterGroup.addLayer(marker);
        });

        map.addLayer(markerClusterGroup);
    }
}

    function updateStats(incidents) {
        document.getElementById('totalStat').textContent = incidents.length;
        document.getElementById('openStat').textContent = 
            incidents.filter(i => i.status === 'Open').length;
        document.getElementById('investigatingStat').textContent = 
            incidents.filter(i => i.status === 'Under Investigation').length;
    }

    function filterIncidents() {
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;

        let filtered = allIncidents;

        if (dateFrom) filtered = filtered.filter(i => i.incident_date >= dateFrom);
        if (dateTo) filtered = filtered.filter(i => i.incident_date <= dateTo);

        renderMap(filtered);
        updateStats(filtered);
    }

    // Heatmap toggle
    document.getElementById('heatmapToggle').addEventListener('click', function() {
        isHeatmapMode = !isHeatmapMode;
        this.textContent = isHeatmapMode ? 'Heatmap Mode' : 'Marker Mode';
        this.classList.toggle('active', isHeatmapMode);
        filterIncidents(); // Re-render with current filters
    });

    // Filters — type & status reload from server, date filters client-side
    document.getElementById('typeFilter').addEventListener('change', () => {
        loadIncidents();
    });
    document.getElementById('statusFilter').addEventListener('change', () => {
        loadIncidents();
    });
    document.getElementById('dateFrom').addEventListener('change', filterIncidents);
    document.getElementById('dateTo').addEventListener('change', filterIncidents);

    // Check for URL parameters (when coming from cases.php)
    const urlParams = new URLSearchParams(window.location.search);
    const targetLat = urlParams.get('lat');
    const targetLng = urlParams.get('lng');
    const targetCase = urlParams.get('case');

    if (targetLat && targetLng) {
        setTimeout(() => {
            map.setView([parseFloat(targetLat), parseFloat(targetLng)], 17);
            
            if (!isHeatmapMode && markerClusterGroup) {
                markerClusterGroup.eachLayer(layer => {
                    const latlng = layer.getLatLng();
                    if (Math.abs(latlng.lat - parseFloat(targetLat)) < 0.0001 &&
                        Math.abs(latlng.lng - parseFloat(targetLng)) < 0.0001) {
                        layer.openPopup();
                    }
                });
            }
        }, 500);
    }
</script>
</body>
</html>