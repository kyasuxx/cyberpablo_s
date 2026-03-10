<?php
session_start();
require_once 'config/connection.php';
require_once 'spatial_helper.php';

// Security check: Only Admins can run the audit
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Error: Only administrators can run the spatial audit.");
}

echo "<div style='font-family: Arial, sans-serif; padding: 40px; line-height: 1.6;'>";
echo "<h1 style='color: #003366; border-bottom: 2px solid #d94c23; padding-bottom: 10px;'>Geofence Data Integrity Audit</h1>";
echo "<p>Scanning the database for misplaced coordinates...</p>";
echo "<div style='background: #f8f9fa; padding: 20px; border: 1px solid #ccc; border-radius: 5px;'>";

$geojson_path = '../api/san_pablo_barangays.json'; 

$sql = "SELECT id, case_no, barangay, barangay_id, lat, lng FROM incidents WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat != 0 AND lng != 0";
$result = $conn->query($sql);

$fixed_count = 0;
$checked_count = 0;

while ($row = $result->fetch_assoc()) {
    $checked_count++;
    
    // Ask the math engine: Where is this pin ACTUALLY located?
    $true_brgy_name = getTrueBarangayFromGeoJSON($row['lat'], $row['lng'], $geojson_path);
    
    if ($true_brgy_name) {
        
        // SMART MATCHER: Account for "Brgy.", "Barangay", or just the plain name
        $search1 = $true_brgy_name;
        $search2 = "Brgy. " . $true_brgy_name;
        $search3 = "Barangay " . $true_brgy_name;
        
        // Find the database ID for the true geographic barangay
        $brgy_stmt = $conn->prepare("
            SELECT id, official_name 
            FROM barangays 
            WHERE official_name = ? OR alt_name = ? 
               OR official_name = ? OR official_name = ? 
            LIMIT 1
        ");
        $brgy_stmt->bind_param("ssss", $search1, $search1, $search2, $search3);
        $brgy_stmt->execute();
        $brgy_data = $brgy_stmt->get_result()->fetch_assoc();
        
        if ($brgy_data) {
            // IF THE DATABASE DOES NOT MATCH THE GEOGRAPHY, FIX IT!
            if ($brgy_data['id'] != $row['barangay_id']) {
                
                $update = $conn->prepare("UPDATE incidents SET barangay_id = ?, barangay = ? WHERE id = ?");
                $update->bind_param("isi", $brgy_data['id'], $brgy_data['official_name'], $row['id']);
                $update->execute();
                
                echo "<span style='color: #28a745;'>&#10004; Corrected <b>{$row['case_no']}</b></span>: Was listed as <i><del>{$row['barangay']}</del></i>, but map pin is physically inside <b>{$brgy_data['official_name']}</b>.<br>";
                
                $fixed_count++;
            }
        }
    }
}

echo "</div>";
echo "<h3 style='margin-top: 20px;'>Audit Complete.</h3>";
echo "<p>Total cases checked: <b>$checked_count</b></p>";
echo "<p>Total geographic errors corrected: <b>$fixed_count</b></p>";
echo "<br><a href='dashboard.php' style='background: #003366; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Return to Dashboard</a>";
echo "</div>";
?>