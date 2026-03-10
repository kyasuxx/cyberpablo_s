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
            <div class="sidebar-inner">
                <div class="sidebar-header">
                    <h2>CyberPablo Admin</h2>
                    <button class="close-btn" onclick="closeAdminSidebar()">&#10005;</button>
                </div>

                <ul class="sidebar-menu">
                    
                    <li>
                        <a href="add_cases.php" class="<?= $current_page == 'add_cases.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-plus"></i>New Case Entry</span>
                            <!-- <span class="menu-desc">Manually encode a new cybercrime incident report.</span> -->
                        </a>
                    </li>
                    <li>
                        <a href="import_cases.php" class="<?= $current_page == 'import_cases.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-download"></i>Batch Import</span>
                            <!-- <span class="menu-desc">Upload Excel files to bulk update the database.</span> -->
                        </a>
                    </li>
                    <li>
                        <a href="users.php" class="<?= $current_page == 'users.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-people-arrows"></i>User Management</span>
                            <!-- <span class="menu-desc">Register investigators, reset passwords, or deactivate accounts.</span> -->
                        </a>
                    </li>
                    <li>
                        <a href="admin_panel.php" class="<?= $current_page == 'admin_panel.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-brain"></i>Link Analysis</span>
                            <!-- <span class="menu-desc">AI-driven Logic: Detects aliases and serial victims.</span> -->
                        </a>
                    </li>
                    <li>
                        <a href="admin_audit_log.php" class="<?= $current_page == 'admin_audit_log.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-regular fa-clipboard"></i>System Audit Logs</span>
                            <!-- <span class="menu-desc">Security Compliance: Review logins and data exports.</span> -->
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>

<script>
    function openAdminSidebar() {
        document.getElementById("adminSidebar").style.width = "300px";
        document.getElementById("sidebarOverlay").style.display = "block";
    }

    function closeAdminSidebar() {
        document.getElementById("adminSidebar").style.width = "0";
        document.getElementById("sidebarOverlay").style.display = "none";
    }
</script>
</html>

