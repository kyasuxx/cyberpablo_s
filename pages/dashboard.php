<?php
session_start();
require_once 'config/connection.php';

// BLOCK UNAUTHORIZED
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// GET USER
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// LOG AUDIT
$ip = $_SERVER['REMOTE_ADDR'];
$stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'login_dashboard', ?)");
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
    <link rel="stylesheet" href="../assets/scss/main.css">
    <style>
        body { margin: 0; font-family: Arial; background: #f4f6f9; }
        
    </style>
</head>

<body>
    <div class="header">
        <button class="sidebar-toggle" onclick="toggleSidebar()">☰ Menu</button>
        <h1>CYBERPABLO</h1>
        <div>
            <?php if (isset($_GET['lat'])): ?>
                <a href="cases.php" style="color:#ffcc00; margin-right:15px;">← Back to Cases</a>
            <?php endif; ?>
            <span>Welcome, <strong><?= htmlspecialchars($username) ?></strong> (<?= $role ?>)</span> |
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="sidebar" id="sidebar">
           <a href="cases.php">Cases</a>
        </div>
        <div id="map" class="map"></div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Initialize map
    const map = L.map('map').setView([14.0702, 121.3256], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    // Check if there are coordinates in URL
    const urlParams = new URLSearchParams(window.location.search);
    const targetLat = urlParams.get('lat');
    const targetLng = urlParams.get('lng');
    const targetCase = urlParams.get('case');
    
    let highlightMarker = null;

    fetch('../api/incidents.php')
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            console.log('Loaded incidents:', data);

            data.forEach(inc => {
                const marker = L.circleMarker([inc.lat, inc.lng], {
                    radius: 7,
                    color: '#d32f2f',
                    fillColor: '#f44336',
                    fillOpacity: 0.8
                }).bindPopup(`
                    <b>${inc.case_no}</b><br>
                    <b>Type:</b> ${inc.incident_type}<br>
                    <b>Barangay:</b> ${inc.barangay}
                `).addTo(map);

                // If this is the target case, highlight it
                if (targetCase && inc.case_no === targetCase) {
                    // Change the marker style to highlight
                    marker.setStyle({
                        radius: 12,
                        color: '#ffcc00',
                        fillColor: '#ffd54f',
                        fillOpacity: 1,
                        weight: 3
                    });
                    highlightMarker = marker;
                }
            });

            // If coordinates were passed, zoom to that location
            if (targetLat && targetLng) {
                const lat = parseFloat(targetLat);
                const lng = parseFloat(targetLng);
                
                // Zoom to the location
                map.setView([lat, lng], 17);
                
                // Open popup if we found the marker
                if (highlightMarker) {
                    setTimeout(() => {
                        highlightMarker.openPopup();
                    }, 500);
                } else {
                    // If marker wasn't found in data, create a temporary one
                    const tempMarker = L.circleMarker([lat, lng], {
                        radius: 12,
                        color: '#ffcc00',
                        fillColor: '#ffd54f',
                        fillOpacity: 1,
                        weight: 3
                    }).bindPopup(`
                        <b>${targetCase || 'Selected Case'}</b><br>
                        <i>Location pinpointed from Cases page</i>
                    `).addTo(map);
                    
                    setTimeout(() => {
                        tempMarker.openPopup();
                    }, 500);
                }
            }
        })
        .catch(err => {
            console.error('Map Error:', err);
            alert('Map failed. Check console.');
        });

    // Sidebar Toggle
    const sidebar = document.querySelector('.sidebar');

    function toggleSidebar() {
        sidebar.classList.toggle('collapsed');
        setTimeout(() => {
            map.invalidateSize();
        }, 350);
    }
</script>
</body>
</html>