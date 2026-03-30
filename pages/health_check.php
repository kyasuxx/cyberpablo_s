<?php
// health_check.php
header('Content-Type: application/json');

// 1. Suppress warnings
error_reporting(0); 

// 2. Start an Output Buffer. This catches any "Connection Failed" 
// errors from connection.php so they don't break our JSON format.
ob_start(); 

require_once 'config/connection.php';

// 3. Throw away any errors that got caught in the buffer
$junk = ob_get_clean(); 

$response = [
    'server' => 'Online', // If this file executes, Apache is alive!
    'database' => 'Offline'
];

// 4. Safely test if the connection object exists and is talking
if (isset($conn) && $conn->ping()) {
    $response['database'] = 'Synced';
}

echo json_encode($response);
exit;
?>