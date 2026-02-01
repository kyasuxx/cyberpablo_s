<?php
session_start();
require_once 'config/connection.php';

// Gatekeeper
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$message = "";

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_username'])) {
    $new_user = trim($_POST['new_username']);
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $spp_id = $_POST['spp_doj_id'];

    $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, spp_doj_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $new_user, $new_pass, $role, $spp_id);
    
    if ($stmt->execute()) {
        $message = "User created successfully!";
    } else {
        $message = "Error: " . $conn->error;
    }
}

// Fetch Users
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Management - CyberPablo</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f8f9fa; color: #003366; }
        .form-group { margin-bottom: 15px; }
        input, select { padding: 8px; width: 100%; box-sizing: border-box; margin-top: 5px; }
        .btn { background: #003366; color: white; padding: 10px 15px; border: none; cursor: pointer; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #003366; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_panel.php" class="back-link">← Back to Admin Panel</a>
        <h2>👥 User Management</h2>
        
        <?php if($message): ?><p style="color: green; font-weight: bold;"><?= $message ?></p><?php endif; ?>

        <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Add New User</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="new_username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label>SPP DOJ ID</label>
                    <input type="text" name="spp_doj_id" placeholder="e.g. SPPD-INV-002">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="investigator">Investigator</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button type="submit" class="btn">Create User</button>
            </form>
        </div>

        <h3>Existing Users</h3>
        <table>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>ID</th>
                <th>Last Login</th>
            </tr>
            <?php while($u = $users->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td>
                    <span style="padding: 3px 8px; border-radius: 10px; background: <?= $u['role']=='admin'?'#ffebee':'#e8f5e9' ?>; color: <?= $u['role']=='admin'?'#c62828':'#2e7d32' ?>;">
                        <?= $u['role'] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($u['spp_doj_id']) ?></td>
                <td><?= $u['last_login'] ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</body>
</html>