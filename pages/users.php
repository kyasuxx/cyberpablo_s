<?php
session_start();
require_once 'config/connection.php';

// 1. Security Gatekeeper
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
    <style>
        /* Admin content standard styling */
        .content-header { background: white; padding: 20px 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .content-header h3 { margin: 0; color: #003366; font-size: 22px; }
        .content-body { padding: 30px; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h4 { margin-top: 0; color: #003366; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }

        /* Form Grid */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full-width { grid-column: 1 / -1; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 13px; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: sans-serif; }
        
        .btn-submit { background: #003366; color: white; border: none; padding: 12px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 14px; width: auto; display: inline-block; }
        .btn-submit:hover { background: #002244; }

        /* Modern Table */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        .data-table th, .data-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .data-table th { background-color: #f8f9fa; color: #333; font-weight: 600; text-transform: uppercase; font-size: 12px; }
        .data-table tr:hover { background-color: #fcfcfc; }

        /* Alerts */
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Role Badges */
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge.admin { background: #ffebee; color: #c62828; }
        .badge.investigator { background: #e8f5e9; color: #2e7d32; }
    </style>
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