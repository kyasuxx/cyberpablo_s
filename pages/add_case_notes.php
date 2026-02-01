<?php
session_start();
require_once 'config/connection.php';

// Return JSON response for AJAX
header('Content-Type: application/json');

// 1. Security Check: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incident_id = intval($_POST['incident_id']);
    $log_type = $_POST['log_type'] ?? 'General';
    $note = trim($_POST['note']);
    $user_id = $_SESSION['user_id'];
    
    // 2. Validation: Don't allow empty notes
    if (empty($note)) {
        echo json_encode(['status' => 'error', 'message' => 'Log details cannot be empty.']);
        exit;
    }

    // 3. Insert the Log (Prepared Statement for Security)
    $stmt = $conn->prepare("INSERT INTO case_logs (incident_id, user_id, log_type, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $incident_id, $user_id, $log_type, $note);
    
    if ($stmt->execute()) {
        // 4. Audit Trail: Also update the main case 'updated_at' timestamp
        $conn->query("UPDATE incidents SET updated_at = NOW() WHERE id = $incident_id");
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
}
?>