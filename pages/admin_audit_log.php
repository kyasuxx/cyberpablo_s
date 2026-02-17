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
    <link rel="stylesheet" href="../assets/css/admin_audit_log.css">
</head>
<body>

<div class="header">
    <div>
        <a href="admin_panel.php" class="back-btn">← Back to Panel</a>
        <h2 class="header-title">Security Audit Trail</h2>
        <p class="header-subtitle">Monitoring system access and critical actions (RA 10175 Compliance).</p>
    </div>
    <div>
        <button onclick="window.print()" class="print-btn">Print Log</button>
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
                <td class="timestamp-col">
                    <?= date('M d, Y H:i:s', strtotime($row['timestamp'])) ?>
                </td>
                <td>
                    <strong><?= htmlspecialchars($row['username'] ?? 'Unknown') ?></strong>
                    <?php if(isset($row['role'])): ?>
                        <span class="role-badge role-<?= htmlspecialchars($row['role']) ?>">
                            <?= htmlspecialchars($row['role']) ?>
                        </span>
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