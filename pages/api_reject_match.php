<?php
session_start();
require_once 'config/connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $case_a = $_POST['case_a'];
    $case_b = $_POST['case_b'];
    $user_id = $_SESSION['user_id'];
    
    // Save the rejection to the database
    $stmt = $conn->prepare("INSERT INTO rejected_links (case_a, case_b, rejected_by) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $case_a, $case_b, $user_id);
    
    if ($stmt->execute()) {
        // Also log it in the audit trail! (Panelists will love this)
        $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)");
        $action = "Rejected case link between " . $case_a . " and " . $case_b;
        $audit->bind_param("iss", $user_id, $action, $_SERVER['REMOTE_ADDR']);
        $audit->execute();

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
?>