<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper (Admins Only)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// 2. Fetch Logs (Joined with Users table)
$sql = "SELECT a.*, u.username, u.role 
        FROM audit_log a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.timestamp DESC 
        LIMIT 500";
$result = $conn->query($sql);

// 3. LOG SUMMARY CALCULATION (Last 24 Hours)
$summary_sql = "
    SELECT 
        COUNT(*) as total_actions,
        SUM(CASE WHEN action LIKE 'login%' THEN 1 ELSE 0 END) as logins,
        SUM(CASE WHEN action = 'logout' THEN 1 ELSE 0 END) as logouts,
        SUM(CASE WHEN action LIKE '%delete%' OR action LIKE '%reject%' THEN 1 ELSE 0 END) as critical_actions,
        SUM(CASE WHEN action LIKE '%excel_import%' OR action LIKE '%add%' THEN 1 ELSE 0 END) as data_entry
    FROM audit_log 
    WHERE timestamp >= NOW() - INTERVAL 1 DAY
";
$summary_res = $conn->query($summary_sql)->fetch_assoc();

$total_24h = $summary_res['total_actions'] ?? 0;
$logins_24h = $summary_res['logins'] ?? 0;
$logouts_24h = $summary_res['logouts'] ?? 0;
$critical_24h = $summary_res['critical_actions'] ?? 0;
$entry_24h = $summary_res['data_entry'] ?? 0;

// Helper Function for Tag Styling
function getActionClass($action) {
    $action = strtolower($action);
    if (strpos($action, 'delete') !== false || strpos($action, 'reject') !== false || strpos($action, 'export') !== false) return 'tag-danger';
    if (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'confirm') !== false) return 'tag-warning';
    if (strpos($action, 'import') !== false || strpos($action, 'add') !== false || strpos($action, 'login') !== false) return 'tag-success';
    return 'tag-info';
}
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
        <p class="header-subtitle">Electronic Evidence & Access Monitoring (RA 10175 Compliance).</p>
    </div>
    <div>
        <button onclick="window.print()" class="print-btn">Print Log</button>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-box">
        <h4>Actions (24h)</h4>
        <div class="stat"><?= $total_24h ?></div>
    </div>
    <div class="summary-box">
        <h4>Access (24h)</h4>
        <div class="stat">
            <span class="text-success"><?= $logins_24h ?> IN</span> / 
            <span class="text-muted"><?= $logouts_24h ?> OUT</span>
        </div>
    </div>
    <div class="summary-box">
        <h4>Data Entries</h4>
        <div class="stat"><?= $entry_24h ?></div>
    </div>
    <div class="summary-box <?= $critical_24h > 0 ? 'alert-border' : '' ?>">
        <h4>Critical Actions</h4>
        <div class="stat <?= $critical_24h > 0 ? 'text-danger' : '' ?>">
            <?= $critical_24h ?>
        </div>
    </div>
</div>

<div class="log-card">
    <div class="search-container">
        <input type="text" id="logSearch" onkeyup="filterLogs()" placeholder="Filter by user, action, case number, or IP..." class="search-input">
    </div>

    <table id="auditTable">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User / Role</th>
                <th>Action Performed</th>
                <th>Source IP</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr class="log-row">
                <td class="timestamp-col">
                    <?= date('M d, Y', strtotime($row['timestamp'])) ?><br>
                    <strong><?= date('h:i:s A', strtotime($row['timestamp'])) ?></strong>
                </td>
                <td>
                    <span class="username-link"><?= htmlspecialchars($row['username'] ?? 'SYSTEM') ?></span>
                    <div class="role-subtitle"><?= htmlspecialchars($row['role'] ?? 'AUTOMATED') ?></div>
                </td>
                <td>
                    <span class="tag <?= getActionClass($row['action']) ?>">
                        <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($row['action']))); ?>
                    </span>
                </td>
                <td>
                    <span class="ip-address"><?= htmlspecialchars($row['ip_address']) ?></span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
/**
 * Live Table Filter
 * Filters through the table rows based on user input
 */
function filterLogs() {
    let input = document.getElementById("logSearch").value.toLowerCase();
    let rows = document.querySelectorAll(".log-row");

    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? "" : "none";
    });
}
</script>

</body>
</html>