<?php
session_start();
require_once 'config/connection.php';

header('Content-Type: application/json');

// 1. STRICT RBAC SECURITY CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['case_no'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$case_no = trim($_POST['case_no']);
$admin_id = $_SESSION['user_id'];
$ip_address = $_SERVER['REMOTE_ADDR'];

// 2. FIND THE INCIDENT ID
$stmt = $conn->prepare("SELECT id FROM incidents WHERE case_no = ? LIMIT 1");
$stmt->bind_param("s", $case_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Case number not found in the database.']);
    exit;
}

$incident = $result->fetch_assoc();
$incident_id = $incident['id'];
$stmt->close();

// 3. PHYSICAL DISK CLEANUP (Delete evidence images so your server doesn't bloat)
$file_stmt = $conn->prepare("SELECT file_path FROM attachments WHERE incident_id = ?");
$file_stmt->bind_param("i", $incident_id);
$file_stmt->execute();
$file_result = $file_stmt->get_result();

while ($row = $file_result->fetch_assoc()) {
    $path = $row['file_path'];
    if (!empty($path) && file_exists($path)) {
        unlink($path); // Physically deletes the file from the hard drive
    }
}
$file_stmt->close();

// 3.5. CLEAN UP GHOST AI REJECTIONS
// If this case had any AI links rejected by an admin, delete those records.
// This prevents a future reused case number from inheriting the old rejections.
$rej_stmt = $conn->prepare("DELETE FROM rejected_links WHERE case_a = ? OR case_b = ?");
$rej_stmt->bind_param("ss", $case_no, $case_no);
$rej_stmt->execute();
$rej_stmt->close();

// 4. DATABASE DELETION
// (Because you have ON DELETE CASCADE in your SQL, deleting the incident automatically deletes the attachments and status history!)


$del_stmt = $conn->prepare("DELETE FROM incidents WHERE id = ?");
$del_stmt->bind_param("i", $incident_id);
$del_success = $del_stmt->execute();
$del_stmt->close();

if ($del_success) {
    // 5. ACCOUNTABILITY AUDIT LOG
    $action_text = "Permanently deleted Case No: " . $case_no;
    $log_stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)");
    $log_stmt->bind_param("iss", $admin_id, $action_text, $ip_address);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error during deletion.']);
}
?>