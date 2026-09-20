<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../pages/config/connection.php'; // Make sure this path is correct

$sql = "SELECT
            i.case_no,
            i.incident_type,
            i.lat,
            i.lng,
            i.incident_date,
            i.status,
            i.modus_operandi,
            i.accused,
            i.complainant,
            b.official_name,
            b.alt_name
        FROM incidents i
        LEFT JOIN barangays b ON i.barangay_id = b.id
        WHERE 1=1";

$params = [];
$types = "";

// Filters
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if ($search) {
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR i.modus_operandi LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= "ssss";
}

// 🚨 FIXED THIS BLOCK TO USE $sql .= INSTEAD OF $where[]
if (!empty($_GET['type'])) {
    $type_val = $_GET['type'];
    if ($type_val === 'Others') {
        $sql .= " AND i.incident_type LIKE ?";
        $params[] = "Others%";
    } else {
        $sql .= " AND i.incident_type = ?";
        $params[] = $type_val;
    }
    $types .= "s";
}

if ($barangay) {
    $sql .= " AND b.id = ?";
    $params[] = $barangay;
    $types .= "i";
}
if ($status) {
    $sql .= " AND i.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Server-side date filtering logic
if ($date_from && $date_to) {
    $sql .= " AND i.incident_date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
} elseif ($date_from) {
    $sql .= " AND i.incident_date >= ?";
    $params[] = $date_from;
    $types .= "s";
} elseif ($date_to) {
    $sql .= " AND i.incident_date <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= "s";
}

$sql .= " ORDER BY i.incident_date DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $lat = (float)$row['lat'];
    $lng = (float)$row['lng'];

    // SKIP invalid GPS or Dates
    if (
        $lat == 0 || $lng == 0 ||
        $lat < -90 || $lat > 90 ||
        $lng < -180 || $lng > 180 ||
        is_null($row['lat']) || is_null($row['lng']) ||
        is_null($row['incident_date']) // Also skip if date is null
    ) {
        continue;
    }

    $data[] = [
        'case_no' => $row['case_no'],
        'incident_type' => $row['incident_type'],
        'barangay' => $row['official_name'] ?? 'Unknown',
        'lat' => $lat,
        'lng' => $lng,
        'incident_date' => $row['incident_date'],
        'status' => $row['status'],
        'modus_operandi' => $row['modus_operandi'],
        'accused' => $row['accused'] ?? 'Unknown',
        'complainant' => $row['complainant'] ?? 'Unknown'
    ];
}

echo json_encode($data);
?>
