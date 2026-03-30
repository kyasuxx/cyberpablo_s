<?php
session_start();
require_once 'config/connection.php';

// 1. Strict Security Checks
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized Access.");
}

// 2. Check if OTP was successfully verified in this session
if (!isset($_SESSION['export_verified']) || $_SESSION['export_verified'] !== true) {
    die("Security Error: OTP Verification Required to export the database.");
}

// 3. Immediately unset the verification so they have to use a new OTP next time
unset($_SESSION['export_verified']);

// 4. Log the highly sensitive action
$audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'exported_database_sql', ?)");
$audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
$audit->execute();

// ==========================================
// 5. GENERATE THE SQL DUMP
// ==========================================
$tables = array();
$result = $conn->query("SHOW TABLES");
while($row = $result->fetch_row()){
    $tables[] = $row[0];
}

$sql_dump = "-- CyberPablo Database Backup\n";
$sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
$sql_dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach($tables as $table){
    $result = $conn->query("SELECT * FROM `$table`");
    $num_fields = $result->field_count;

    $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
    $sql_dump .= $row2[1] . ";\n\n";

    while($row = $result->fetch_row()){
        $sql_dump .= "INSERT INTO `$table` VALUES(";
        for($j=0; $j < $num_fields; $j++){
            if (!isset($row[$j])) {
                $sql_dump .= 'NULL';
            } else {
                $escaped = $conn->real_escape_string($row[$j]);
                $sql_dump .= "'" . $escaped . "'";
            }
            if ($j < ($num_fields-1)) { $sql_dump .= ','; }
        }
        $sql_dump .= ");\n";
    }
    $sql_dump .= "\n\n";
}
$sql_dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// ==========================================
// 6. FORCE BROWSER DOWNLOAD
// ==========================================
$filename = "CyberPablo_DB_Backup_" . date('Y-m-d_His') . ".sql";
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $sql_dump;
exit;
?>