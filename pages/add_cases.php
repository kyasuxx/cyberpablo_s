<?php
session_start();
require_once 'config/connection.php';
require_once '../vendor/autoload.php'; // For PhpSpreadsheet, though not needed for this form

// 1. --- Check Authentication ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// 2. --- Fetch Data for Dropdowns ---
$barangays = [];
$barangay_result = $conn->query("SELECT id, official_name FROM barangays ORDER BY official_name");
while ($b = $barangay_result->fetch_assoc()) {
    $barangays[] = $b;
}

$prosecutors = [];
$presult = $conn->query("SELECT id, full_name FROM prosecutors WHERE is_active = 1 ORDER BY full_name");
while ($p = $presult->fetch_assoc()) {
    $prosecutors[] = $p;
}

// 3. --- Generate a New Case Number ---
$year = date('Y');
$stmt = $conn->prepare("SELECT case_no FROM incidents WHERE case_no LIKE ? ORDER BY case_no DESC LIMIT 1");
$like_pattern = "CYBER-" . $year . "-%";
$stmt->bind_param("s", $like_pattern);
$stmt->execute();
$result = $stmt->get_result();
$last_case = $result->fetch_assoc();

$new_case_num_int = 1;
if ($last_case) {
    $last_num = (int)str_replace("CYBER-" . $year . "-", "", $last_case['case_no']);
    $new_case_num_int = $last_num + 1;
}
// Formats the number as 0001, 0002, etc.
$new_case_no = "CYBER-" . $year . "-" . str_pad($new_case_num_int, 4, '0', STR_PAD_LEFT);


