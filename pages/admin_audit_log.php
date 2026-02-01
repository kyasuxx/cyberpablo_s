<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper (Admins Only)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// 2. Fetch Logs (Joined with Users table to show names, not just IDs)
$sql = "SELECT a.*, u.username, u.role 
        FROM audit_log a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.timestamp DESC 
        LIMIT 500"; // Limit to last 500 actions for performance
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Audit Trail - CyberPablo</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .back-btn { text-decoration: none; color: #003366; font-weight: bold; }
        
        .log-card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); overflow: hidden; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #003366; color: white; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        tr:hover { background: #f9f9f9; }
        
        .role-badge { padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .role-admin { background: #e3f2fd; color: #1565c0; }
        .role-user { background: #f3e5f5; color: #7b1fa2; }
        
        .action-tag { font-family: monospace; background: #eee; padding: 2px 5px; border-radius: 3px; color: #333; }
        .ip-address { color: #666; font-size: 12px; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <a href="admin_panel.php" class="back-btn">← Back to Panel</a>
        <h2 style="margin: 5px 0; color: #333;">Security Audit Trail</h2>
        <p style="color: #666; margin: 0; font-size: 14px;">Monitoring system access and critical actions (RA 10175 Compliance).</p>
    </div>
    <div>
        <button onclick="window.print()" style="padding: 8px 15px; cursor: pointer;">Print Log</button>
    </div>
</div>

<div class="log-card">
    <table>
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User / Role</th>
                <th>Action Performed</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td style="white-space: nowrap; color: #555;">
                    <?= date('M d, Y H:i:s', strtotime($row['timestamp'])) ?>
                </td>
                <td>
                    <strong><?= htmlspecialchars($row['username'] ?? 'Unknown') ?></strong>
                    <?php if(isset($row['role'])): ?>
                        <span class="role-badge role-<?= $row['role'] ?>"><?= $row['role'] ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="action-tag"><?= htmlspecialchars($row['action']) ?></span>
                </td>
                <td>
                    <span class="ip-address"><?= htmlspecialchars($row['ip_address']) ?></span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>