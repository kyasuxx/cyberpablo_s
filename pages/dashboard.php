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
    <style>
        body { margin: 0; font-family: Arial; background: #f4f6f9; }
        .header {
            background: #003366; color: white; padding: 15px; text-align: center;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { margin: 0; font-size: 24px; }
        .logout { color: #ffcc00; text-decoration: none; font-weight: bold; }
        .container { display: flex; height: calc(100vh - 70px); }
        .sidebar { width: 350px; background: white; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); overflow-y: auto; }
        .map { flex: 1; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #003366; color: white; }
        .search { width: 100%; padding: 10px; margin: 15px 0; font-size: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>CYBERPABLO</h1>
        <div>
            <span>Welcome, <strong><?= htmlspecialchars($username) ?></strong> (<?= $role ?>)</span> |
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="sidebar">
            <h3>Recent Cases</h3>
            <input type="text" class="search" placeholder="Search by type or barangay..." id="searchInput">

            <table id="casesTable">
                <tr><th>Case No</th><th>Type</th><th>Barangay</th><th>Date</th></tr>
                <?php
                $result = $conn->query("SELECT case_no, incident_type, barangay, incident_date FROM incidents ORDER BY incident_date DESC LIMIT 10");
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>
                        <td>{$row['case_no']}</td>
                        <td>{$row['incident_type']}</td>
                        <td>{$row['barangay']}</td>
                        <td>" . date('M d, Y', strtotime($row['incident_date'])) . "</td>
                    </tr>";
                }
                ?>
            </table>
        </div>
        <div id="map" class="map"></div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([14.0702, 121.3256], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    fetch('../api/incidents.php')
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            console.log('Loaded incidents:', data);

            data.forEach(inc => {
                L.circleMarker([inc.lat, inc.lng], {
                    radius: 7,
                    color: '#d32f2f',
                    fillColor: '#f44336',
                    fillOpacity: 0.8
                }).bindPopup(`
                    <b>${inc.case_no}</b><br>
                    <b>Type:</b> ${inc.incident_type}<br>
                    <b>Barangay:</b> ${inc.barangay}
                `).addTo(map);
            });
        })
        .catch(err => {
            console.error('Map Error:', err);
            alert('Map failed. Check console.');
        });
</script>
</body>
</html>