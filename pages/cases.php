<?php
session_start();
require_once 'config/connection.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Filters
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// JOIN with official_name OR alt_name
$sql = "SELECT i.*, b.lat, b.lng, b.official_name, b.alt_name
        FROM incidents i 
        LEFT JOIN barangays b ON (
            i.barangay = b.official_name OR 
            i.barangay = b.alt_name OR
            i.barangay = REPLACE(b.official_name, 'Brgy. ', '') OR
            i.barangay = REPLACE(b.alt_name, 'Brgy. ', '')
        )
        WHERE 1=1";
$params = []; $types = "";

// Filters
if ($search) {
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR b.official_name LIKE ? OR b.alt_name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= "sssss";
}
if ($type) { $sql .= " AND i.incident_type = ?"; $params[] = $type; $types .= "s"; }
if ($barangay) { $sql .= " AND (b.official_name = ? OR b.alt_name = ?)"; $params[] = $barangay; $params[] = $barangay; $types .= "ss"; }
if ($status) { $sql .= " AND i.status = ?"; $params[] = $status; $types .= "s"; }

// Count Query
$count_sql = preg_replace('/SELECT i\.\*, b\.lat, b\.lng, b\.official_name, b\.alt_name/', 'SELECT COUNT(*)', $sql);
$count_stmt = $conn->prepare($count_sql);
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_row()[0];
$pages = ceil($total / $limit);

// Main Query
$sql .= " ORDER BY i.incident_date DESC LIMIT ? OFFSET ?";
$params[] = $limit; $params[] = $offset; $types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get barangays for dropdown (official_name)
$barangay_result = $conn->query("SELECT official_name, alt_name FROM barangays ORDER BY official_name");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cases - CyberPablo</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        body { margin: 0; font-family: Arial; background: #f4f6f9; }
        .header { background: #003366; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .nav a { color: #ffcc00; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .container { padding: 20px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-box { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); flex: 1; text-align: center; }
        .stat-box h3 { margin: 0; color: #003366; }
        .filters { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filters input, .filters select, .filters button { padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .filters button { background: #003366; color: white; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #003366; color: white; }
        tr:hover { background: #f8f9fa; cursor: pointer; }
        .status-open { color: #d32f2f; font-weight: bold; }
        .status-invest { color: #f9a825; font-weight: bold; }
        .status-closed { color: #388e3c; font-weight: bold; }
        .pagination { text-align: center; margin: 20px 0; }
        .pagination a { margin: 0 5px; padding: 8px 12px; background: #003366; color: white; text-decoration: none; border-radius: 5px; }
        .pagination a.active { background: #ffcc00; color: #003366; }
        .export { background: #d32f2f; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; float: right; }
        .mini-map { height: 200px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
        .alt-name { font-size: 0.85em; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>CYBERPABLO</h1>
        <div class="nav">
            <a href="dashboard.php">Map</a>
            <a href="cases.php">Cases</a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="import_excel.php">Import Excel</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <h2>Cases Dashboard (<?= $total ?> Total)</h2>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Open'")->fetch_row()[0] ?></h3>
                <p>Open Cases</p>
            </div>
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Under Investigation'")->fetch_row()[0] ?></h3>
                <p>Investigating</p>
            </div>
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Closed'")->fetch_row()[0] ?></h3>
                <p>Closed</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display: flex; gap: 10px; flex: 1;">
                <input type="text" name="search" placeholder="Search Case No, Accused, Barangay..." value="<?= htmlspecialchars($search) ?>">
                <select name="type">
                    <option value="">All Types</option>
                    <option value="Phishing" <?= $type=='Phishing'?'selected':'' ?>>Phishing</option>
                    <option value="Online Fraud" <?= $type=='Online Fraud'?'selected':'' ?>>Online Fraud</option>
                    <option value="Cyber Harassment" <?= $type=='Cyber Harassment'?'selected':'' ?>>Cyber Harassment</option>
                    <option value="Identity Theft" <?= $type=='Identity Theft'?'selected':'' ?>>Identity Theft</option>
                </select>
                <select name="barangay">
                    <option value="">All Barangays</option>
                    <?php while ($b = $barangay_result->fetch_assoc()): ?>
                        <option value="<?= $b['official_name'] ?>" <?= $barangay==$b['official_name']?'selected':'' ?>>
                            <?= $b['official_name'] ?><?= $b['alt_name'] ? " ({$b['alt_name']})" : '' ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                    <option value="Under Investigation" <?= $status=='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                    <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                </select>
                <button type="submit">Filter</button>
                <a href="cases.php" style="color:#d32f2f;">Clear</a>
            </form>
        </div>

        <!-- Table -->
        <table>
            <tr>
                <th>Case No</th>
                <th>Type</th>
                <th>Accused</th>
                <th>Complainant</th>
                <th>Barangay</th>
                <th>Date Filed</th>
                <th>Status</th>
                <th>Map</th>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr onclick="showOnMap(<?= $row['lat'] ?? 14.0702 ?>, <?= $row['lng'] ?? 121.3256 ?>, '<?= addslashes($row['case_no']) ?>')">
                <td><strong><?= $row['case_no'] ?></strong></td>
                <td><?= $row['incident_type'] ?></td>
                <td><?= htmlspecialchars($row['accused'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['complainant'] ?? '—') ?></td>
                <td>
                    <?= htmlspecialchars($row['official_name'] ?? $row['barangay']) ?>
                    <?php if ($row['alt_name'] && $row['official_name']): ?>
                        <div class="alt-name">(<?= $row['alt_name'] ?>)</div>
                    <?php endif; ?>
                </td>
                <td><?= $row['date_filed'] ? date('M d, Y', strtotime($row['date_filed'])) : '—' ?></td>
                <td>
                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                        <?= $row['status'] ?>
                    </span>
                </td>
                <td>
                    <?php if ($row['lat'] && $row['lng']): ?>
                        <a href="dashboard.php?lat=<?= $row['lat'] ?>&lng=<?= $row['lng'] ?>&case=<?= urlencode($row['case_no']) ?>" 
                           style="color:green; text-decoration:none; font-weight:bold;">
                            View on Map
                        </a>
                    <?php else: ?>
                        <span style="color:orange;">No GPS</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

        <!-- Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= $type ?>&barangay=<?= $barangay ?>&status=<?= $status ?>" 
                   class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>

        <a href="export_cases.php" class="export">Export to Excel</a>
    </div>

    <!-- Mini Map -->
    <div id="miniMap" class="mini-map" style="display:none;"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map, marker;
        function showOnMap(lat, lng, title) {
            const mini = document.getElementById('miniMap');
            mini.style.display = 'block';
            if (!map) {
                map = L.map('miniMap').setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
            } else {
                map.setView([lat, lng], 16);
            }
            if (marker) marker.remove();
            marker = L.circleMarker([lat, lng], {
                radius: 8, color: '#d32f2f', fillColor: '#f44336', fillOpacity: 0.9
            }).addTo(map).bindPopup(`<b>${title}</b>`).openPopup();
        }
    </script>
</body>
</html>