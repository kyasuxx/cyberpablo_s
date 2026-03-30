<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// 🔒 SECURITY FIX: Kick out standard investigators who try to type the URL
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$message = "";
$message_type = ""; // 'success' or 'error'

// 2. Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_username'])) {
    $new_user = trim($_POST['new_username']);
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];
    $role = $_POST['role'];
    $spp_id = trim($_POST['spp_doj_id']);

    // Validation 1: Passwords match
    if ($new_pass !== $conf_pass) {
        $message = "Error: Passwords do not match.";
        $message_type = "error";
    } else {
        // Validation 2: Check for duplicate username
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $new_user);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $message = "Error: Username '$new_user' is already taken.";
            $message_type = "error";
        } else {
            // Success: Hash and Insert
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, spp_doj_id) VALUES (?, ?, ?, ?)");
            $insert_stmt->bind_param("ssss", $new_user, $hashed_pass, $role, $spp_id);
            
            if ($insert_stmt->execute()) {
                $message = "Account for '$new_user' created successfully.";
                $message_type = "success";
            } else {
                $message = "Database Error: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Fetch Users
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management - CyberPablo</title>
    <link rel="stylesheet" href="../assets/css/users.css">
</head>
<body style="margin: 0; font-family: sans-serif; background: #f4f6f9;">

    <?php require_once 'header.php'; ?>

    <div class="admin-layout">
        
        <?php include 'admin_sidebar.php'; ?>

        <main class="admin-content">
            <header class="content-header">
                <h3>System User Management</h3>
            </header>

            <div class="content-body">
                
                <?php if ($message): ?>
                    <div class="alert <?= $message_type ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <h4>Register New Personnel</h4>
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Username / Officer ID</label>
                                <input type="text" name="new_username" required placeholder="e.g., jdelacruz">
                            </div>
                            
                            <div class="form-group">
                                <label>Department ID (SPP / DOJ Reference)</label>
                                <input type="text" name="spp_doj_id" placeholder="e.g., SPPD-INV-002">
                            </div>

                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="new_password" required placeholder="Create a strong password">
                            </div>

                            <div class="form-group">
                                <label>Confirm Password</label>
                                <input type="password" name="confirm_password" required placeholder="Re-type password">
                            </div>

                            <div class="form-group">
                                <label>System Role & Permissions</label>
                                <select name="role">
                                    <option value="investigator">Investigator (Read/Write Cases)</option>
                                    <option value="admin">System Admin (Full Access)</option>
                                </select>
                            </div>

                            <div class="form-group" style="display: flex; align-items: flex-end;">
                                <button type="submit" class="btn-submit">Create Account</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <h4>Active System Users</h4>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Access Level</th>
                                <th>Department ID</th>
                                <th>Account Created</th>
                                <th>Last Login</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($u = $users->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                <td>
                                    <span class="badge <?= $u['role'] === 'admin' ? 'admin' : 'investigator' ?>">
                                        <?= htmlspecialchars($u['role']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($u['spp_doj_id'] ?: 'Not Assigned') ?></td>
                                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <?php 
                                        echo $u['last_login'] ? date('M d, Y h:i A', strtotime($u['last_login'])) : 'Never logged in';
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>
    </div>

</body>
</html>