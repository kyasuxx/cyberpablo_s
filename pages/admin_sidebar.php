<?php
// Get the name of the current file to highlight the active link
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <script src="https://kit.fontawesome.com/6e8b00826a.js" crossorigin="anonymous"></script>
</head>
<body>
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="closeAdminSidebar()"></div>

        <div id="adminSidebar" class="admin-sidebar">
            <div class="sidebar-inner" style="display: flex; flex-direction: column; height: 100vh;">
                
                <div class="sidebar-header">
                    <h2>CyberPablo Admin</h2>
                    <button class="close-btn" onclick="closeAdminSidebar()">&#10005;</button>
                </div>

                <ul class="sidebar-menu" style="flex-grow: 1; overflow-y: auto;">
                    
                    <li>
                        <a href="add_cases.php" class="<?= $current_page == 'add_cases.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-plus"></i>New Case Entry</span>
                        </a>
                    </li>
                    <li>
                        <a href="import_cases.php" class="<?= $current_page == 'import_cases.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-download"></i>Batch Import</span>
                        </a>
                    </li>
                    <li>
                        <a href="users.php" class="<?= $current_page == 'users.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-people-arrows"></i>User Management</span>
                        </a>
                    </li>
                    <li>
                        <a href="admin_panel.php" class="<?= $current_page == 'admin_panel.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-brain"></i>Link Analysis</span>
                        </a>
                    </li>
                    <li>
                        <a href="admin_audit_log.php" class="<?= $current_page == 'admin_audit_log.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-regular fa-clipboard"></i>System Audit Logs</span>
                        </a>
                    </li>
                </ul>

                <div class="system-health-widget" style="padding: 15px 25px; border-top: 1px solid rgba(255,255,255,0.1);">
                    <h4 style="color: #94a3b8; font-size: 11px; text-transform: uppercase; margin: 0 0 12px 0; letter-spacing: 1px;">System Health</h4>
                    
                    <div style="font-size: 13px; color: #e2e8f0; margin-bottom: 8px; display: flex; align-items: center;">
                        <i id="status-server-icon" class="fa-solid fa-server" style="color: #4ade80; width: 20px; transition: 0.3s;"></i> 
                        <span>Server: <strong id="status-server-text" style="color: #4ade80; margin-left: 5px; transition: 0.3s;">Checking...</strong></span>
                    </div>
                    
                    <div style="font-size: 13px; color: #e2e8f0; margin-bottom: 8px; display: flex; align-items: center;">
                        <i id="status-db-icon" class="fa-solid fa-database" style="color: #60a5fa; width: 20px; transition: 0.3s;"></i> 
                        <span>Database: <strong id="status-db-text" style="color: #60a5fa; margin-left: 5px; transition: 0.3s;">Checking...</strong></span>
                    </div>
                    
                    <div style="font-size: 13px; color: #e2e8f0; display: flex; align-items: center;">
                        <i class="fa-solid fa-shield-halved" style="color: #fbbf24; width: 20px;"></i> 
                        <span>GeoFence: <strong style="color: #fbbf24; margin-left: 5px;">Active</strong></span>
                    </div>
                </div>

                <div class="sidebar-footer" style="padding: 20px 25px; background: rgba(0,0,0,0.25); border-top: 1px solid rgba(255,255,255,0.05);">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                        <i class="fa-solid fa-circle-user" style="font-size: 32px; color: #cbd5e1;"></i>
                        <div style="line-height: 1.2;">
                            <div style="color: #ffffff; font-size: 14px; font-weight: bold;"><?= $_SESSION['username'] ?? 'admin' ?></div>
                            <div style="color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">System Admin</div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="admin_settings.php" style="color: #cbd5e1; text-decoration: none; font-size: 13px; display: flex; align-items: center; transition: 0.2s;">
                            <i class="fa-solid fa-gear" style="width: 20px;"></i> System Settings
                        </a>
                        <a href="logout.php" style="color: #f87171; text-decoration: none; font-size: 13px; display: flex; align-items: center; transition: 0.2s;">
                            <i class="fa-solid fa-arrow-right-from-bracket" style="width: 20px;"></i> Secure Logout
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>

<script>
    function openAdminSidebar() {
        document.getElementById("adminSidebar").style.width = "300px";
        document.getElementById("sidebarOverlay").style.display = "block";
    }

    // --- ADVANCED SYSTEM HEALTH MONITOR ---
    function checkSystemHealth() {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000); // 3-second timeout

        // ADDED: Cache-busting parameter (?_t=...) and cache: 'no-store'
        fetch('health_check.php?_t=' + new Date().getTime(), { 
            signal: controller.signal,
            cache: 'no-store' 
        })
            .then(response => {
                if (!response.ok) throw new Error("Server response not OK");
                return response.json();
            })
            .then(data => {
                clearTimeout(timeoutId);
                
                // 1. Server is responding
                document.getElementById('status-server-icon').style.color = '#4ade80'; 
                document.getElementById('status-server-text').style.color = '#4ade80';
                document.getElementById('status-server-text').innerText = 'Online';

                // 2. Database Status
                if (data.database === 'Synced') {
                    document.getElementById('status-db-icon').style.color = '#60a5fa'; 
                    document.getElementById('status-db-text').style.color = '#60a5fa';
                    document.getElementById('status-db-text').innerText = 'Synced';
                } else {
                    document.getElementById('status-db-icon').style.color = '#ef4444'; 
                    document.getElementById('status-db-text').style.color = '#ef4444';
                    document.getElementById('status-db-text').innerText = 'Disconnected';
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                
                document.getElementById('status-server-icon').style.color = '#ef4444'; 
                document.getElementById('status-server-text').style.color = '#ef4444';
                document.getElementById('status-server-text').innerText = 'Offline';

                document.getElementById('status-db-icon').style.color = '#ef4444'; 
                document.getElementById('status-db-text').style.color = '#ef4444';
                document.getElementById('status-db-text').innerText = 'Disconnected';
            });
    }

    // Run it instantly when the sidebar loads, then ping every 10 seconds
    checkSystemHealth();
    setInterval(checkSystemHealth, 10000);

    function closeAdminSidebar() {
        document.getElementById("adminSidebar").style.width = "0";
        document.getElementById("sidebarOverlay").style.display = "none";
    }
</script>
</html>