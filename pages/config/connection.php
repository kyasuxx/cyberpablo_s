<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Manila');

// Load environment variables
require_once __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cyberpablo";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
