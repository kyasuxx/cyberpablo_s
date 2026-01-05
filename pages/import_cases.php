<?php
session_start();
require_once 'config/connection.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$upload_message = '';
$upload_status = '';
$preview_data = [];
$errors = [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    require_once '../vendor/autoload.php'; // PhpSpreadsheet

    $file = $_FILES['excel_file'];
    $allowed = ['xlsx', 'xls', 'csv'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        $upload_status = 'error';
        $upload_message = 'Invalid file type. Only Excel (.xlsx, .xls) or CSV files allowed.';
    } elseif ($file['size'] > 10 * 1024 * 1024) {
        $upload_status = 'error';
        $upload_message = 'File too large. Maximum 10MB allowed.';
    } else {
        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Validate headers
            $header_aliases = [
                'case_no' => ['case no', 'case number'],
                'incident_type' => ['incident type', 'type of incident'],
                'barangay' => ['barangay', 'brgy'],
                'incident_date' => ['incident date', 'date of incident'],
                'modus_operandi' => ['modus operandi', 'method'],
                'status' => ['status'],
                'accused' => ['accused', 'suspect'],
                'accused_address' => ['accused address', 'suspect address'],
                'accused_contact' => ['accused contact', 'suspect contact'],
                'complainant' => ['complainant', 'victim'],
                'complainant_address' => ['complainant address', 'victim address'],
                'complainant_contact' => ['complainant contact', 'victim contact'],
                'nps_docket' => ['nps docket', 'docket number', 'nps docket number'],
                'offense_crime' => ['offense crime', 'offense', 'crime', 'offense/crime', 'crime type'],
                'date_committed' => ['date committed', 'crime date'],
                'date_filed' => ['date filed', 'filed date'],
                'bail_recommended' => ['bail recommended', 'bail', 'bail amount'],
                'prosecutor' => ['prosecutor', 'assigned prosecutor'],
                'received_by' => ['received by'],
                'received_date' => ['received date'],
                'returned_to' => ['returned to'],
                'returned_date' => ['returned date'],
                'evidence_notes' => ['evidence notes', 'notes', 'evidence'],
                'attachments' => ['attachments', 'files', 'filenames', 'attachment filenames'] // <-- FIX 1: UNCOMMENTED
            ];

            // Normalize headers
            $headers_raw = array_map('trim', $rows[0]);
            $headers_normalized = [];
            foreach ($headers_raw as $header) {
                $normalized = strtolower($header);
                $normalized = str_replace(['_', '-', '.', '/', '\\'], ' ', $normalized); // Added / and \
                $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize multiple spaces to one
                $normalized = trim($normalized);

                $matched_key = null;
                foreach ($header_aliases as $expected => $aliases) {
                    if (in_array($normalized, $aliases)) {
                        $matched_key = $expected;
                        break;
                    }
                }
                $headers_normalized[] = $matched_key ?? str_replace(' ', '_', $normalized);
            }

            $headers = $headers_normalized;

            // Validate all required headers exist
            $required_keys = ['case_no', 'incident_type', 'barangay', 'incident_date', 'status'];
            $missing_headers = [];
            foreach ($required_keys as $r_key) {
                if (!in_array($r_key, $headers)) {
                    $missing_headers[] = $r_key;
                }
            }
            
            if (!empty($missing_headers)) {
                $upload_status = 'error';
                $upload_message = 'Missing required columns: ' . implode(', ', $missing_headers);
            } else {
                // Preview first 10 rows
                $preview_count = min(10, count($rows) - 1);
                for ($i = 1; $i <= $preview_count; $i++) {
                    // Prevent row mismatch
                    if (count($headers) != count($rows[$i])) {
                        $errors[] = "Row " . ($i + 1) . ": Column count mismatch. Expected " . count($headers) . " but got " . count($rows[$i]) . ". Skipping row.";
                        continue;
                    }
                    $row_data = @array_combine($headers, $rows[$i]);
                    if (!$row_data) continue;

                    // Validate barangay exists
                    $barangay_input = trim($row_data['barangay']);
                    $clean = preg_replace('/^Brgy\.?\s*/i', '', $barangay_input);

                    $brgy_check = $conn->prepare("
                        SELECT id, official_name, lat, lng FROM barangays 
                        WHERE official_name = ? OR alt_name = ? 
                        OR official_name = ? OR alt_name = ? 
                        LIMIT 1
                    ");
                    $brgy_check->bind_param("ssss", $barangay_input, $barangay_input, $clean, $clean);
                    $brgy_check->execute();
                    $brgy_result = $brgy_check->get_result();

                    if ($brgy_result->num_rows === 0) {
                        $errors[] = "Row $i: Barangay '{$row_data['barangay']}' not found in database";
                    }

                    // Check duplicate case number (but allow updates)
                    $dup_check = $conn->prepare("SELECT case_no FROM incidents WHERE case_no = ?");
                    $dup_check->bind_param("s", $row_data['case_no']);
                    $dup_check->execute();

                    if ($dup_check->get_result()->num_rows > 0) {
                        $row_data['existing_case'] = true;
                    } else {
                        $row_data['existing_case'] = false;
                    }

                    // FIX 2: Add "Not Listed" for preview
                    $row_data['incident_type'] = trim($row_data['incident_type'] ?? '');
                    if ($row_data['incident_type'] === '') {
                        $row_data['incident_type'] = 'Not Listed';
                    }
                    $preview_data[] = $row_data;
                }

                if (empty($errors)) {
                    $upload_status = 'success';
                    $upload_message = 'File validated successfully. ' . (count($rows) - 1) . ' rows ready to import.';
                } else {
                    $upload_status = 'warning';
                    $upload_message = 'Some rows have warnings (e.g., missing barangays). Duplicates will be updated automatically.';
                }

                $_SESSION['pending_import_rows'] = $rows;
            }
        } catch (Exception $e) {
            $upload_status = 'error';
            $upload_message = 'Error reading file: ' . $e->getMessage();
        }
    }
}

