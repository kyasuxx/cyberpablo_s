<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../pages/config/connection.php';

$sql = "SELECT 
            i.case_no, 
            i.incident_type, 
            i.barangay, 
            i.lat, 
            i.lng,
            i.incident_date,
            i.status,
            i.modus_operandi,
            b.official_name,
            b.alt_name
        FROM incidents i 
        LEFT JOIN barangays b ON (
            i.barangay = b.official_name OR 
            i.barangay = b.alt_name OR
            REPLACE(i.barangay, 'Brgy. ', '') = REPLACE(b.official_name, 'Brgy. ', '') OR
            REPLACE(i.barangay, 'Brgy. ', '') = REPLACE(b.alt_name, 'Brgy. ', '')
        )
        WHERE 1=1";

$params = []; 
$types = "";

// Filters
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';

if ($search) {
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= "sss";
}
if ($type) { 
    $sql .= " AND i.incident_type = ?"; 
    $params[] = $type; 
    $types .= "s"; 
}
if ($barangay) { 
    $sql .= " AND (b.official_name = ? OR b.alt_name = ?)"; 
    $params[] = $barangay; 
    $params[] = $barangay; 
    $types .= "ss"; 
}
if ($status) { 
    $sql .= " AND i.status = ?"; 
    $params[] = $status; 
    $types .= "s"; 
}

$sql .= " ORDER BY i.incident_date DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit;
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $lat = (float)$row['lat'];
    $lng = (float)$row['lng'];

    // SKIP invalid GPS
    if (
        $lat == 0 || $lng == 0 || 
        $lat < -90 || $lat > 90 || 
        $lng < -180 || $lng > 180 ||
        is_null($row['lat']) || is_null($row['lng'])
    ) {
        continue;
    }

    $data[] = [
        'case_no' => $row['case_no'],
        'incident_type' => $row['incident_type'],
        'barangay' => $row['official_name'] ?? $row['barangay'],
        'lat' => $lat,
        'lng' => $lng,
        'incident_date' => $row['incident_date'],
        'status' => $row['status'],
        'modus_operandi' => $row['modus_operandi']
    ];
}

echo json_encode($data);
?>