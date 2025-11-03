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
                'complainant' => ['complainant', 'victim'],
                'date_filed' => ['date filed', 'filed date'],
                'prosecutor' => ['prosecutor', 'assigned prosecutor']
            ];

            // Normalize headers
            $headers_raw = array_map('trim', $rows[0]);
            $headers_normalized = [];
            foreach ($headers_raw as $header) {
                $normalized = strtolower($header);
                $normalized = str_replace(['_', '-', '.'], ' ', $normalized);

                $matched_key = null;
                foreach ($header_aliases as $expected => $aliases) {
                    if (in_array($normalized, $aliases)) {
                        $matched_key = $expected;
                        break;
                    }
                }
                $headers_normalized[] = $matched_key ?? str_replace(' ', '_', $normalized);
            }

            // ✅ FIX: define $headers so array_combine() won’t fail
            $headers = $headers_normalized;

            // Validate all required headers exist
            $missing_headers = array_diff(array_keys($header_aliases), $headers_normalized);
            if (!empty($missing_headers)) {
                $upload_status = 'error';
                $upload_message = 'Missing required columns: ' . implode(', ', $missing_headers);
            } else {
                // Preview first 10 rows
                $preview_count = min(10, count($rows) - 1);
                for ($i = 1; $i <= $preview_count; $i++) {
                    $row_data = @array_combine($headers, $rows[$i]);
                    if (!$row_data) continue;

                    // Validate barangay exists
                    $barangay_input = trim($row_data['barangay']);
                    $clean = preg_replace('/^Brgy\.?\s*/i', '', $barangay_input);

                    $brgy_check = $conn->prepare("
                        SELECT official_name, lat, lng FROM barangays 
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
                        // Just mark as existing, not an error
                        $row_data['existing_case'] = true;
                    } else {
                        $row_data['existing_case'] = false;
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

                // Store file for import (even if warnings exist)
                $_SESSION['pending_import_file'] = $file['tmp_name'];
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
        'complainant' => ['complainant', 'victim'],
        'date_filed' => ['date filed', 'filed date'],
        'prosecutor' => ['prosecutor', 'assigned prosecutor']
    ];

    $headers_raw = array_map('trim', $rows[0]);
    $headers = [];
    foreach ($headers_raw as $header) {
        $normalized = strtolower($header);
        $normalized = str_replace(['_', '-', '.'], ' ', $normalized);

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
        for ($i = 1; $i < count($rows); $i++) {
            $row_data = array_combine($headers, $rows[$i]);

            // Get barangay coordinates
            $barangay_input = trim($row_data['barangay']);
            $clean = preg_replace('/^Brgy\.?\s*/i', '', $barangay_input);

            $brgy_stmt = $conn->prepare("
                SELECT official_name, lat, lng FROM barangays 
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

                if (!$pid) {
                    $insert_p = $conn->prepare("INSERT INTO prosecutors (full_name) VALUES (?)");
                    $insert_p->bind_param("s", $p);
                    $insert_p->execute();
                    $prosecutor_id = $conn->insert_id;
                } else {
                    $prosecutor_id = $pid;
                }
            }

            $victim_hash = hash('sha256', $row_data['complainant'] . time());

            $insert = $conn->prepare("
                INSERT INTO incidents (
                    case_no, incident_type, barangay, lat, lng, incident_date,
                    modus_operandi, hashed_victim_id, status, accused, complainant,
                    date_filed, prosecutor_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    incident_type = VALUES(incident_type),
                    barangay = VALUES(barangay),
                    lat = VALUES(lat), lng = VALUES(lng),
                    incident_date = VALUES(incident_date),
                    modus_operandi = VALUES(modus_operandi),
                    status = VALUES(status),
                    accused = VALUES(accused),
                    complainant = VALUES(complainant),
                    date_filed = VALUES(date_filed),
                    prosecutor_id = VALUES(prosecutor_id),
                    updated_at = NOW()
            ");
            $insert->bind_param(
                "sssddsssssssi",
                $row_data['case_no'],
                $row_data['incident_type'],
                $brgy['official_name'],
                $brgy['lat'],
                $brgy['lng'],
                $row_data['incident_date'],
                $row_data['modus_operandi'],
                $victim_hash,
                $row_data['status'],
                $row_data['accused'],
                $row_data['complainant'],
                $row_data['date_filed'],
                $prosecutor_id
            );

            if ($insert->execute()) {
                $imported++;
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

        unset($_SESSION['pending_import_file'], $_SESSION['pending_import_rows']);
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
        <h1>🔐 CYBERPABLO - Excel Import</h1>
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
            <h2>📤 Upload Excel File</h2>
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
                    <button type="submit" class="btn btn-primary">📊 Validate & Preview</button>
                </div>
            </form>

            <a href="download_template.php" class="template-download">⬇️ Download Excel Template</a>
        </div>

        <?php if (!empty($preview_data)): ?>
            <div class="card">
                <h2>👁️ Preview (First 10 Rows)</h2>
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
                                <td><?= htmlspecialchars($row['incident_date']) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                    <?php if (empty($errors) || $upload_status === 'warning'): ?>
                        <form method="POST" style="margin-top: 20px; text-align: center;">
                            <button type="submit" name="confirm_import" class="btn btn-success">
                                ✅ Confirm & Import All Records
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                ❌ Cancel
                            </button>
                        </form>
                    <?php endif; ?>

            </div>
        <?php endif; ?>

        <div class="card">
            <h2>📋 Required Columns</h2>
            <ul style="line-height: 1.8; color: #555;">
                <li><strong>case_no</strong> - Unique case identifier (e.g., CYBER-2025-0001)</li>
                <li><strong>incident_type</strong> - Phishing | Online Fraud | Identity Theft | Cyber Harassment | Others</li>
                <li><strong>barangay</strong> - Must match official barangay names in database</li>
                <li><strong>incident_date</strong> - Format: YYYY-MM-DD</li>
                <li><strong>modus_operandi</strong> - Description of the crime method</li>
                <li><strong>status</strong> - Open | Under Investigation | Closed</li>
                <li><strong>accused</strong> - Name of accused (optional)</li>
                <li><strong>complainant</strong> - Name of complainant (will be hashed)</li>
                <li><strong>date_filed</strong> - Date case was filed (YYYY-MM-DD)</li>
                <li><strong>prosecutor</strong> - Assigned prosecutor name (optional)</li>
            </ul>
        </div>
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