// Handle final import confirmation
if (isset($_POST['confirm_import']) && isset($_SESSION['pending_import_rows'])) {
    $rows = $_SESSION['pending_import_rows'];
    $header_aliases = [
        'case_no' => ['case no', 'case number'],
        'incident_type' => ['incident type', 'type of incident'],
        'barangay' => ['barangay', 'brgy'],
        'incident_date' => ['incident date', 'date of incident'],
        'modus_operandi' => ['modus operandi', 'method'],
        'status' => ['status'],
        'accused' => ['accused', 'suspect'],
        'accused_address' => ['accused address', 'suspect address'],
        'accused_contact' => ['accused contact', 'suspect contact'],
        'complainant' => ['complainant', 'victim'],
        'complainant_address' => ['complainant address', 'victim address'],
        'complainant_contact' => ['complainant contact', 'victim contact'],
        'nps_docket' => ['nps docket', 'docket number', 'nps docket number'],
        'offense_crime' => ['offense crime', 'offense', 'crime', 'offense/crime', 'crime type'],
        'date_committed' => ['date committed', 'crime date'],
        'date_filed' => ['date filed', 'filed date'],
        'bail_recommended' => ['bail recommended', 'bail', 'bail amount'],
        'prosecutor' => ['prosecutor', 'assigned prosecutor'],
        'received_by' => ['received by'],
        'received_date' => ['received date'],
        'returned_to' => ['returned to'],
        'returned_date' => ['returned date'],
        'evidence_notes' => ['evidence notes', 'notes', 'evidence'],
        'attachments' => ['attachments', 'files', 'filenames', 'attachment filenames'] // <-- FIX 1: UNCOMMENTED
    ];

    $headers_raw = array_map('trim', $rows[0]);
    $headers = [];
    foreach ($headers_raw as $header) {
        $normalized = strtolower($header);
        $normalized = str_replace(['_', '-', '.', '/', '\\'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        $matched_key = null;
        foreach ($header_aliases as $expected => $aliases) {
            if (in_array($normalized, $aliases)) {
                $matched_key = $expected;
                break;
            }
        }
        $headers[] = $matched_key ?? str_replace(' ', '_', $normalized);
    }


    $imported = 0;
    $skipped = 0;

    $conn->begin_transaction();

    try {
        // Helper function to clean dates during import
            function safeImportDate($dateString, $format = 'Y-m-d') {
                if (empty($dateString) || str_contains($dateString, '0000-00-00') || str_contains($dateString, '0001')) {
                    return null; // Set to NULL if it's empty, a zero date, or the bad -0001 date
                }
                $timestamp = strtotime($dateString);
                if ($timestamp === false || $timestamp <= 0) {
                    return null; // Set to NULL if it's an invalid date (like "N/A" or "test")
                }
                return date($format, $timestamp);
            }
        for ($i = 1; $i < count($rows); $i++) {
            // Prevent row mismatch
            if (count($headers) != count($rows[$i])) {
                continue; // Skip mismatched row
            }
            $row_data = @array_combine($headers, $rows[$i]);
            if (!$row_data) continue;

            // Get barangay ID
            $barangay_input = trim($row_data['barangay']);
            $clean = preg_replace('/^Brgy\.?\s*/i', '', $barangay_input);

            $brgy_stmt = $conn->prepare("
                SELECT id, official_name, lat, lng FROM barangays 
                WHERE official_name = ? OR alt_name = ? 
                OR official_name = ? OR alt_name = ? 
                LIMIT 1
            ");
            $brgy_stmt->bind_param("ssss", $barangay_input, $barangay_input, $clean, $clean);
            $brgy_stmt->execute();
            $brgy = $brgy_stmt->get_result()->fetch_assoc();

            if (!$brgy) {
                $skipped++;
                continue;
            }

            $prosecutor_id = null;
            if (!empty($row_data['prosecutor'])) {
                $p = trim($row_data['prosecutor']);
                $pcheck = $conn->prepare("SELECT id FROM prosecutors WHERE full_name = ?");
                $pcheck->bind_param("s", $p);
                $pcheck->execute();
                $pid = $pcheck->get_result()->fetch_row()[0] ?? null;

                if (!$pid && !empty($p)) { // Only insert if not empty
                    $insert_p = $conn->prepare("INSERT INTO prosecutors (full_name) VALUES (?)");
                    $insert_p->bind_param("s", $p);
                    $insert_p->execute();
                    $prosecutor_id = $conn->insert_id;
                } else {
                    $prosecutor_id = $pid;
                }
            }

            $victim_hash = hash('sha256', ($row_data['complainant'] ?? '') . time());

            $insert = $conn->prepare("
                INSERT INTO incidents (
                    case_no, incident_type, barangay, barangay_id, lat, lng, incident_date,
                    modus_operandi, hashed_victim_id, status, 
                    accused, accused_address, accused_contact,
                    complainant, complainant_address, complainant_contact,
                    nps_docket, offense_crime, date_committed, date_filed, 
                    bail_recommended, prosecutor_id, 
                    received_by, received_date, returned_to, returned_date,
                    evidence_notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    incident_type = VALUES(incident_type),
                    barangay = VALUES(barangay),
                    barangay_id = VALUES(barangay_id),
                    lat = VALUES(lat), 
                    lng = VALUES(lng),
                    incident_date = VALUES(incident_date),
                    modus_operandi = VALUES(modus_operandi),
                    status = VALUES(status),
                    accused = VALUES(accused),
                    accused_address = VALUES(accused_address),
                    accused_contact = VALUES(accused_contact),
                    complainant = VALUES(complainant),
                    complainant_address = VALUES(complainant_address),
                    complainant_contact = VALUES(complainant_contact),
                    nps_docket = VALUES(nps_docket),
                    offense_crime = VALUES(offense_crime),
                    date_committed = VALUES(date_committed),
                    date_filed = VALUES(date_filed),
                    bail_recommended = VALUES(bail_recommended),
                    prosecutor_id = VALUES(prosecutor_id),
                    received_by = VALUES(received_by),
                    received_date = VALUES(received_date),
                    returned_to = VALUES(returned_to),
                    returned_date = VALUES(returned_date),
                    evidence_notes = VALUES(evidence_notes),
                    updated_at = NOW()
            ");

            // Handle nullable date fields
// Handle nullable date fields

            

            $incident_date = safeImportDate($row_data['incident_date'] ?? '', 'Y-m-d');
            if ($incident_date === null) {
                // If the date is invalid or blank, default to today's date to satisfy NOT NULL
                $incident_date = date('Y-m-d'); 
            }
            $date_committed = safeImportDate($row_data['date_committed'] ?? '', 'Y-m-d H:i:s');
            $date_filed = safeImportDate($row_data['date_filed'] ?? '', 'Y-m-d');
            $received_date = safeImportDate($row_data['received_date'] ?? '', 'Y-m-d H:i:s');
            $returned_date = safeImportDate($row_data['returned_date'] ?? '', 'Y-m-d H:i:s');
            $bail = (!empty($row_data['bail_recommended']) && is_numeric($row_data['bail_recommended'])) 
                ? floatval($row_data['bail_recommended']) : null;

            // Handle optional text fields - MUST BE VARIABLES for bind_param
            $accused = $row_data['accused'] ?? '';
            $accused_address = $row_data['accused_address'] ?? '';
            $accused_contact = $row_data['accused_contact'] ?? '';
            $complainant = $row_data['complainant'] ?? '';
            $complainant_address = $row_data['complainant_address'] ?? '';
            $complainant_contact = $row_data['complainant_contact'] ?? '';
            
            // FIX 2: Add "Not Listed" for import
            $incident_type = trim($row_data['incident_type'] ?? '');
            if ($incident_type === '') {
                $incident_type = 'Not Listed';
            }
            $modus = $row_data['modus_operandi'] ?? '';
            $status = $row_data['status'] ?? 'Open'; // Default to Open
            $nps_docket = $row_data['nps_docket'] ?? '';
            $offense_crime = $row_data['offense_crime'] ?? '';
            $received_by = $row_data['received_by'] ?? '';
            $returned_to = $row_data['returned_to'] ?? '';
            $evidence_notes = $row_data['evidence_notes'] ?? '';

            $insert->bind_param(
                "ssisddssssssssssssssdisssss",
                $row_data['case_no'],
                $incident_type,
                $brgy['official_name'],
                $brgy['id'],
                $brgy['lat'],
                $brgy['lng'],
                $incident_date,
                $modus,
                $victim_hash,
                $status,
                $accused,
                $accused_address,
                $accused_contact,
                $complainant,
                $complainant_address,
                $complainant_contact,
                $nps_docket,
                $offense_crime,
                $date_committed,
                $date_filed,
                $bail,
                $prosecutor_id,
                $received_by,
                $received_date,
                $returned_to,
                $returned_date,
                $evidence_notes
            );

            if ($insert->execute()) {
                $imported++;

                // --- START: NEW ATTACHMENT CODE ---
                
                // 1. Get the incident_id
                $incident_id = $conn->insert_id;
                if ($incident_id == 0) {
                    // It was an UPDATE, so we must fetch the ID
                    $id_stmt = $conn->prepare("SELECT id FROM incidents WHERE case_no = ?");
                    $id_stmt->bind_param("s", $row_data['case_no']);
                    $id_stmt->execute();
                    $incident_id = $id_stmt->get_result()->fetch_row()[0];
                }
                
            // 2. Process the 'attachments' column if it's not empty
                if ($incident_id && !empty($row_data['attachments'])) {
                    $case_no = $row_data['case_no'];

                    // FIX: Build a reliable server path
                    $server_path_dir = $_SERVER['DOCUMENT_ROOT'] . "/cyberpablo/uploads/cases/" . $case_no . "/";
                    $server_path_dir = str_replace('/', DIRECTORY_SEPARATOR, $server_path_dir);

                    // This is the WEB path (what's saved in the DB and used in <a> tags)
                    $web_path_dir = "../uploads/cases/" . $case_no . "/";

                    // Create the directory if it doesn't exist using the SERVER path
                    if (!is_dir($server_path_dir)) {
                        mkdir($server_path_dir, 0755, true);
                    }

                    // Split filenames by comma
                    $filenames_from_excel = explode(',', $row_data['attachments']);

                    foreach ($filenames_from_excel as $original_filename) {
                        $original_filename = trim($original_filename);
                        if (empty($original_filename)) continue;

                        // --- THIS IS THE NEW ROBUST LOGIC ---
                        
                        $file_to_add_server_path = null;
                        $file_to_add_web_path = null;
                        $file_to_add_filename = null;
                        
                        // Scan the directory for all files
                        $all_files_in_dir = glob($server_path_dir . "*");

                        if ($all_files_in_dir) {
                            foreach ($all_files_in_dir as $found_filepath) {
                                // Make sure it's a file, not a directory
                                if (!is_file($found_filepath)) {
                                    continue;
                                }

                                $found_filename = basename($found_filepath);

                                // Check 1: Is it an exact match?
                                if ($found_filename === $original_filename) {
                                    $file_to_add_server_path = $found_filepath;
                                    $file_to_add_filename = $found_filename;
                                    $file_to_add_web_path = $web_path_dir . $found_filename;
                                    break; // Found it, stop looking
                                }

                                // Check 2: Is it a renamed match? (e.g., 12345_original.jpg)
                                // This is the logic that handles your observation
                                if (str_ends_with($found_filename, "_" . $original_filename)) {
                                    $file_to_add_server_path = $found_filepath;
                                    $file_to_add_filename = $found_filename;
                                    $file_to_add_web_path = $web_path_dir . $found_filename;
                                    break; // Found it, stop looking
                                }
                            }
                        }

                        // If we found a file (either original OR renamed), add it to the database
                        if ($file_to_add_filename) {
                            
                            // PREVENT DUPLICATES: Check if this file is already linked
                            $dup_att_stmt = $conn->prepare("SELECT id FROM attachments WHERE incident_id = ? AND file_name = ?");
                            $dup_att_stmt->bind_param("is", $incident_id, $file_to_add_filename); 
                            $dup_att_stmt->execute();
                            $dup_result = $dup_att_stmt->get_result();

                            if ($dup_result->num_rows == 0) {
                                // File exists but isn't in DB, so insert the record
                                $att_stmt = $conn->prepare(
                                    "INSERT INTO attachments (incident_id, file_name, file_path, uploaded_by) 
                                     VALUES (?, ?, ?, ?)"
                                );
                                // Use the *actual* filename and web path we found
                                $att_stmt->bind_param("issi", $incident_id, $file_to_add_filename, $file_to_add_web_path, $_SESSION['user_id']); 
                                $att_stmt->execute();
                                $att_stmt->close();
                            }
                            $dup_att_stmt->close();
                        }
                        // --- END OF NEW LOGIC ---
                    }
                }
            } else {
                $skipped++;
            }
        }

        $conn->commit();

        $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'excel_import', ?)");
        $audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
        $audit->execute();

        $upload_status = 'success';
        $upload_message = "Import complete! $imported records imported, $skipped skipped.";

        unset($_SESSION['pending_import_rows']);
    } catch (Exception $e) {
        $conn->rollback();
        $upload_status = 'error';
        $upload_message = 'Import failed: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Excel - CyberPablo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; }
        
        .header {
            background: linear-gradient(135deg, #003366 0%, #004d99 100%);
            color: white;
            padding: 20px 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 { font-size: 28px; font-weight: 600; }
        
        .nav {
            margin-top: 10px;
            display: flex;
            gap: 20px;
        }
        
        .nav a {
            color: #ffcc00;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav a:hover { color: #ffd700; }
        
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .card h2 {
            color: #003366;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .upload-area {
            border: 3px dashed #ccc;
            border-radius: 12px;
            padding: 60px;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: #003366;
            background: #f0f8ff;
        }
        
        .upload-area.dragover {
            border-color: #28a745;
            background: #e8f5e9;
        }
        
        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        input[type="file"] { display: none; }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #003366;
            color: white;
        }
        
        .btn-primary:hover {
            background: #004d99;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,51,102,0.3);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #003366;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .error-list {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .error-list li {
            color: #856404;
            margin: 5px 0;
        }
        
        .template-download {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s;
        }
        
        .template-download:hover {
            background: #218838;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CYBERPABLO - Excel Import</h1>
        <div class="nav">
            <a href="dashboard.php">← Back to Map</a>
            <a href="cases.php">Cases</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if ($upload_message): ?>
            <div class="alert alert-<?= $upload_status ?>">
                <strong><?= ucfirst($upload_status) ?>:</strong> <?= $upload_message ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="card">
                <h2>⚠️ Validation Errors</h2>
                <div class="error-list">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Upload Excel File</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Upload an Excel (.xlsx, .xls) or CSV file containing cybercrime incident data.
                The file will be validated before import.
            </p>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                    <div class="upload-icon">📁</div>
                    <h3>Click to select file or drag & drop</h3>
                    <p style="color: #999; margin-top: 10px;">Supported: .xlsx, .xls, .csv (Max 10MB)</p>
                </div>
                <input type="file" name="excel_file" id="fileInput" accept=".xlsx,.xls,.csv" required>
                
                <div style="margin-top: 20px; text-align: center;">
                    <button type="submit" class="btn btn-primary">Validate & Preview</button>
                </div>
            </form>

            <a href="download_template.php" class="template-download">⬇Download Excel Template</a>
        </div>

        <?php if (!empty($preview_data)): ?>
            <div class="card">
                <h2>Preview (First 10 Rows)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Case No</th>
                            <th>Type</th>
                            <th>Barangay</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['case_no']) ?></td>
                                <td><?= htmlspecialchars($row['incident_type']) ?></td>
                                <td><?= htmlspecialchars($row['barangay']) ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['incident_date']))) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                    <?php if (empty($errors) || $upload_status === 'warning'): ?>
                        <form method="POST" style="margin-top: 20px; text-align: center;">
                            <button type="submit" name="confirm_import" class="btn btn-success">
                                Confirm & Import All Records
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                Cancel
                            </button>
                        </form>
                    <?php endif; ?>

            </div>
        <?php endif; ?>
    </div>

    <script>
        // Drag & drop functionality
        const uploadArea = document.querySelector('.upload-area');
        const fileInput = document.getElementById('fileInput');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.remove('dragover');
            }, false);
        });

        uploadArea.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            document.getElementById('uploadForm').submit();
        }, false);

        // Show selected filename
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                uploadArea.querySelector('h3').textContent = `Selected: ${fileName}`;
            }
        });
    </script>
</body>
</html>