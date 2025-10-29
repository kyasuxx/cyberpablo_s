<?php
// pages/api/incidents.php
header('Content-Type: application/json');
require_once '../pages/config/connection.php';

$stmt = $conn->prepare("
    SELECT case_no, incident_type, barangay, lat, lng 
    FROM incidents 
    ORDER BY incident_date DESC
");
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'case_no' => $row['case_no'],
        'incident_type' => $row['incident_type'],
        'barangay' => $row['barangay'],
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng']
    ];
}

echo json_encode($data);
?>