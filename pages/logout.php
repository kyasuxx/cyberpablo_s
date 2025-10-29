<?php
session_start();
require_once 'config/connection.php';

$user_id = $_SESSION['user_id'] ?? null;
$ip = $_SERVER['REMOTE_ADDR'];

if ($user_id) {
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'logout', ?)");
    $stmt->bind_param("is", $user_id, $ip);
    $stmt->execute();
}

session_destroy();
header("Location: login.php");
exit;
?>