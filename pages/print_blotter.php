<?php
session_start();
require_once 'config/connection.php';

// 1. SECURITY: Strict Access Control
if (!isset($_SESSION['user_id'])) {
    die("UNAUTHORIZED ACCESS: Please log in.");
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 2. DATA RETRIEVAL: Join tables to get Barangay and Encoder details
$sql = "SELECT i.*, b.official_name as barangay_name, u.username as encoder 
        FROM incidents i 
        LEFT JOIN barangays b ON i.barangay_id = b.id 
        LEFT JOIN users u ON i.created_at = u.created_at 
        WHERE i.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$case = $result->fetch_assoc();
$log_sql = "SELECT cl.*, u.username 
            FROM case_logs cl 
            LEFT JOIN users u ON cl.user_id = u.id 
            WHERE cl.incident_id = ? 
            ORDER BY cl.created_at ASC";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("i", $id);
$log_stmt->execute();
$logs = $log_stmt->get_result();

if (!$case) die("ERROR: Case record not found.");

// 3. THE "LEGAL ENGINE": Converting raw data into Court-Ready Narrative
// PHP functions to ensure standard format (e.g., Names must be UPPERCASE)
$date_reported_full = date('F j, Y', strtotime($case['created_at'])); // e.g., October 24, 2025
$time_reported = date('H:i', strtotime($case['created_at']));
$incident_date_full = date('F j, Y', strtotime($case['incident_date']));
$incident_time = date('H:i', strtotime($case['incident_date']));

$complainant = strtoupper($case['complainant']);
$respondent = $case['accused'] ? strtoupper($case['accused']) : "A CERTAIN UNIDENTIFIED SUSPECT";
$crime = strtoupper($case['incident_type']);
$location = strtoupper($case['barangay_name'] ?: "UNKNOWN LOCATION");
$details = $case['modus_operandi'];

// Constructing the "Standard PNP Blotter" Paragraph
$narrative = "On <strong>$date_reported_full</strong> at approximately <strong>$time_reported</strong>H, ";
$narrative .= "one <strong>$complainant</strong>, of legal age, and a resident of {$case['complainant_address']}, ";
$narrative .= "personally appeared at this Office to report an alleged violation of <strong>RA 10175 ($crime)</strong>. ";
$narrative .= "<br><br>";
$narrative .= "The complainant states that on or about <strong>$incident_date_full</strong>, at or near <strong>$location</strong>, San Pablo City, ";
$narrative .= "the respondent, identified as <strong>$respondent</strong>, did then and there willfully, unlawfully, and feloniously ";
$narrative .= "commit acts constituting $crime. ";
$narrative .= "<br><br>";
$narrative .= "<strong>DETAILS OF INCIDENT:</strong><br>" . nl2br($details);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Blotter Entry No. <?= $case['case_no'] ?></title>
    <link rel="stylesheet" href="../assets/css/print_blotter.css">
</head>
<body>

    <button onclick="window.print()" class="no-print print-btn">PRINT OFFICIAL COPY</button>

    <div class="header">
        <p>Republic of the Philippines</p>
        <p>National Police Commission</p>
        <h3>PHILIPPINE NATIONAL POLICE</h3>
        <h4>SAN PABLO CITY POLICE STATION</h4>
        <p>San Pablo City, Laguna</p>
    </div>

    <div class="title">INCIDENT RECORD FORM</div>

    <table class="meta-box">
        <tr>
            <td class="label">ENTRY NUMBER:</td>
            <td><strong><?= $case['case_no'] ?></strong></td>
        </tr>
        <tr>
            <td class="label">DATE REPORTED:</td>
            <td><?= strtoupper($date_reported_full) ?> at <?= $time_reported ?>H</td>
        </tr>
        <tr>
            <td class="label">INCIDENT TYPE:</td>
            <td><?= strtoupper($case['incident_type']) ?></td>
        </tr>
        <tr>
            <td class="label">PLACE OF INCIDENT:</td>
            <td><?= $location ?></td>
        </tr>
    </table>

    <div class="narrative">
        <h4 style="margin-bottom: 10px; text-decoration: underline;">NARRATIVE OF EVENTS:</h4>
        <?= $narrative ?>
    </div>

    <?php if ($logs->num_rows > 0): ?>
    <div style="margin-top: 30px; margin-bottom: 30px;">
        <h4 style="margin-bottom: 10px; text-decoration: underline;">INVESTIGATION UPDATES:</h4>
        <table style="width: 100%; border-collapse: collapse; font-size: 11pt;">
            <thead>
                <tr style="border-bottom: 2px solid #000;">
                    <th style="text-align: left; width: 20%; padding: 5px;">Date/Time</th>
                    <th style="text-align: left; width: 20%; padding: 5px;">Officer</th>
                    <th style="text-align: left; width: 15%; padding: 5px;">Type</th>
                    <th style="text-align: left; width: 45%; padding: 5px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($log = $logs->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #ccc;">
                    <td style="padding: 5px; vertical-align: top;">
                        <?= date('M d, Y H:i', strtotime($log['created_at'])) ?>
                    </td>
                    <td style="padding: 5px; vertical-align: top;">
                        <?= strtoupper($log['username']) ?>
                    </td>
                    <td style="padding: 5px; vertical-align: top; font-weight: bold;">
                        <?= strtoupper($log['log_type']) ?>
                    </td>
                    <td style="padding: 5px; vertical-align: top;">
                        <?= nl2br(htmlspecialchars($log['details'])) ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>



    <p style="text-align: justify; font-size: 11pt; margin-bottom: 40px;">
        I HEREBY CERTIFY that the foregoing narrative is true and correct to the best of my knowledge and belief.
    </p>

    <div class="signatures">
        <div class="sig-block">
            <div class="line"></div>
            <strong><?= strtoupper($case['complainant']) ?></strong><br>
            Complainant / Affiant
        </div>
        <div class="sig-block">
            <div class="line"></div>
            <strong>DUTY OFFICER</strong><br>
            Cybercrime Desk Officer
        </div>
    </div>

    <div class="footer">
        System Generated by CyberPablo | Printed by User: <?= strtoupper($_SESSION['username']) ?> | Timestamp: <?= date('Y-m-d H:i:s') ?>
        <br>Note: This document is an official system record. Unauthorized alteration is punishable by law.
    </div>

</body>
</html>