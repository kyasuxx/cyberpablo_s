<?php
session_start();
require_once 'config/connection.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incident_id = intval($_POST['incident_id']);
    $log_type = $_POST['log_type'] ?? 'General';
    $note = trim($_POST['note']);
    $user_id = $_SESSION['user_id'];
    if (empty($note)) {
        echo json_encode(['status' => 'error', 'message' => 'Log details cannot be empty.']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO case_logs (incident_id, user_id, log_type, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $incident_id, $user_id, $log_type, $note);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE incidents SET updated_at = NOW() WHERE id = $incident_id");
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
}
?>