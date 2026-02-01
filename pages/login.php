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
    <style>
        /* Reset and Base Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body {
            background-color: #f4f4f4;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Card Container */
        .login-card {
            background: white;
            width: 100%;
            max-width: 420px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            border-top: 5px solid #d94c23; /* The Orange Top Border */
            padding: 40px 30px 20px 30px;
            text-align: center;
        }

        /* Header Section */
        .logo-section {
            position: relative;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .main-logo {
            width: 80px; /* Adjust based on your actual logo */
            height: auto;
        }

        .admin-badge {
            background-color: #d94c23;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 10px;
            position: absolute;
            bottom: 0;
            right: -10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        h2 {
            font-family: 'Times New Roman', serif; /* Serif font for the Government look */
            font-size: 28px;
            color: #222;
            margin-bottom: 5px;
            font-weight: normal;
        }

        .subtitle {
            color: #d94c23;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 30px;
            text-transform: uppercase;
        }

        /* Form Styling */
        form { text-align: left; }

        label {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .input-group {
            position: relative;
            margin-bottom: 20px;
        }

        /* Placeholder for input icons (Shield/Key) */
        .input-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            opacity: 0.5;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 10px 10px 40px; /* Left padding for icon */
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        input:focus {
            border-color: #d94c23;
        }

        /* Button Styling */
        button {
            width: 100%;
            background-color: #000;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        button:hover {
            background-color: #333;
        }

        /* Warning Box */
        .warning-box {
            background-color: #fff8e1; /* Light yellow */
            border: 1px solid #f0e6c5;
            padding: 15px;
            font-size: 11px;
            color: #555;
            text-align: justify;
            margin-top: 25px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            line-height: 1.4;
        }

        .warning-icon {
            width: 20px;
            height: auto;
            flex-shrink: 0;
        }

        .error-msg {
            color: red;
            background: #ffe6e6;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo-section">
            <img src="assets/images/logo_placeholder.png" alt="Logo" class="main-logo" onerror="this.style.display='none'; document.getElementById('alt-logo').style.display='block';">
            <div id="alt-logo" style="display:none; font-size: 40px;">🛡️</div>
            
            <span class="admin-badge">ADMIN</span>
        </div>

        <h2>Department of Justice</h2>
        <p class="subtitle">Restricted Access // Log Monitored</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            
            <label for="username">Administrator ID</label>
            <div class="input-group">
                <img src="assets/images/shield_icon.png" class="input-icon" onerror="this.style.display='none'">
                <input type="text" id="username" name="username" placeholder="ADMIN-0000" required>
            </div>

            <label for="password">Passphrase</label>
            <div class="input-group">
                <img src="assets/images/key_icon.png" class="input-icon" onerror="this.style.display='none'">
                <input type="password" id="password" name="password" placeholder="**********" required>
            </div>

            

            <button type="submit">
                <span>🛡️</span> Authenticate Session
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