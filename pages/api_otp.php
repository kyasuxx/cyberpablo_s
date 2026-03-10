<?php
session_start();
header('Content-Type: application/json');

// Bring in database and Composer dependencies
require_once 'config/connection.php';
require_once '../vendor/autoload.php'; // This loads PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'generate') {
    // Fetch the user's email from the database
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();

    if (empty($user_data['email'])) {
        echo json_encode(['success' => false, 'message' => 'No email address registered to this account.']);
        exit;
    }

    $otp = rand(100000, 999999);
    $_SESSION['export_otp'] = $otp;
    $_SESSION['export_otp_time'] = time();

    // --- REAL EMAIL SENDING BLOCK (PHPMailer) ---
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // --- IMPORTANT: PUT YOUR GMAIL DETAILS HERE ---
        $mail->Username   = 'bachoichoi31@gmail.com'; // The email sending the OTP
        $mail->Password   = 'REDACTED';  // No spaces
        // ----------------------------------------------
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom($mail->Username, 'CyberPablo System');
        $mail->addAddress($user_data['email']); // Send to the logged-in user's email

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Security Verification - CyberPablo Data Export';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ccc; border-radius: 5px;'>
                <h2 style='color: #003366;'>CyberPablo Authorization</h2>
                <p>You requested a secure data export. Please use the following One-Time Password (OTP) to authorize this action.</p>
                <div style='background: #f4f4f4; padding: 15px; font-size: 24px; font-weight: bold; text-align: center; letter-spacing: 5px; margin: 20px 0;'>
                    {$otp}
                </div>
                <p style='color: red; font-size: 12px;'>This code is valid for exactly 5 minutes. Do not share it with anyone.</p>
            </div>
        ";

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent to registered email.']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Email failed to send. Check server settings.']);
        exit;
    }
    // ------------------------------------------
}

if ($action === 'verify') {
    $input_code = trim($_POST['code'] ?? '');
    $actual_code = $_SESSION['export_otp'] ?? '';
    $timestamp = $_SESSION['export_otp_time'] ?? 0;

    // Check if code matches AND is less than 5 minutes old (300 seconds)
    if ($input_code == $actual_code && (time() - $timestamp) < 300) {
        unset($_SESSION['export_otp']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired code.']);
    }
    exit;
}