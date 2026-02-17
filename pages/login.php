<?php
// login.php
session_start();
require_once 'config/connection.php';  // ← Adjust path if needed

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    if (!$stmt) {
        $error = "Prepare failed: " . $conn->error;
    } else {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Securely update the last_login timestamp
            $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();

            // Phase 2: Role-based redirect
            if ($user['role'] === 'admin') {
                header("Location: admin_panel.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            $error = "Wrong password!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CyberPablo Login</title>
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>

    <div class="login-card">
        <div class="logo-section">
            <img src="assets/images/logo_placeholder.png" alt="Logo" class="main-logo" 
                 onerror="this.style.display='none'; document.getElementById('alt-logo').style.display='block';">
            <div id="alt-logo"><img src="../assets/images/logo.png" alt=""></div>
            
            <span class="admin-badge">CYBERPABLO</span>
        </div>

        <h2>Department of Justice</h2>
        <p class="subtitle">Restricted Access // Log Monitored</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="username">Username</label>
            <div class="input-group">
                <img src="assets/images/shield_icon.png" class="input-icon" onerror="this.style.display='none'">
                <input type="text" id="username" name="username" placeholder="Username" required>
            </div>

            <label for="password">Password</label>
            <div class="input-group">
                <img src="assets/images/key_icon.png" class="input-icon" onerror="this.style.display='none'">
                <input type="password" id="password" name="password" placeholder="**********" required>
            </div>

            <button type="submit">
                <span></span>Log In
            </button>
        </form>

        <div class="warning-box">
            <img src="assets/images/warning_icon.png" class="warning-icon" alt="!" onerror="this.style.display='none'"> 
            <div>
                <strong>ADMINISTRATIVE WARNING:</strong> You are accessing a protected government information system. Unauthorized access or attempts to modify data are punishable by law under Republic Act No. 10175 (Cybercrime Prevention Act of 2012).
            </div>
        </div>
    </div>

</body>
</html>