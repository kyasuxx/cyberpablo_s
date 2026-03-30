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


<link rel="stylesheet" href="../assets/css/header.css">

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
        
        <a href="admin_panel.php" class="nav-item <?= $current_page == 'admin_panel.php' ? 'active' : '' ?>" style="color: #ffcc00;">
            Link Analysis
        </a>
    </nav>

    <div class="user-profile">
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($username) ?></span>
            <span class="user-role"><?= htmlspecialchars($role) ?></span>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</header>