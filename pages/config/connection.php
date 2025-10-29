<?php
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