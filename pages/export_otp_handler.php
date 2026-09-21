<?php
session_start();
require_once 'config/connection.php';

// IMPORTANT: Include your Composer autoload! Adjust the path if necessary.
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

// ACTION 1: GENERATE AND SEND OTP
if ($action === 'send') {
    $otp = rand(100000, 999999);
    $_SESSION['export_otp'] = $otp;

    // Fetch the logged-in admin's email
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $admin_email = $user['email'];

        // Initialize PHPMailer
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;

            // Pull credentials from your .env file
            $mail->Username   = getenv('SMTP_EMAIL');
            $mail->Password   = getenv('SMTP_APP_PASSWORD');

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients - use the variable rather than a hardcoded string
            $mail->setFrom($mail->Username, 'CyberPablo Security');
            $mail->addAddress($admin_email); // The admin's email from the DB

            // Content
            $mail->isHTML(false);
            $mail->Subject = 'CyberPablo Security - Database Backup OTP';
            $mail->Body    = "Hello Admin,\n\nSomeone requested a full SQL database backup from your account.\n\nYour One-Time Password (OTP) is: " . $otp . "\n\nIf you did not request this, please change your password immediately.";

            // Send the email
            $mail->send();

            echo json_encode([
                'success' => true,
                'message' => 'OTP sent to ' . $admin_email,
            ]);
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $mail->ErrorInfo);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Admin email not found in database.']);
    }
    exit;
}

// ACTION 2: VERIFY OTP
if ($action === 'verify') {
    $input_otp = trim($_POST['otp'] ?? '');

    if (isset($_SESSION['export_otp']) && $input_otp == $_SESSION['export_otp']) {
        $_SESSION['export_verified'] = true;
        unset($_SESSION['export_otp']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
