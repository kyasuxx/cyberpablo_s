<?php
session_start();
require_once 'config/connection.php';

// ==========================================
// 1. STRICT ROLE-BASED ACCESS CONTROL (RBAC)
// ==========================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$success_msg = '';
$error_msg = '';

// ==========================================
// 2. FORM PROCESSING HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Action: Update Agency Info
    if (isset($_POST['update_agency'])) {
        $agency_name = trim($_POST['agency_name']);
        $branch = trim($_POST['branch']);
        
        $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'updated_agency_settings', ?)");
        $audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
        $audit->execute();
        
        $success_msg = "Agency information successfully updated.";
    }

    // Action: Clear Audit Logs
    if (isset($_POST['clear_logs'])) {
        try {
            // Note: Make sure 'timestamp' matches your actual column name in the database!
            $conn->query("DELETE FROM audit_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $deleted = $conn->affected_rows;
            
            $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'cleared_old_logs', ?)");
            $audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
            $audit->execute();
            
            $success_msg = "System maintenance complete. Removed $deleted old audit log entries.";
        } catch (Exception $e) {
            $error_msg = "Failed to clear logs: " . $conn->error;
        }
    }
}

// Mock values for the UI
$current_agency = "San Pablo City Police Station";
$current_branch = "Cybercrime Investigation Division";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - CyberPablo Admin</title>
    <script src="https://kit.fontawesome.com/6e8b00826a.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../assets/css/admin_settings.css">

</head>
<body>
    <?php require_once 'header.php'; ?>
    <?php require_once 'admin_sidebar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1><i class="fa-solid fa-sliders" style="color: var(--color-primary-blue-light);"></i> System Configurations</h1>
            <p>Master control panel. Changes made here affect all users and system behaviors.</p>
        </div>

        <?php if ($success_msg): ?>
            <div class="message success"><i class="fa-solid fa-circle-check"></i> <?= $success_msg ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="message error"><i class="fa-solid fa-triangle-exclamation"></i> <?= $error_msg ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2><i class="fa-solid fa-server"></i> Database Maintenance</h2>
        </div>
        <div class="form-grid">
            <div class="maintenance-box">
                <div class="maintenance-info">
                    <h3><i class="fa-solid fa-file-export" style="color: var(--color-primary-blue-light);"></i> Export SQL Backup</h3>
                    <p>Download a complete `.sql` dump of the entire database.</p>
                </div>
                <button type="button" class="btn-secondary" onclick="requestExportOTP()">Generate Backup</button>
            </div>

            <div class="maintenance-box" style="border-left: 4px solid var(--color-danger);">
                <div class="maintenance-info">
                    <h3><i class="fa-solid fa-trash-can" style="color: var(--color-danger);"></i> Clear Old Audit Logs</h3>
                    <p>Permanently delete logs older than 30 days. <strong>Cannot be undone.</strong></p>
                </div>
                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete old audit logs?');">
                    <button type="submit" name="clear_logs" class="btn-danger">Purge Logs</button>
                </form>
            </div>
        </div>

        <div class="form-section" style="margin-top: 40px;">
            <h2 style="color: #d32f2f;"><i class="fa-solid fa-triangle-exclamation"></i> Dangerous Actions</h2>
        </div>
        <div class="form-grid">
            <div class="maintenance-box" style="border-left: 4px solid #d32f2f; background-color: #fdf2f2;">
                <div class="maintenance-info">
                    <h3 style="color: #d32f2f;"><i class="fa-solid fa-folder-minus"></i> Delete Case Record</h3>
                    <p>Permanently delete a specific case number, including all attached digital evidence files and status history logs. <strong>Use with extreme caution.</strong></p>
                </div>
                
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="delete_cases.php" class="btn-danger" style="text-decoration: none; display: inline-block; padding: 10px 30px; white-space: nowrap;">
                        Deletion Tool
                    </a>
                <?php endif; ?>
                
            </div>
        </div>
        </div>

    <div id="otpModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; width: 350px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <h2 style="margin-top: 0; color: #2c4e9e;"><i class="fa-solid fa-shield-halved"></i> Security Check</h2>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;" id="otpMessage">An OTP has been sent to your email. Please enter it to authorize the database export.</p>
            
            <input type="text" id="otpInput" placeholder="Enter 6-digit OTP" maxlength="6" style="width: 100%; padding: 12px; margin-bottom: 15px; text-align: center; font-size: 18px; letter-spacing: 5px; border: 1px solid #cbd5e1; border-radius: 6px;">
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="closeOTPModal()" style="padding: 10px 20px; border: none; background: #e2e8f0; color: #333; border-radius: 6px; cursor: pointer; font-weight: bold;">Cancel</button>
                <button onclick="verifyOTP()" style="padding: 10px 20px; border: none; background: #2c4e9e; color: white; border-radius: 6px; cursor: pointer; font-weight: bold;">Verify & Download</button>
            </div>
        </div>
    </div>

    <script>
        function requestExportOTP() {
            fetch('export_otp_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=send'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('otpModal').style.display = 'flex';
                    console.log("DEV MODE OTP: " + data.dev_otp); 
                } else {
                    alert(data.message);
                }
            });
        }

        function verifyOTP() {
            const otp = document.getElementById('otpInput').value;
            if (otp.length !== 6) {
                alert("Please enter a 6-digit OTP.");
                return;
            }

            fetch('export_otp_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=verify&otp=' + encodeURIComponent(otp)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeOTPModal();
                    window.location.href = 'export_db.php';
                } else {
                    alert(data.message);
                }
            });
        }

        function closeOTPModal() {
            document.getElementById('otpModal').style.display = 'none';
            document.getElementById('otpInput').value = '';
        }
    </script>
</body>
</html>