// 4. --- Handle Form Submission (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        // Get data from POST
        $case_no = $_POST['case_no'];
        $incident_type = $_POST['incident_type'];
        $barangay_id = $_POST['barangay_id'];
        $incident_date = $_POST['incident_date'];
        $status = $_POST['status'];

        // Get Barangay Lat/Lng from DB
        $brgy_stmt = $conn->prepare("SELECT lat, lng, official_name FROM barangays WHERE id = ?");
        $brgy_stmt->bind_param("i", $barangay_id);
        $brgy_stmt->execute();
        $brgy = $brgy_stmt->get_result()->fetch_assoc();

        if (!$brgy) {
            throw new Exception("Invalid Barangay selected.");
        }

        // Handle nullable fields
        $date_committed = !empty($_POST['date_committed']) ? $_POST['date_committed'] : null;
        $date_filed = !empty($_POST['date_filed']) ? $_POST['date_filed'] : null;
        $prosecutor_id = !empty($_POST['prosecutor_id']) ? (int)$_POST['prosecutor_id'] : null;
        $bail = !empty($_POST['bail_recommended']) ? (float)$_POST['bail_recommended'] : null;
        $hashed_victim_id = hash('sha256', ($_POST['complainant'] ?? '') . time());

        // Prepare the INSERT statement
        $insert_stmt = $conn->prepare("
            INSERT INTO incidents (
                case_no, incident_type, barangay, barangay_id, lat, lng, incident_date,
                modus_operandi, hashed_victim_id, status, 
                accused, accused_address, accused_contact,
                complainant, complainant_address, complainant_contact,
                nps_docket, offense_crime, date_committed, date_filed, 
                bail_recommended, prosecutor_id, 
                received_by, received_date, returned_to, returned_date,
                evidence_notes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        
        // Bind all 27 parameters
        $insert_stmt->bind_param(
            "ssisddssssssssssssssdisssss",
            $case_no,
            $incident_type,
            $brgy['official_name'],
            $barangay_id,
            $brgy['lat'],
            $brgy['lng'],
            $incident_date,
            $_POST['modus_operandi'],
            $hashed_victim_id,
            $status,
            $_POST['accused'],
            $_POST['accused_address'],
            $_POST['accused_contact'],
            $_POST['complainant'],
            $_POST['complainant_address'],
            $_POST['complainant_contact'],
            $_POST['nps_docket'],
            $_POST['offense_crime'],
            $date_committed,
            $date_filed,
            $bail,
            $prosecutor_id,
            $_POST['received_by'],
            $_POST['received_date'],
            $_POST['returned_to'],
            $_POST['returned_date'],
            $_POST['evidence_notes']
        );

        // Execute and check for success
        if (!$insert_stmt->execute()) {
            throw new Exception("Database insert failed: " . $insert_stmt->error);
        }
        
        $incident_id = $conn->insert_id; // Get the ID of the case we just created

        // --- 5. Handle File Uploads ---
        if ($incident_id && !empty($_FILES['attachments']['name'][0])) {
            $dest_dir_server = $_SERVER['DOCUMENT_ROOT'] . "/cyberpablo/uploads/cases/" . $case_no . "/";
            $dest_dir_web = "../uploads/cases/" . $case_no . "/";

            if (!is_dir($dest_dir_server)) {
                mkdir($dest_dir_server, 0755, true);
            }

            foreach ($_FILES['attachments']['name'] as $key => $filename) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_filename = basename($filename);
                    $new_filename = time() . "_" . $original_filename; // Add timestamp to prevent overwrites
                    
                    $server_file_path = $dest_dir_server . $new_filename;
                    $web_file_path = $dest_dir_web . $new_filename;

                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $server_file_path)) {
                        // File moved, now add to DB
                        $att_stmt = $conn->prepare(
                            "INSERT INTO attachments (incident_id, file_name, file_path, uploaded_by) 
                             VALUES (?, ?, ?, ?)"
                        );
                        $att_stmt->bind_param("issi", $incident_id, $new_filename, $web_file_path, $user_id);
                        $att_stmt->execute();
                        $att_stmt->close();
                    }
                }
            }
        }

        $conn->commit();
        $message = "Success! Case $case_no has been created.";
        $message_type = 'success';
        
        // Refresh the new case number
        $new_case_num_int++;
        $new_case_no = "CYBER-" . $year . "-" . str_pad($new_case_num_int, 4, '0', STR_PAD_LEFT);

    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Case - CyberPablo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: linear-gradient(135deg, #003366 0%, #004d99 100%); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .header h1 { font-size: 24px; margin: 0; }
        .nav-links a { color: #ffcc00; text-decoration: none; font-weight: 500; margin: 0 10px; }
        .nav-links a.logout { background: #d32f2f; color: white; padding: 8px 20px; border-radius: 6px; }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 25px; }
        .form-section { border-bottom: 2px solid #003366; padding-bottom: 10px; margin: 30px 0 20px 0; grid-column: 1 / -1; }
        .form-section h2 { color: #003366; margin: 0; }
        
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-weight: 600; margin-bottom: 8px; font-size: 14px; color: #333; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            font-size: 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: inherit;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input[type="file"] { padding: 5px; }
        
        /* Required field styling */
        .form-group label.required::after { content: ' *'; color: #d32f2f; }
        
        .btn-submit {
            grid-column: 3 / -1;
            background: #28a745;
            color: white;
            font-size: 16px;
            font-weight: 600;
            padding: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-submit:hover { background: #218838; }
        
        /* Choices.js custom style */
        .choices__inner { background: #fff; border-radius: 6px; padding: 6px 10px; }
        .choices__list--dropdown { border-radius: 6px; }
        
        /* Message styling */
        .message { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Add New Case</h1>
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="cases.php">Cases</a>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-grid">

                <div class="form-section">
                    <h2>Case Details</h2>
                </div>

                <div class="form-group">
                    <label for="case_no" class="required">Case Number</label>
                    <input type="text" id="case_no" name="case_no" value="<?= htmlspecialchars($new_case_no) ?>" readonly style="background: #eee;">
                </div>

                <div class="form-group">
                    <label for="incident_type" class="required">Incident Type</label>
                    <select id="incident_type" name="incident_type" required>
                        <option value="">Select a type...</option>
                        <option value="Phishing">Phishing</option>
                        <option value="Online Fraud">Online Fraud</option>
                        <option value="Identity Theft">Identity Theft</option>
                        <option value="Cyber Harassment">Cyber Harassment</option>
                        <option value="Sextortion">Sextortion</option>
                        <option value="Online Libel">Online Libel</option>
                        <option value="Not Listed">Not Listed</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="barangay_id" class="required">Barangay</label>
                    <select id="barangay_id" name="barangay_id" required>
                        <option value="">Search for a barangay...</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?= $barangay['id'] ?>"><?= htmlspecialchars($barangay['official_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="incident_date" class="required">Incident Date</label>
                    <input type="date" id="incident_date" name="incident_date" required>
                </div>
                
                <div class="form-group">
                    <label for="date_committed">Date/Time Committed</label>
                    <input type="datetime-local" id="date_committed" name="date_committed">
                </div>

                <div class="form-group">
                    <label for="status" class="required">Status</label>
                    <select id="status" name="status" required>
                        <option value="Open" selected>Open</option>
                        <option value="Under Investigation">Under Investigation</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label for="modus_operandi">Modus Operandi</label>
                    <textarea id="modus_operandi" name="modus_operandi" rows="3"></textarea>
                </div>

                <div class="form-section">
                    <h2>Parties Involved</h2>
                </div>
                
                <div class="form-group">
                    <label for="complainant">Complainant</label>
                    <input type="text" id="complainant" name="complainant">
                </div>
                <div class="form-group">
                    <label for="complainant_address">Complainant Address</label>
                    <input type="text" id="complainant_address" name="complainant_address">
                </div>
                <div class="form-group">
                    <label for="complainant_contact">Complainant Contact</label>
                    <input type="text" id="complainant_contact" name="complainant_contact">
                </div>
                
                <div class="form-group">
                    <label for="accused">Accused</label>
                    <input type="text" id="accused" name="accused">
                </div>
                <div class="form-group">
                    <label for="accused_address">Accused Address</label>
                    <input type="text" id="accused_address" name="accused_address">
                </div>
                <div class="form-group">
                    <label for="accused_contact">Accused Contact</label>
                    <input type="text" id="accused_contact" name="accused_contact">
                </div>

                <div class="form-section">
                    <h2>Legal & Filing Details</h2>
                </div>

                <div class="form-group">
                    <label for="prosecutor_id">Prosecutor</label>
                    <select id="prosecutor_id" name="prosecutor_id">
                        <option value="">Search for a prosecutor...</option>
                        <?php foreach ($prosecutors as $prosecutor): ?>
                            <option value="<?= $prosecutor['id'] ?>"><?= htmlspecialchars($prosecutor['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="nps_docket">NPS Docket</label>
                    <input type="text" id="nps_docket" name="nps_docket">
                </div>
                
                <div class="form-group">
                    <label for="offense_crime">Offense/Crime</label>
                    <input type="text" id="offense_crime" name="offense_crime">
                </div>

                <div class="form-group">
                    <label for="branch">Branch</label>
                    <input type="text" id="branch" name="branch">
                </div>
                
                <div class="form-group">
                    <label for="date_filed">Date Filed</label>
                    <input type="date" id="date_filed" name="date_filed">
                </div>

                <div class="form-group">
                    <label for="bail_recommended">Bail Recommended (₱)</label>
                    <input type="number" step="0.01" id="bail_recommended" name="bail_recommended">
                </div>

                <div class="form-section">
                    <h2>Evidence & Attachments</h2>
                </div>

                <div class="form-group full-width">
                    <label for="evidence_notes">Evidence Notes</label>
                    <textarea id="evidence_notes" name="evidence_notes" rows="3"></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label for="attachments">Attachments (can select multiple)</label>
                    <input type="file" id="attachments" name="attachments[]" multiple>
                </div>
                
                <input type="hidden" name="received_by" value="<?= htmlspecialchars($_SESSION['username']) ?>">
                <input type="hidden" name="received_date" value="<?= date('Y-m-d H:i:s') ?>">
                <input type="hidden" name="returned_to" value="">
                <input type="hidden" name="returned_date" value="">

                <button type="submit" class="btn-submit">Save New Case</button>

            </div>
        </form>
    </div>

    <script>
        // Initialize searchable dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            new Choices('#barangay_id', {
                searchEnabled: true,
                shouldSort: false,
            });
            new Choices('#prosecutor_id', {
                searchEnabled: true,
                shouldSort: false,
            });
            new Choices('#incident_type', {
                searchEnabled: false,
            });
            new Choices('#status', {
                searchEnabled: false,
            });
        });
    </script>
</body>
</html>