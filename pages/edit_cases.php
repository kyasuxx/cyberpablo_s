<?php
session_start();
require_once 'config/connection.php';
require_once 'spatial_helper.php'; // <-- ADDED: The Spatial Geofencing Tool

// Security check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Get case ID from URL
$case_id = $_GET['id'] ?? '';
if (empty($case_id)) {
    header("Location: cases.php");
    exit;
}

// Fetch case data
$stmt = $conn->prepare("
    SELECT i.*, b.official_name, b.alt_name, b.lat as brgy_lat, b.lng as brgy_lng,
           p.full_name as prosecutor_name
    FROM incidents i
    LEFT JOIN barangays b ON i.barangay_id = b.id
    LEFT JOIN prosecutors p ON i.prosecutor_id = p.id
    WHERE i.case_no = ?
");
$stmt->bind_param("s", $case_id);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();

if (!$case) {
    die("Case not found");
}

// Fetch prosecutors
$prosecutors = $conn->query("SELECT id, full_name, office FROM prosecutors WHERE is_active = 1 ORDER BY full_name");

// Fetch barangays for autocomplete
$barangays = $conn->query("SELECT id, official_name, alt_name, lat, lng FROM barangays ORDER BY official_name");
$barangay_data = [];
while ($b = $barangays->fetch_assoc()) {
    $barangay_data[] = [
        'id' => $b['id'],
        'official' => $b['official_name'],
        'alt' => $b['alt_name'],
        'lat' => $b['lat'],
        'lng' => $b['lng']
    ];
}

// Fetch status history
$history_stmt = $conn->prepare("
    SELECT csh.*, u.username 
    FROM case_status_history csh
    LEFT JOIN users u ON csh.changed_by = u.id
    WHERE csh.incident_id = ?
    ORDER BY csh.changed_at DESC
");
$history_stmt->bind_param("i", $case['id']);
$history_stmt->execute();
$status_history = $history_stmt->get_result();

// Handle form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_case'])) {
    $old_status = $case['status'];
    $new_status = trim($_POST['status']);
    $status_remarks = trim($_POST['status_remarks'] ?? '');
    
    $conn->begin_transaction();
    
    try {
        // --- SPATIAL GEOFENCING AUTO-CORRECTOR ---
        $final_barangay = $_POST['barangay'];
        $final_barangay_id = $_POST['barangay_id'];
        $final_lat = (float)$_POST['lat'];
        $final_lng = (float)$_POST['lng'];

        if ($final_lat !== 0.0 && $final_lng !== 0.0) {
            $geojson_path = '../api/san_pablo_barangays.json'; 
            
            // Ask the math engine: Where did the user actually drag the pin?
            $true_brgy_name = getTrueBarangayFromGeoJSON($final_lat, $final_lng, $geojson_path);
            
            if ($true_brgy_name) {
                // Smart Matcher
                $search1 = $true_brgy_name;
                $search2 = "Brgy. " . $true_brgy_name;
                $search3 = "Barangay " . $true_brgy_name;
                
                $brgy_stmt = $conn->prepare("
                    SELECT id, official_name FROM barangays 
                    WHERE official_name = ? OR alt_name = ? 
                       OR official_name = ? OR official_name = ? 
                    LIMIT 1
                ");
                $brgy_stmt->bind_param("ssss", $search1, $search1, $search2, $search3);
                $brgy_stmt->execute();
                $brgy_data = $brgy_stmt->get_result()->fetch_assoc();
                
                if ($brgy_data) {
                    // SILENT CORRECTION: Override the user's text input with the geographic truth
                    $final_barangay = $brgy_data['official_name'];
                    $final_barangay_id = $brgy_data['id'];
                }
            }
        }
        // ------------------------------------------

        // Update incident
        $update_stmt = $conn->prepare("
            UPDATE incidents SET
                incident_type = ?,
                barangay = ?,
                barangay_id = ?,
                lat = ?,
                lng = ?,
                incident_date = ?,
                modus_operandi = ?,
                status = ?,
                accused = ?,
                accused_address = ?,
                accused_contact = ?,
                complainant = ?,
                complainant_address = ?,
                complainant_contact = ?,
                nps_docket = ?,
                offense_crime = ?,
                date_committed = ?,
                date_filed = ?,
                bail_recommended = ?,
                prosecutor_id = ?,
                prosecutor = ?,
                received_by = ?,
                received_date = ?,
                returned_to = ?,
                returned_date = ?
            WHERE case_no = ?
        ");
        
        $prosecutor_id = !empty($_POST['prosecutor_id']) ? $_POST['prosecutor_id'] : null;
        $prosecutor_text = isset($_POST['prosecutor_text']) ? trim($_POST['prosecutor_text']) : '';

        $bail = (isset($_POST['bail_recommended']) && $_POST['bail_recommended'] !== '' && $_POST['bail_recommended'] > 0) 
            ? floatval($_POST['bail_recommended']) 
            : null;

        $date_committed = (isset($_POST['date_committed']) && $_POST['date_committed'] !== '') 
            ? $_POST['date_committed'] 
            : null;

        $received_date = (isset($_POST['received_date']) && $_POST['received_date'] !== '') 
            ? $_POST['received_date'] 
            : null;

        $date_filed = (isset($_POST['date_filed']) && $_POST['date_filed'] !== '') 
            ? $_POST['date_filed'] 
            : null;

        $returned_date = (isset($_POST['returned_date']) && $_POST['returned_date'] !== '') 
            ? $_POST['returned_date'] 
            : null;
        
        $update_stmt->bind_param(
            "ssiddsssssssssssssdissssss",  
            $_POST['incident_type'],       
            $final_barangay,               // <-- Uses Geo-Validated Data
            $final_barangay_id,            // <-- Uses Geo-Validated Data
            $final_lat,                    // <-- Uses Geo-Validated Data
            $final_lng,                    // <-- Uses Geo-Validated Data
            $_POST['incident_date'],       
            $_POST['modus_operandi'],      
            $new_status,                   
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
            $prosecutor_text,              
            $_POST['received_by'],         
            $received_date,                
            $_POST['returned_to'],         
            $returned_date,                
            $case_id                       
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update case");
        }
        
        if ($old_status !== $new_status) {
            $history_insert = $conn->prepare("
                INSERT INTO case_status_history (incident_id, status, changed_by, remarks)
                VALUES (?, ?, ?, ?)
            ");
            $history_insert->bind_param("isis", $case['id'], $new_status, $user_id, $status_remarks);
            $history_insert->execute();
        }
        
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = "../uploads/cases/" . $case_id . "/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $filename = basename($_FILES['attachments']['name'][$key]);
                    $filename = preg_replace("/[^a-zA-Z0-9._-]/", "", $filename);
                    $target = $upload_dir . time() . "_" . $filename;
                    
                    if (move_uploaded_file($tmp_name, $target)) {
                        $att_stmt = $conn->prepare("
                            INSERT INTO attachments (incident_id, file_name, file_path, uploaded_by) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $att_stmt->bind_param("issi", $case['id'], $filename, $target, $user_id);
                        $att_stmt->execute();
                        $att_stmt->close(); 
                    }
                }
            }
        }
        
        $audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)");
        $action = "edit_cases_" . $case_id;
        $audit->bind_param("iss", $user_id, $action, $_SERVER['REMOTE_ADDR']);
        $audit->execute();
        
        $conn->commit();
        $success_msg = "Case updated successfully! Geographic alignment verified.";
        
        $stmt->execute();
        $case = $stmt->get_result()->fetch_assoc();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error updating case: " . $e->getMessage();
    }
}

$att_stmt = $conn->prepare("SELECT * FROM attachments WHERE incident_id = ?");
$att_stmt->bind_param("i", $case['id']);
$att_stmt->execute();
$attachments_result = $att_stmt->get_result();
$attachment_dir = "../uploads/cases/" . $case_id . "/"; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Case <?= htmlspecialchars($case_id) ?> - CyberPablo</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/edit_cases.css">
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="container">
        <div class="main-content">
            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?= $success_msg ?></div>
            <?php endif; ?>
            
            <?php if ($error_msg): ?>
                <div class="alert alert-error"><?= $error_msg ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="card">
                    <h2>Basic Information</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Case Number</label>
                            <input type="text" value="<?= htmlspecialchars($case['case_no']) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="incident_type" class="required">Incident Type</label>
                            <select id="incident_type" name="incident_type" required>
                                <option value="">Select a type...</option>
                                <option value="Republic Act No. 10175 (Phishing)" <?= $case['incident_type']=='Republic Act No. 10175 (Phishing)'?'selected':'' ?>>Republic Act No. 10175 (Phishing)</option>
                                <option value="Republic Act No. 10175 (Online Fraud)" <?= $case['incident_type']=='Republic Act No. 10175 (Online Fraud)'?'selected':'' ?>>Republic Act No. 10175 (Online Fraud)</option>
                                <option value="Republic Act No. 10175 (Identity Theft)" <?= $case['incident_type']=='Republic Act No. 10175 (Identity Theft)'?'selected':'' ?>>Republic Act No. 10175 (Identity Theft)</option>
                                <option value="Republic Act No. 10175 (Cyber Harassment)" <?= $case['incident_type']=='Republic Act No. 10175 (Cyber Harassment)'?'selected':'' ?>>Republic Act No. 10175 (Cyber Harassment)</option>
                                <option value="Republic Act No. 10175 (Sextortion)" <?= $case['incident_type']=='Republic Act No. 10175 (Sextortion)'?'selected':'' ?>>Republic Act No. 10175 (Sextortion)</option>
                                <option value="Republic Act No. 10175 (Online Libel)" <?= $case['incident_type']=='Republic Act No. 10175 (Online Libel)'?'selected':'' ?>>Republic Act No. 10175 (Online Libel)</option>
                                <option value="Republic Act No. 10175 (Hacking)" <?= $case['incident_type']=='Republic Act No. 10175 (Hacking)'?'selected':'' ?>>Republic Act No. 10175 (Hacking)</option>
                                <option value="Others" <?= $case['incident_type']=='Others'?'selected':'' ?>>Others</option>
                            </select>

                            <input type="text" id="other_specify" name="other_specify" placeholder="Please specify the crime..." style="display:none; margin-top:10px;">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="position: relative;">
                            <label class="required">Barangay</label>
                            <input type="text" name="barangay" id="barangay" 
                                   value="<?= htmlspecialchars($case['official_name'] ?? $case['barangay']) ?>" 
                                   autocomplete="off" required>
                            <input type="hidden" name="barangay_id" id="barangay_id" value="<?= $case['barangay_id'] ?>">

                            <div id="barangay-suggestions"></div>
                        </div>
                        <div class="form-group">
                            <label class="required">Incident Date</label>
                            <input type="date" name="incident_date" 
                                   value="<?= $case['incident_date'] ?>" required>
                        </div>
                    </div>

                    <div class="form-row full">
                        <div class="form-group">
                            <label class="required">Modus Operandi</label>
                            <textarea name="modus_operandi" required><?= htmlspecialchars($case['modus_operandi']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>Location</h2>
                    <p style="color: #666; margin-bottom: 15px; font-size: 14px;">
                        Drag the marker to adjust location. The system will automatically verify and update the registered Barangay bounds upon saving.
                    </p>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" step="0.000001" name="lat" id="lat" 
                                   value="<?= $case['lat'] ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" step="0.000001" name="lng" id="lng" 
                                   value="<?= $case['lng'] ?>" readonly>
                        </div>
                    </div>
                    
                    <div id="map"></div>
                </div>

                <div class="card">
                    <h2>👥 Parties Involved</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Accused Name</label>
                            <input type="text" name="accused" 
                                   value="<?= htmlspecialchars($case['accused'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Accused Contact</label>
                            <input type="text" name="accused_contact" 
                                   value="<?= htmlspecialchars($case['accused_contact'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row full">
                        <div class="form-group">
                            <label>Accused Address</label>
                            <input type="text" name="accused_address" 
                                   value="<?= htmlspecialchars($case['accused_address'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Complainant Name</label>
                            <input type="text" name="complainant" 
                                   value="<?= htmlspecialchars($case['complainant'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Complainant Contact</label>
                            <input type="text" name="complainant_contact" 
                                   value="<?= htmlspecialchars($case['complainant_contact'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row full">
                        <div class="form-group">
                            <label>Complainant Address</label>
                            <input type="text" name="complainant_address" 
                                   value="<?= htmlspecialchars($case['complainant_address'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>⚖️ Case Details</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>NPS Docket Number</label>
                            <input type="text" name="nps_docket" 
                                   value="<?= htmlspecialchars($case['nps_docket'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Offense/Crime</label>
                            <input type="text" name="offense_crime" 
                                   value="<?= htmlspecialchars($case['offense_crime'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Date Committed</label>
                            <input type="datetime-local" name="date_committed" 
                                   value="<?= $case['date_committed'] ? date('Y-m-d\TH:i', strtotime($case['date_committed'])) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Date Filed</label>
                            <input type="date" name="date_filed" 
                                value="<?= !empty($case['date_filed']) && $case['date_filed'] !== '0000-00-00' ? $case['date_filed'] : '' ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Bail Recommended (₱)</label>
                            <input type="number" step="0.01" name="bail_recommended" 
                                   value="<?= $case['bail_recommended'] ?? '' ?>">
                        </div>
                        <div class="form-group">
                            <label class="required">Status</label>
                            <select name="status" required>
                                <option value="Open" <?= $case['status']=='Open'?'selected':'' ?>>Open</option>
                                <option value="Under Investigation" <?= $case['status']=='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                                <option value="Closed" <?= $case['status']=='Closed'?'selected':'' ?>>Closed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row full">
                        <div class="form-group">
                            <label>Status Change Remarks</label>
                            <textarea name="status_remarks" placeholder="Required if changing status..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>Prosecutor Assignment</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Prosecutor</label>
                            <select name="prosecutor_id" id="prosecutor_id">
                                <option value="">-- Select from roster --</option>
                                <?php while ($p = $prosecutors->fetch_assoc()): ?>
                                    <option value="<?= $p['id'] ?>" 
                                            <?= $case['prosecutor_id']==$p['id']?'selected':'' ?>>
                                        <?= htmlspecialchars($p['full_name']) ?> 
                                        (<?= htmlspecialchars($p['office']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Or Enter Manually</label>
                            <input type="text" name="prosecutor_text" id="prosecutor_text"
                                   value="<?= htmlspecialchars($case['prosecutor'] ?? '') ?>"
                                   placeholder="Atty. Juan Dela Cruz">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>Processing Details</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Received By</label>
                            <input type="text" name="received_by" 
                                   value="<?= htmlspecialchars($case['received_by'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Received Date</label>
                            <input type="datetime-local" name="received_date" 
                                   value="<?= $case['received_date'] ? date('Y-m-d\TH:i', strtotime($case['received_date'])) : '' ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Returned To</label>
                            <input type="text" name="returned_to" 
                                   value="<?= htmlspecialchars($case['returned_to'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Returned Date</label>
                            <input type="datetime-local" name="returned_date" 
                                   value="<?= $case['returned_date'] ? date('Y-m-d\TH:i', strtotime($case['returned_date'])) : '' ?>">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>File Attachments</h2>
                    
                    <?php if ($attachments_result->num_rows > 0): ?>
                        <div class="attachment-list" style="margin-bottom: 20px;">
                            <h3 style="font-size: 14px; color: #666; margin-bottom: 10px;">Existing Files:</h3>
                            <?php while ($a = $attachments_result->fetch_assoc()): ?>
                                <div class="attachment-item">
                                    <a href="<?= htmlspecialchars($a['file_path']) ?>" target="_blank">
                                        <?= htmlspecialchars($a['file_name']) ?>
                                    </a>
                                    <span style="font-size: 12px; color: #999;">
                                        (Uploaded <?= date('M d, Y', strtotime($a['uploaded_at'])) ?>)
                                    </span>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>

                    <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                        <p>Click to upload new attachments (PDF, JPG, PNG)</p>
                        <p style="font-size: 12px; color: #999; margin-top: 5px;">Max 5MB per file</p>
                    </div>
                    <input type="file" name="attachments[]" id="fileInput" multiple accept=".pdf,.jpg,.jpeg,.png" style="display: none;">
                </div>

                <div class="button-group">
                    <button type="submit" name="update_case" class="btn btn-primary">
                        Save Changes
                    </button>
                    <a href="print_blotter.php?id=<?= $case['id'] ?>" target="_blank" class="btn btn-secondary" style="background: #4b5563; color: white; text-decoration: none; padding: 10px 15px; border-radius: 5px;">
                        Generate Official IRF
                    </a>
                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <div class="sidebar">
            <div class="card">
                <h2>Status History</h2>
                <?php if ($status_history->num_rows > 0): ?>
                    <?php while ($h = $status_history->fetch_assoc()): ?>
                        <div class="history-item">
                            <strong><?= $h['status'] ?></strong>
                            <p style="font-size: 13px; margin-top: 5px;">
                                <?= htmlspecialchars($h['remarks'] ?? 'No remarks') ?>
                            </p>
                            <div class="history-date">
                                By <?= htmlspecialchars($h['username']) ?><br>
                                <?= date('M d, Y h:i A', strtotime($h['changed_at'])) ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="color: #999; text-align: center;">No history yet</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Case Info</h2>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <strong style="color: #666; font-size: 12px;">Current Status</strong>
                        <div>
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $case['status'])) ?>">
                                <?= $case['status'] ?>
                            </span>
                        </div>
                    </div>
                    <div>
                        <strong style="color: #666; font-size: 12px;">Created</strong>
                        <div style="font-size: 14px;">
                            <?= date('M d, Y', strtotime($case['created_at'])) ?>
                        </div>
                    </div>
                    <div>
                        <strong style="color: #666; font-size: 12px;">Last Updated</strong>
                        <div style="font-size: 14px;">
                            <?= date('M d, Y h:i A', strtotime($case['updated_at'])) ?>
                        </div>
                    </div>
                    <?php if ($case['prosecutor_name']): ?>
                    <div>
                        <strong style="color: #666; font-size: 12px;">Assigned Prosecutor</strong>
                        <div style="font-size: 14px;">
                            <?= htmlspecialchars($case['prosecutor_name']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Barangay data from PHP
        const barangays = <?= json_encode($barangay_data) ?>;
        
        // Initialize map
        const map = L.map('map').setView([<?= $case['lat'] ?>, <?= $case['lng'] ?>], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // --- ADD GEOJSON BORDERS TO MAP ---
        fetch('../api/san_pablo_barangays.json')
            .then(response => response.json())
            .then(data => {
                L.geoJSON(data, {
                    style: function (feature) {
                        return {
                            color: "#003366",       // Dark blue border line
                            weight: 2,              // Thickness of the line
                            opacity: 0.6,           // Transparency of the line
                            fillColor: "#003366",   // Fill color
                            fillOpacity: 0.05,      // Very light fill so you can still see the streets
                            dashArray: '5, 5'       // Makes it a dashed line
                        };
                    },
                    // Optional: Add a little tooltip when hovering over a border
                    onEachFeature: function (feature, layer) {
                        if (feature.properties && feature.properties.adm4_en) {
                            layer.bindTooltip(feature.properties.adm4_en, {
                                sticky: true,
                                className: 'barangay-tooltip'
                            });
                        }
                    }
                }).addTo(map);
            })
            .catch(error => console.error("Error loading Barangay borders:", error));

        // Add draggable marker
        let marker = L.marker([<?= $case['lat'] ?>, <?= $case['lng'] ?>], {
            draggable: true
        }).addTo(map);

        marker.bindPopup("<b>Drag me to adjust location</b>").openPopup();

        // Update coordinates on marker drag
        marker.on('dragend', function(e) {
            const pos = marker.getLatLng();
            document.getElementById('lat').value = pos.lat.toFixed(6);
            document.getElementById('lng').value = pos.lng.toFixed(6);
        });

        // Update marker on map click
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            document.getElementById('lat').value = e.latlng.lat.toFixed(6);
            document.getElementById('lng').value = e.latlng.lng.toFixed(6);
        });

        // Barangay autocomplete
        const barangayInput = document.getElementById('barangay');
        const suggestions = document.getElementById('barangay-suggestions');
        
        barangayInput.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            
            if (query.length < 2) {
                suggestions.style.display = 'none';
                return;
            }
            
            const filtered = barangays.filter(b => 
                b.official.toLowerCase().includes(query) ||
                (b.alt && b.alt.toLowerCase().includes(query))
            );
            
            if (filtered.length === 0) {
                suggestions.style.display = 'none';
                return;
            }
            
            suggestions.innerHTML = filtered.map(b => `
                <div class="suggestion-item" 
                     data-id="${b.id}"
                     data-official="${b.official}" 
                     data-lat="${b.lat}" 
                     data-lng="${b.lng}">
                    <div>${b.official}</div>
                    ${b.alt ? `<div class="suggestion-alt">(${b.alt})</div>` : ''}
                </div>
            `).join('');
            
            suggestions.style.display = 'block';
            
            // Add click handlers to suggestions
            document.querySelectorAll('.suggestion-item').forEach(item => {
                item.addEventListener('click', function() {
                    const official = this.dataset.official;
                    const lat = parseFloat(this.dataset.lat);
                    const lng = parseFloat(this.dataset.lng);
                    const id = this.dataset.id;
                    barangayInput.value = official;
                    document.getElementById('lat').value = lat.toFixed(6);
                    document.getElementById('lng').value = lng.toFixed(6);
                    document.getElementById('barangay_id').value = id;
                    
                    // Update map
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 15);
                    
                    suggestions.style.display = 'none';
                });
            });
        });

        // Close suggestions on outside click
        document.addEventListener('click', function(e) {
            if (!barangayInput.contains(e.target) && !suggestions.contains(e.target)) {
                suggestions.style.display = 'none';
            }
        });

        // File input preview
        const fileInput = document.getElementById('fileInput');
        const uploadArea = document.querySelector('.file-upload-area');
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const fileNames = Array.from(this.files).map(f => f.name).join(', ');
                uploadArea.querySelector('p').textContent = `📎 ${this.files.length} file(s) selected: ${fileNames}`;
            }
        });

        // Prosecutor dropdown sync
        const prosecutorDropdown = document.getElementById('prosecutor_id');
        const prosecutorText = document.getElementById('prosecutor_text');
        
        prosecutorDropdown.addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const prosecutorName = selectedOption.text.split(' (')[0];
                prosecutorText.value = prosecutorName;
                prosecutorText.disabled = true;
                prosecutorText.style.backgroundColor = '#f0f0f0';
            } else {
                prosecutorText.disabled = false;
                prosecutorText.style.backgroundColor = 'white';
            }
        });

        if (prosecutorDropdown.value) {
            prosecutorText.disabled = true;
            prosecutorText.style.backgroundColor = '#f0f0f0';
        }

        // Form validation
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            const status = form.querySelector('[name="status"]').value;
            const oldStatus = '<?= $case['status'] ?>';
            const remarks = form.querySelector('[name="status_remarks"]').value.trim();
            
            if (status !== oldStatus && !remarks) {
                e.preventDefault();
                alert('⚠️ Please provide remarks when changing case status');
                form.querySelector('[name="status_remarks"]').focus();
                return false;
            }

            const files = fileInput.files;
            for (let i = 0; i < files.length; i++) {
                if (files[i].size > 5 * 1024 * 1024) {
                    e.preventDefault();
                    alert(`⚠️ File "${files[i].name}" exceeds 5MB limit`);
                    return false;
                }
            }
        });

        let formChanged = false;
        const formInputs = form.querySelectorAll('input, select, textarea');
        formInputs.forEach(input => {
            input.addEventListener('change', () => formChanged = true);
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });

        form.addEventListener('submit', function() {
            formChanged = false; 
        });
    </script>
</body>
</html>