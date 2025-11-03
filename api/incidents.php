<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../pages/config/connection.php';

$stmt = $conn->prepare("
    SELECT 
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
        i.barangay = b.alt_name
    )
    ORDER BY i.incident_date DESC
");

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit;
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = [
        'case_no' => $row['case_no'],
        'incident_type' => $row['incident_type'],
        'barangay' => $row['official_name'] ?? $row['barangay'],
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng'],
        'incident_date' => $row['incident_date'],
        'status' => $row['status'],
        'modus_operandi' => $row['modus_operandi']
    ];
}

echo json_encode($data);
?>