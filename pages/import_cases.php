<?php
session_start();
require_once 'config/connection.php';
require_once 'spatial_helper.php';

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
$duplicate_count_preview = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    require_once '../vendor/autoload.php';

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

            // Validate headers (Added Latitude and Longitude aliases!)
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
                'latitude' => ['latitude', 'lat'],
                'longitude' => ['longitude', 'lng', 'long'],
                'attachments' => ['attachments', 'files', 'filenames', 'attachment filenames']
            ];

            $headers_raw = array_map('trim', $rows[0]);
            $headers_normalized = [];
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
                $headers_normalized[] = $matched_key ?? str_replace(' ', '_', $normalized);
            }

            $headers = $headers_normalized;

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
                $preview_count = min(10, count($rows) - 1);
                for ($i = 1; $i <= $preview_count; $i++) {
                    if (count($headers) != count($rows[$i])) {
                        $errors[] = "Row " . ($i + 1) . ": Column count mismatch. Skipping row.";
                        continue;
                    }
                    $row_data = @array_combine($headers, $rows[$i]);
                    if (!$row_data) continue;

                    // --- 1. SPATIAL GEOFENCING AUTO-CORRECTOR (PREVIEW PHASE) ---
                    $barangay_input = trim($row_data['barangay']);
                    $csv_lat = !empty($row_data['latitude']) ? (float)$row_data['latitude'] : 0;
                    $csv_lng = !empty($row_data['longitude']) ? (float)$row_data['longitude'] : 0;

                    if ($csv_lat !== 0 && $csv_lng !== 0) {
                        $geojson_path = '../api/san_pablo_barangays.json'; 
                        $true_barangay = getTrueBarangayFromGeoJSON($csv_lat, $csv_lng, $geojson_path);
                        
                        if ($true_barangay && strtolower($true_barangay) !== strtolower(preg_replace('/^Brgy\.?\s*/i', '', $barangay_input))) {
                            $barangay_input = $true_barangay;
                            $row_data['barangay'] = "Brgy. " . $true_barangay; // Update preview to show the corrected name
                        }
                    }

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

                    $dup_check = $conn->prepare("SELECT case_no FROM incidents WHERE case_no = ?");
                    $dup_check->bind_param("s", $row_data['case_no']);
                    $dup_check->execute();

                    if ($dup_check->get_result()->num_rows > 0) {
                        $row_data['existing_case'] = true;
                        $duplicate_count_preview++;
                    } else {
                        $row_data['existing_case'] = false;
                    }
                    $dup_check->close();

                    $row_data['incident_type'] = trim($row_data['incident_type'] ?? '');
                    if ($row_data['incident_type'] === '') {
                        $row_data['incident_type'] = 'Not Listed';
                    }
                    $preview_data[] = $row_data;
                }

                if (empty($errors)) {
                    $upload_status = 'success';
                    $upload_message = 'File validated successfully. ' . (count($rows) - 1) . ' rows ready to process.';
                    if ($duplicate_count_preview > 0) {
                        $upload_status = 'warning';
                        $upload_message .= ' Note: Found ' . $duplicate_count_preview . ' duplicate(s) in preview that will be automatically skipped.';
                    }
                } else {
                    $upload_status = 'warning';
                    $upload_message = 'Some rows have errors. Valid rows will be imported. Duplicates will be skipped.';
                }

                $_SESSION['pending_import_rows'] = $rows;
            }
        } catch (Exception $e) {
            $upload_status = 'error';
            $upload_message = 'Error reading file: ' . $e->getMessage();
        }
    }
}

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
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'lng', 'long'],
        'attachments' => ['attachments', 'files', 'filenames', 'attachment filenames']
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
        function safeImportDate($dateString, $format = 'Y-m-d') {
            if (empty($dateString) || str_contains($dateString, '0000-00-00') || str_contains($dateString, '0001')) return null;
            $timestamp = strtotime($dateString);
            if ($timestamp === false || $timestamp <= 0) return null;
            return date($format, $timestamp);
        }

        for ($i = 1; $i < count($rows); $i++) {
            if (count($headers) != count($rows[$i])) continue; 
            
            $row_data = @array_combine($headers, $rows[$i]);
            if (!$row_data) continue;

            $case_no = trim($row_data['case_no']);
            $dup_check_stmt = $conn->prepare("SELECT id FROM incidents WHERE case_no = ?");
            $dup_check_stmt->bind_param("s", $case_no);
            $dup_check_stmt->execute();
            if ($dup_check_stmt->get_result()->num_rows > 0) {
                $skipped++;
                $dup_check_stmt->close();
                continue; 
            }
            $dup_check_stmt->close();

            // --- 2. SPATIAL GEOFENCING AUTO-CORRECTOR (IMPORT PHASE) ---
            $barangay_input = trim($row_data['barangay']);
            $csv_lat = !empty($row_data['latitude']) ? (float)$row_data['latitude'] : 0;
            $csv_lng = !empty($row_data['longitude']) ? (float)$row_data['longitude'] : 0;

            if ($csv_lat !== 0 && $csv_lng !== 0) {
                $geojson_path = '../api/san_pablo_barangays.json'; 
                $true_barangay = getTrueBarangayFromGeoJSON($csv_lat, $csv_lng, $geojson_path);
                
                if ($true_barangay && strtolower($true_barangay) !== strtolower(preg_replace('/^Brgy\.?\s*/i', '', $barangay_input))) {
                    $barangay_input = $true_barangay;
                }
            }

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

            // CRITICAL FIX: If CSV provides coordinates, use them. Otherwise, default to Barangay center.
            $final_lat = ($csv_lat !== 0) ? $csv_lat : $brgy['lat'];
            $final_lng = ($csv_lng !== 0) ? $csv_lng : $brgy['lng'];

            $prosecutor_id = null;
            if (!empty($row_data['prosecutor'])) {
                $p = trim($row_data['prosecutor']);
                $pcheck = $conn->prepare("SELECT id FROM prosecutors WHERE full_name = ?");
                $pcheck->bind_param("s", $p);
                $pcheck->execute();
                $pid = $pcheck->get_result()->fetch_row()[0] ?? null;

                if (!$pid && !empty($p)) { 
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
            ");

            $incident_date = safeImportDate($row_data['incident_date'] ?? '', 'Y-m-d') ?? date('Y-m-d');
            $date_committed = safeImportDate($row_data['date_committed'] ?? '', 'Y-m-d H:i:s');
            $date_filed = safeImportDate($row_data['date_filed'] ?? '', 'Y-m-d');
            $received_date = safeImportDate($row_data['received_date'] ?? '', 'Y-m-d H:i:s');
            $returned_date = safeImportDate($row_data['returned_date'] ?? '', 'Y-m-d H:i:s');
            $bail = (!empty($row_data['bail_recommended']) && is_numeric($row_data['bail_recommended'])) 
                ? floatval($row_data['bail_recommended']) : null;

            $accused = $row_data['accused'] ?? '';
            $accused_address = $row_data['accused_address'] ?? '';
            $accused_contact = $row_data['accused_contact'] ?? '';
            $complainant = $row_data['complainant'] ?? '';
            $complainant_address = $row_data['complainant_address'] ?? '';
            $complainant_contact = $row_data['complainant_contact'] ?? '';
            
            $incident_type = trim($row_data['incident_type'] ?? '');
            
            $ra_crimes = ['Phishing', 'Online Fraud', 'Identity Theft', 'Cyber Harassment', 'Sextortion', 'Online Libel', 'Hacking'];
            if (in_array(ucwords(strtolower($incident_type)), $ra_crimes)) {
                $incident_type = 'Republic Act No. 10175 (' . ucwords(strtolower($incident_type)) . ')';
            } elseif ($incident_type === '') {
                $incident_type = 'Not Listed';
            }

            $modus = $row_data['modus_operandi'] ?? '';
            $status = $row_data['status'] ?? 'Open'; 
            $nps_docket = $row_data['nps_docket'] ?? '';
            $offense_crime = $row_data['offense_crime'] ?? '';
            $received_by = $row_data['received_by'] ?? '';
            $returned_to = $row_data['returned_to'] ?? '';
            $evidence_notes = $row_data['evidence_notes'] ?? '';

            $insert->bind_param(
                "ssisddssssssssssssssdisssss",
                $case_no,
                $incident_type,
                $brgy['official_name'],
                $brgy['id'],
                $final_lat, // Uses the corrected CSV coordinates
                $final_lng, // Uses the corrected CSV coordinates
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
                $incident_id = $conn->insert_id;

                if ($incident_id && !empty($row_data['attachments'])) {
                    $server_path_dir = $_SERVER['DOCUMENT_ROOT'] . "/cyberpablo/uploads/cases/" . $case_no . "/";
                    $server_path_dir = str_replace('/', DIRECTORY_SEPARATOR, $server_path_dir);
                    $web_path_dir = "../uploads/cases/" . $case_no . "/";

                    if (!is_dir($server_path_dir)) {
                        mkdir($server_path_dir, 0755, true);
                    }

                    $filenames_from_excel = explode(',', $row_data['attachments']);

                    foreach ($filenames_from_excel as $original_filename) {
                        $original_filename = trim($original_filename);
                        if (empty($original_filename)) continue;

                        $file_to_add_filename = null;
                        $file_to_add_web_path = null;
                        
                        $all_files_in_dir = glob($server_path_dir . "*");

                        if ($all_files_in_dir) {
                            foreach ($all_files_in_dir as $found_filepath) {
                                if (!is_file($found_filepath)) continue;
                                $found_filename = basename($found_filepath);

                                if ($found_filename === $original_filename || str_ends_with($found_filename, "_" . $original_filename)) {
                                    $file_to_add_filename = $found_filename;
                                    $file_to_add_web_path = $web_path_dir . $found_filename;
                                    break; 
                                }
                            }
                        }

                        if ($file_to_add_filename) {
                            $dup_att_stmt = $conn->prepare("SELECT id FROM attachments WHERE incident_id = ? AND file_name = ?");
                            $dup_att_stmt->bind_param("is", $incident_id, $file_to_add_filename); 
                            $dup_att_stmt->execute();
                            if ($dup_att_stmt->get_result()->num_rows == 0) {
                                $att_stmt = $conn->prepare("INSERT INTO attachments (incident_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
                                $att_stmt->bind_param("issi", $incident_id, $file_to_add_filename, $file_to_add_web_path, $_SESSION['user_id']); 
                                $att_stmt->execute();
                                $att_stmt->close();
                            }
                            $dup_att_stmt->close();
                        }
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
        $upload_message = "Import complete! $imported new records imported, $skipped skipped (duplicates/errors).";

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
    <link rel="stylesheet" href="../assets/css/import_cases.css">
</head>
<body>
   <?php require_once 'header.php'; ?>

    <div class="container">
        <?php if ($upload_message): ?>
            <div class="alert alert-<?= $upload_status ?>">
                <strong><?= ucfirst($upload_status) ?>:</strong> <?= $upload_message ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="card">
                <h2>Validation Errors</h2>
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
            <p class="description-text">
                Upload an Excel (.xlsx, .xls) or CSV file containing cybercrime incident data.
                Duplicates will be safely skipped. GPS coordinates will auto-correct incorrect Barangay entries.
            </p>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                    <div class="upload-icon">📁</div>
                    <h3>Click to select file or drag & drop</h3>
                    <p class="upload-subtext">Supported: .xlsx, .xls, .csv (Max 10MB)</p>
                </div>
                <input type="file" name="excel_file" id="fileInput" accept=".xlsx,.xls,.csv" required>
                
                <div class="button-container">
                    <button type="submit" class="btn btn-primary">Validate & Preview</button>
                </div>
            </form>

            <a href="download_template.php" class="template-download">Download Excel Template</a>
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
                                <td>
                                    <?= htmlspecialchars($row['case_no']) ?>
                                    <?php if($row['existing_case']): ?>
                                        <br><span style="color: #d32f2f; font-size: 10px; font-weight: bold;">(Duplicate - Will Skip)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['incident_type']) ?></td>
                                <td>
                                    <?= htmlspecialchars($row['barangay']) ?>
                                    <?php if($row['latitude'] && $row['longitude']): ?>
                                        <br><span style="color: #28a745; font-size: 10px; font-weight: bold;">(Geo-Verified)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['incident_date']))) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                    <?php if (empty($errors) || $upload_status === 'warning'): ?>
                        <form method="POST" class="button-container">
                            <button type="submit" name="confirm_import" class="btn btn-success">
                                Confirm & Import New Records
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

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                uploadArea.querySelector('h3').textContent = `Selected: ${fileName}`;
            }
        });
    </script>
</body>
</html>