<?php
// login.php
session_start();
require_once 'config/connection.php';  // ← Adjust path if needed



$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Debug: Check if user exists
    $check = $conn->query("SELECT COUNT(*) FROM users WHERE username = '$username'");
    if ($check->fetch_row()[0] == 0) {
        $error = "User not found!";
    } else {
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

                $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Wrong password!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/scss/main.css">
    <title>CyberPablo Login</title>
    <style>
    
        
        
    </style>
</head>
<body>
    <main class="login">
        <div class="box">
            <h2>CyberPablo</h2>
            <p><strong>San Pablo City Cybercrime System</strong></p>

            <?php if ($error): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Login</button>
            </form>

            <p><small>Test: <b>admin</b> / <b>ChangeMe123!</b></small></p>
        </div>
    </main>
</body>
</html>