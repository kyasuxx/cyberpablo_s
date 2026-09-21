<?php
session_start();
require_once 'config/connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $case_a = $_POST['case_a'];
    $case_b = $_POST['case_b'];
    $user_id = $_SESSION['user_id'];
    $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)");
    $action = "Confirmed serial link between Case " . $case_a . " and Case " . $case_b;

    $audit->bind_param("iss", $user_id, $action, $_SERVER['REMOTE_ADDR']);

    if ($audit->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
?>
