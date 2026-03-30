<?php
session_start();
require_once 'config/connection.php';

// Turn on error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

try {
    // ACTION 1: FETCH ALL PINS
    if ($method === 'GET') {
        $sql = "SELECT p.*, u.username FROM tactical_pins p LEFT JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC";
        $result = $conn->query($sql);
        $pins = [];
        while ($row = $result->fetch_assoc()) {
            $pins[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $pins]);
        exit;
    }

    // ACTIONS 2, 3, 4: CREATE, UPDATE, DELETE
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';

        // CREATE
        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $color = $_POST['color'] ?? '#d32f2f';
            
            // Force these to be decimals so MySQL doesn't crash
            $lat = (float)($_POST['lat'] ?? 0);
            $lng = (float)($_POST['lng'] ?? 0);

            if (empty($title) || $lat === 0.0 || $lng === 0.0) {
                echo json_encode(['success' => false, 'message' => 'Missing data or invalid coordinates.']); 
                exit;
            }

            $stmt = $conn->prepare("INSERT INTO tactical_pins (user_id, title, color, lat, lng) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'SQL Prepare Error: ' . $conn->error]); 
                exit;
            }

            // i = integer, s = string, d = double (decimal)
            $stmt->bind_param("issdd", $user_id, $title, $color, $lat, $lng);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Pin saved.']);
            exit;
        }

        // UPDATE
        if ($action === 'update') {
            $pin_id = (int)($_POST['pin_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $color = $_POST['color'] ?? '#d32f2f';

            $check = $conn->prepare("SELECT user_id FROM tactical_pins WHERE id = ?");
            $check->bind_param("i", $pin_id);
            $check->execute();
            $res = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$res || ($res['user_id'] != $user_id && $role !== 'admin')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied.']); exit;
            }

            $stmt = $conn->prepare("UPDATE tactical_pins SET title = ?, color = ? WHERE id = ?");
            $stmt->bind_param("ssi", $title, $color, $pin_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Pin updated.']);
            exit;
        }

        // DELETE
        if ($action === 'delete') {
            $pin_id = (int)($_POST['pin_id'] ?? 0);

            $check = $conn->prepare("SELECT user_id FROM tactical_pins WHERE id = ?");
            $check->bind_param("i", $pin_id);
            $check->execute();
            $res = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$res || ($res['user_id'] != $user_id && $role !== 'admin')) {
                echo json_encode(['success' => false, 'message' => 'Permission denied.']); exit;
            }

            $stmt = $conn->prepare("DELETE FROM tactical_pins WHERE id = ?");
            $stmt->bind_param("i", $pin_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Pin deleted.']);
            exit;
        }
    }
} catch (Exception $e) {
    // If absolutely anything goes wrong with MySQL, it will catch it and print it here safely!
    echo json_encode(['success' => false, 'message' => 'Database Crash: ' . $e->getMessage()]);
    exit;
}
?>