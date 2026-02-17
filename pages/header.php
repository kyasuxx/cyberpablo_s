<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current filename to highlight active link
$current_page = basename($_SERVER['PHP_SELF']);
$username = $_SESSION['username'] ?? 'Officer';
$role = $_SESSION['role'] ?? 'user';
?>

<?php if ($role === 'admin') { include 'admin_sidebar.php'; } ?>

<style>
    /* UNIVERSAL HEADER STYLES */
    .app-header {
        background-color: #003366; /* Official Dark Blue */
        border-top: 5px solid #d94c23; /* The Orange Accent from Login */
        color: white;
        padding: 0 30px;
        height: 70px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        position: relative;
        z-index: 1000;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    /* Left Side: Brand */
    .brand {
        display: flex;
        align-items: center;
        gap: 15px;
        font-size: 20px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .brand-logo {
        height: 40px;
        width: auto;
        border-radius: 50px;
    }

    /* Burger Menu Icon inside Brand */
    .burger-btn {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 5px;
        margin-right: 5px;
        transition: color 0.3s;
    }
    
    .burger-btn:hover {
        color: #d94c23; /* Hover orange */
    }

    /* Middle: Navigation */
    .nav-menu {
        display: flex;
        gap: 5px;
    }

    .nav-item {
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        padding: 8px 15px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .nav-item:hover {
        background-color: rgba(255,255,255,0.1);
        color: white;
    }

    .nav-item.active {
        background-color: #d94c23; /* Active Orange */
        color: white;
        font-weight: bold;
    }

    /* Right Side: User Profile */
    .user-profile {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .user-info {
        text-align: right;
        line-height: 1.2;
    }

    .user-name {
        display: block;
        font-weight: bold;
        font-size: 14px;
    }

    .user-role {
        display: block;
        font-size: 11px;
        opacity: 0.8;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .logout-btn {
        background-color: #d32f2f; /* Red Logout */
        color: white;
        text-decoration: none;
        padding: 8px 15px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: bold;
        transition: background 0.3s;
        border: 1px solid rgba(0,0,0,0.1);
    }

    .logout-btn:hover {
        background-color: #b71c1c;
    }
</style>

<header class="app-header">
    <div class="brand">
        <?php if ($role === 'admin'): ?>
            <button class="burger-btn" onclick="openAdminSidebar()">&#9776;</button>
        <?php endif; ?>

        <img src="../assets/images/logo.png" alt="Logo" class="brand-logo">
        CYBERPABLO
    </div>

    <nav class="nav-menu">
        <a href="dashboard.php" class="nav-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            Dashboard
        </a>
        <a href="cases.php" class="nav-item <?= $current_page == 'cases.php' ? 'active' : '' ?>">
            Cases
        </a>
        
        <?php if ($role === 'admin'): ?>
            <a href="admin_panel.php" class="nav-item <?= $current_page == 'admin_panel.php' ? 'active' : '' ?>" style="color: #ffcc00;">
                Admin
            </a>
        <?php endif; ?>
    </nav>

    <div class="user-profile">
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($username) ?></span>
            <span class="user-role"><?= htmlspecialchars($role) ?></span>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</header>