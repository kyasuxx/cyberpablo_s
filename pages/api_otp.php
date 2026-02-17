<?php
session_start();
header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'generate') {
    // Generate a 6-digit random code
    $otp = rand(100000, 999999);
    
    // Save to session so we can verify it later
    $_SESSION['export_otp'] = $otp;
    $_SESSION['export_otp_time'] = time();

    // NOTE: In a real-world scenario, you would put the Semaphore SMS API code here 
    // to text $_SESSION['admin_phone_number']

    echo json_encode(['success' => true, 'code' => $otp]); // Sending code back for simulation purposes
    exit;
}

if ($action === 'verify') {
    $input_code = trim($_POST['code'] ?? '');
    $actual_code = $_SESSION['export_otp'] ?? '';
    $timestamp = $_SESSION['export_otp_time'] ?? 0;

    // Check if code matches AND is less than 5 minutes old
    if ($input_code == $actual_code && (time() - $timestamp) < 300) {
        // Destroy code after successful use (prevents replay attacks)
        unset($_SESSION['export_otp']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired code']);
    }
    exit;
}