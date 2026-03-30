<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Manila');
$servername = "localhost";
$username = "root";
$password = "";
//database name "cyberpablo"
$dbname = "cyberpablo";
//create connection
$conn = new mysqli($servername, $username, $password, $dbname);

//check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

//set charset to utf8mb4
$conn->set_charset("utf8mb4");

// echo "Connected successfully to the database.";
?>