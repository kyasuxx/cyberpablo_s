<?php
// view_evidence.php
session_start();
require_once 'config/connection.php';

// 1. SECURITY: Block direct access
if (!isset($_SESSION['user_id'])) {
    die("ACCESS DENIED: Authorization required.");
}

// 2. VALIDATION: Get the ID
$attachment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($attachment_id === 0) {
    die("Invalid file request.");
}

// 3. RETRIEVAL: Find the file in the database
$stmt = $conn->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->bind_param("i", $attachment_id);
$stmt->execute();
$result = $stmt->get_result();
$file = $result->fetch_assoc();

if (!$file) {
    die("Evidence record not found or has been deleted.");
}

// 4. CHAIN OF CUSTODY LOGGING (The Thesis Feature)
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
// Action string: "Officer John viewed evidence: scam_screenshot.png"
$action = "viewed_evidence: " . $file['file_name']; 
$ip = $_SERVER['REMOTE_ADDR'];

// Insert into Audit Log
$log_stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)");
$log_stmt->bind_param("iss", $user_id, $action, $ip);

if ($log_stmt->execute()) {
    // 5. DELIVERY: Redirect to the actual file
    // We use a redirect so the browser handles the file type (image, PDF, etc.) naturally
    header("Location: " . $file['file_path']);
    exit;
} else {
    die("Error logging chain of custody. Access denied.");
}
?>