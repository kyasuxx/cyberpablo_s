<?php
session_start();
// Prevent PHP warnings from breaking the JSON format
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once 'config/connection.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'send') {
    $recipient_email = getenv('EXPORT_RECIPIENT_EMAIL');

    $otp = rand(100000, 999999);
    $_SESSION['export_otp'] = (string)$otp;
    $_SESSION['export_otp_time'] = time();

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        // Placeholder
        $mail->Username = getenv('SMTP_EMAIL');
        $mail->Password = getenv('SMTP_APP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($mail->Username, 'CyberPablo System');
        $mail->addAddress($recipient_email);

        $mail->isHTML(true);
        $mail->Subject = 'Security Verification - CyberPablo Data Export';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ccc; border-radius: 5px;'>
                <h2 style='color: #003366;'>CyberPablo Authorization</h2>
                <p>You requested a secure data export. Please use the following One-Time Password (OTP) to authorize this action.</p>
                <div style='background: #f4f4f4; padding: 15px; font-size: 24px; font-weight: bold; text-align: center; letter-spacing: 5px; margin: 20px 0;'>
                    {$otp}
                </div>
                <p style='color: red; font-size: 12px;'>This code is valid for exactly 5 minutes.</p>
            </div>
        ";

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Email failed to send.']);
    }
    exit;
}

if ($action === 'verify') {
    $input_code = trim($_POST['otp'] ?? '');
    $actual_code = $_SESSION['export_otp'] ?? '';
    $timestamp = $_SESSION['export_otp_time'] ?? 0;

    // Strict check: Not empty, matches exactly, and within 5 minutes
    if ($input_code !== '' && $input_code === $actual_code && (time() - $timestamp) < 300) {
        unset($_SESSION['export_otp']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired code.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
?>
