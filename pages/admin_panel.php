<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper: Strict Admin Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - CyberPablo</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; }
        
        .header {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%); /* distinct Dark Blue for Admin */
            color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .nav-links a { color: #fff; text-decoration: none; margin-left: 20px; font-weight: 500; opacity: 0.9; }
        .nav-links a:hover { opacity: 1; text-decoration: underline; }
        
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        
        .welcome-banner {
            background: white; padding: 25px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px;
            border-left: 5px solid #1a237e;
        }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; }
        
        .card {
            background: white; padding: 25px; border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: transform 0.2s;
            border-top: 4px solid transparent;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        
        .card:hover { transform: translateY(-5px); }
        .card h3 { margin-top: 0; color: #1a237e; }
        .card p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
        
        .btn {
            display: inline-block; padding: 10px 15px; border-radius: 6px;
            text-decoration: none; font-weight: bold; text-align: center;
            transition: background 0.3s;
        }
        
        /* Card Specific Colors */
        .card.add { border-top-color: #2e7d32; }
        .card.add .btn { background: #2e7d32; color: white; }
        .card.add .btn:hover { background: #1b5e20; }
        
        .card.import { border-top-color: #f57f17; }
        .card.import .btn { background: #f57f17; color: white; }
        .card.import .btn:hover { background: #e65100; }
        
        .card.users { border-top-color: #1565c0; }
        .card.users .btn { background: #1565c0; color: white; }
        .card.users .btn:hover { background: #0d47a1; }
    </style>
</head>
<body>

<?php require_once 'header.php'; ?>

<!-- <div class="header">
    <div style="font-size: 20px; font-weight: bold;">CyberPablo <span style="font-weight: 300; opacity: 0.8;">| Admin Command</span></div>
    <div class="nav-links">
        <a href="dashboard.php">View Map</a>
        <a href="cases.php">View Cases</a>
        <a href="logout.php">Logout</a>
    </div>
</div> -->

<div class="container">
    <div class="welcome-banner">
        <h2>Welcome back, Admin <?= htmlspecialchars($username) ?>.</h2>
        <p>Manage system data, user access, and bulk operations from this central panel.</p>
    </div>

    <div class="grid">
        <div class="card add">
            <div>
                <h3>New Case Entry</h3>
                <p>Manually encode a new cybercrime incident report. Includes standardized categorization (Phase 2).</p>
            </div>
            <a href="add_cases.php" class="btn">Open Intake Form</a>
        </div>

        <div class="card import">
            <div>
                <h3>Batch Import</h3>
                <p>Upload Excel (.xlsx) files to bulk update the database. <br><em>Features orphaned file heuristic linkage.</em></p>
            </div>
            <a href="import_cases.php" class="btn">Go to Import Tool</a>
        </div>

        <div class="card users">
            <div>
                <h3>User Management</h3>
                <p>Register new investigators, reset passwords, or deactivate accounts.</p>
            </div>
            <a href="users.php" class="btn">Manage Accounts</a>
        </div>
        <div class="card analysis" style="border-top-color: #9c27b0;">
            <div>
                <h3 style="color: #9c27b0;">Link Analysis</h3>
                <p><strong>AI-driven Logic:</strong> Detects phonetic aliases, modus operandi clusters, and serial victims.</p>
            </div>
            <a href="link_analysis.php" class="btn" style="background: #9c27b0; color: white;">Run Intelligence Scan</a>
        </div>
        <div class="card security" style="border-top-color: #333;">
            <div>
                <h3 style="color: #333;">System Audit Logs</h3>
                <p><strong>Security Compliance:</strong> Review login history, data exports, and system access logs.</p>
            </div>
            <a href="admin_audit_log.php" class="btn" style="background: #333; color: white;">View Security Logs</a>
        </div>
    </div>
</div>

</body>
</html>