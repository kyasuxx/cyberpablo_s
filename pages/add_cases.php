<?php
session_start();
require_once 'config/connection.php';
require_once 'spatial_helper.php'; // <-- ADDED: The Spatial Geofencing Tool
require_once '../vendor/autoload.php';

// 1. --- Check Authentication ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// 2. --- Fetch Data for Autocomplete & Dropdowns ---
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
$new_case_no = "CYBER-" . $year . "-" . str_pad($new_case_num_int, 4, '0', STR_PAD_LEFT);


// 4. --- Handle Form Submission (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        $case_no = $_POST['case_no'];
        $incident_type = $_POST['incident_type'];
        $incident_date = $_POST['incident_date'];
        $status = $_POST['status'];
        $modus = $_POST['modus_operandi'];

        // --- CUSTOM CRIME CHECK ---
        if ($incident_type === 'Others' && !empty(trim($_POST['other_specify']))) {
            $incident_type = 'Others - ' . trim($_POST['other_specify']);
        }

        // --- SPATIAL GEOFENCING AUTO-CORRECTOR ---
        $final_barangay = $_POST['barangay'];
        $final_barangay_id = $_POST['barangay_id'];
        $final_lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
        $final_lng = isset($_POST['lng']) ? (float)$_POST['lng'] : 0.0;

        if ($final_lat !== 0.0 && $final_lng !== 0.0) {
            $geojson_path = '../api/san_pablo_barangays.json'; 
            $true_brgy_name = getTrueBarangayFromGeoJSON($final_lat, $final_lng, $geojson_path);
            
            if ($true_brgy_name) {
                // Smart Matcher
                $search1 = $true_brgy_name;
                $search2 = "Brgy. " . $true_brgy_name;
                $search3 = "Barangay " . $true_brgy_name;
                $search4 = str_ireplace('Santa ', 'Sta. ', $search2);
                
                $brgy_stmt = $conn->prepare("
                    SELECT id, official_name FROM barangays 
                    WHERE official_name IN (?, ?, ?, ?) OR alt_name = ? 
                    LIMIT 1
                ");
                $brgy_stmt->bind_param("sssss", $search1, $search2, $search3, $search4, $search1);
                $brgy_stmt->execute();
                $brgy_data = $brgy_stmt->get_result()->fetch_assoc();
                
                if ($brgy_data) {
                    // SILENT CORRECTION
                    $final_barangay = $brgy_data['official_name'];
                    $final_barangay_id = $brgy_data['id'];
                }
            }
        } else {
            // Fallback: User didn't touch the map, get coords from the selected Barangay ID
            $brgy_stmt = $conn->prepare("SELECT lat, lng, official_name FROM barangays WHERE id = ?");
            $brgy_stmt->bind_param("i", $final_barangay_id);
            $brgy_stmt->execute();
            $brgy = $brgy_stmt->get_result()->fetch_assoc();

            if (!$brgy) {
                throw new Exception("Invalid Barangay selected.");
            }
            $final_lat = $brgy['lat'];
            $final_lng = $brgy['lng'];
            $final_barangay = $brgy['official_name'];
        }
        // ------------------------------------------

        $date_committed = !empty($_POST['date_committed']) ? $_POST['date_committed'] : null;
        $date_filed = !empty($_POST['date_filed']) ? $_POST['date_filed'] : null;
        $prosecutor_id = !empty($_POST['prosecutor_id']) ? (int)$_POST['prosecutor_id'] : null;
        $bail = !empty($_POST['bail_recommended']) ? (float)$_POST['bail_recommended'] : null;
        $hashed_victim_id = hash('sha256', ($_POST['complainant'] ?? '') . time());

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
        
        $insert_stmt->bind_param(
            "ssisddssssssssssssssdisssss",
            $case_no,
            $incident_type,
            $final_barangay,    // <-- Uses Geo-Validated Data
            $final_barangay_id, // <-- Uses Geo-Validated Data
            $final_lat,         // <-- Uses Geo-Validated Data
            $final_lng,         // <-- Uses Geo-Validated Data
            $incident_date,
            $modus,
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

        if (!$insert_stmt->execute()) {
            throw new Exception("Database insert failed: " . $insert_stmt->error);
        }
        
        $incident_id = $conn->insert_id;

        // --- 5. Handle File Uploads (SECURED) ---
        if ($incident_id && !empty($_FILES['attachments']['name'][0])) {
            $dest_dir_server = $_SERVER['DOCUMENT_ROOT'] . "/cyberpablo/uploads/cases/" . $case_no . "/";
            $dest_dir_web = "../uploads/cases/" . $case_no . "/";

            if (!is_dir($dest_dir_server)) {
                mkdir($dest_dir_server, 0755, true);
            }

            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'doc', 'csv', 'txt'];

            foreach ($_FILES['attachments']['name'] as $key => $filename) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_filename = basename($filename);
                    $file_ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                    
                    if (!in_array($file_ext, $allowed_exts)) {
                        throw new Exception("Security Error: Uploading .$file_ext files is not permitted.");
                    }

                    $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original_filename);
                    $new_filename = time() . "_" . $safe_filename; 
                    
                    $server_file_path = $dest_dir_server . $new_filename;
                    $web_file_path = $dest_dir_web . $new_filename;

                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $server_file_path)) {
                        $att_stmt = $conn->prepare(
                            "INSERT INTO attachments (incident_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)"
                        );
                        $att_stmt->bind_param("issi", $incident_id, $new_filename, $web_file_path, $user_id);
                        $att_stmt->execute();
                        $att_stmt->close();
                    }
                }
            }
        }

        // --- START OF AUDIT LOG INJECTION ---
        $audit_action = "Added a new cybercrime case: " . $case_no;
        $audit_query = "INSERT INTO audit_log (user_id, action, timestamp) VALUES (?, ?, NOW())";
        if ($audit_stmt = $conn->prepare($audit_query)) {
            $audit_stmt->bind_param("is", $user_id, $audit_action);
            $audit_stmt->execute();
            $audit_stmt->close();
        }

        $conn->commit();
        $message = "Success! Case $case_no has been created.";
        $message_type = 'success';
        
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
    <link rel="stylesheet" href="../assets/css/add_cases.css">
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

    <style>
        /* Map Container Styling */
        #map { height: 400px; width: 100%; border-radius: 8px; border: 1px solid #ddd; z-index: 1; margin-bottom: 20px; }
        
        /* Auto-Complete Suggestion Styling */
        #barangay-suggestions {
            display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; 
            border: 1px solid #cbd5e1; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
            z-index: 1000; max-height: 200px; overflow-y: auto; margin-top: 4px;
        }
        .suggestion-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover { background-color: #f8fafc; }
        .suggestion-alt { color: #64748b; font-size: 0.9em; }
    </style>
</head>
<body>
    
<?php require_once 'header.php'; ?>

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
                    <input type="text" id="case_no" name="case_no" value="<?= htmlspecialchars($new_case_no) ?>" readonly class="readonly-input">
                </div>

                <div class="form-group">
                    <label for="incident_type" class="required">Incident Type</label>
                    <select id="incident_type" name="incident_type" required>
                        <option value="">Select a type...</option>
                        <option value="Republic Act No. 10175 (Phishing)">Phishing</option>
                        <option value="Republic Act No. 10175 (Online Fraud)">Online Fraud</option>
                        <option value="Republic Act No. 10175 (Identity Theft)">Identity Theft</option>
                        <option value="Republic Act No. 10175 (Cyber Harassment)">Cyber Harassment</option>
                        <option value="Republic Act No. 10175 (Sextortion)">Sextortion</option>
                        <option value="Republic Act No. 10175 (Online Libel)">Online Libel</option>
                        <option value="Republic Act No. 10175 (Hacking)">Hacking</option>
                        <option value="Others">Others</option>
                    </select>

                    <input type="text" id="other_specify" name="other_specify" placeholder="Please specify the crime..." style="display:none; margin-top:10px; width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="position: relative;">
                    <label class="required">Barangay</label>
                    <input type="text" name="barangay" id="barangay" 
                           placeholder="Type to search..." autocomplete="off" required>
                    <input type="hidden" name="barangay_id" id="barangay_id">
                    <div id="barangay-suggestions"></div>
                </div>

                <div class="form-group">
                    <label for="incident_date" class="required">Incident Date 
                        <span id="dayOfWeekDisplay" style="color: #003366; font-weight: normal; margin-left: 8px;"></span>
                    </label>
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
                    <h2 style="margin-bottom: 1rem">Location Details</h2>
                    <p style="color: #666; font-size: 13px; margin-top: -10px; margin-bottom: 10px;">Drag the pin to set exact coordinates. The system will auto-correct the Barangay if the pin crosses a boundary.</p>
                </div>

                <div class="form-group">
                    <label>Latitude</label>
                    <input type="number" step="0.000001" name="lat" id="lat" readonly style="background-color: #f8fafc;">
                </div>
                
                <div class="form-group">
                    <label>Longitude</label>
                    <input type="number" step="0.000001" name="lng" id="lng" readonly style="background-color: #f8fafc;">
                </div>

                <div class="form-group full-width">
                    <div id="map"></div>
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

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // 1. MAP & AUTOCOMPLETE INITIALIZATION
            const barangays = <?= json_encode($barangay_data) ?>;
            const map = L.map('map').setView([14.0702, 121.3256], 13); // Default to San Pablo Center
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            let marker = L.marker([14.0702, 121.3256], { draggable: true }).addTo(map);
            marker.bindPopup("<b>Drag me to incident location</b>").openPopup();

            // Load GeoJSON Borders
            fetch('../api/san_pablo_barangays.json')
                .then(response => response.json())
                .then(data => {
                    L.geoJSON(data, {
                        style: { color: "#003366", weight: 2, opacity: 0.6, fillColor: "#003366", fillOpacity: 0.05, dashArray: '5, 5' }
                    }).addTo(map);
                }).catch(err => console.error(err));

            marker.on('dragend', function(e) {
                const pos = marker.getLatLng();
                document.getElementById('lat').value = pos.lat.toFixed(6);
                document.getElementById('lng').value = pos.lng.toFixed(6);
            });

            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                document.getElementById('lat').value = e.latlng.lat.toFixed(6);
                document.getElementById('lng').value = e.latlng.lng.toFixed(6);
            });

            const barangayInput = document.getElementById('barangay');
            const suggestions = document.getElementById('barangay-suggestions');
            
            barangayInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                if (query.length < 2) { suggestions.style.display = 'none'; return; }
                
                const filtered = barangays.filter(b => b.official.toLowerCase().includes(query) || (b.alt && b.alt.toLowerCase().includes(query)));
                if (filtered.length === 0) { suggestions.style.display = 'none'; return; }
                
                suggestions.innerHTML = filtered.map(b => `
                    <div class="suggestion-item" data-id="${b.id}" data-official="${b.official}" data-lat="${b.lat}" data-lng="${b.lng}">
                        <div>${b.official}</div>${b.alt ? `<div class="suggestion-alt">(${b.alt})</div>` : ''}
                    </div>`).join('');
                
                suggestions.style.display = 'block';
                
                document.querySelectorAll('.suggestion-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const official = this.dataset.official;
                        const lat = parseFloat(this.dataset.lat);
                        const lng = parseFloat(this.dataset.lng);
                        const id = this.dataset.id;
                        
                        barangayInput.value = official;
                        document.getElementById('barangay_id').value = id;
                        
                        if(lat && lng) {
                            document.getElementById('lat').value = lat.toFixed(6);
                            document.getElementById('lng').value = lng.toFixed(6);
                            marker.setLatLng([lat, lng]);
                            map.setView([lat, lng], 15);
                        }
                        
                        suggestions.style.display = 'none';

                        // Auto-fill address
                        const standardizedAddress = `${official}, San Pablo City, Laguna`;
                        const compAddressEl = document.getElementById('complainant_address');
                        const accAddressEl = document.getElementById('accused_address');
                        if (compAddressEl.value.trim() === "") compAddressEl.value = standardizedAddress;
                        if (accAddressEl.value.trim() === "") accAddressEl.value = standardizedAddress;
                    });
                });
            });

            document.addEventListener('click', function(e) {
                if (!barangayInput.contains(e.target) && !suggestions.contains(e.target)) {
                    suggestions.style.display = 'none';
                }
            });


            // 2. CHOICES & DYNAMIC UI
            new Choices('#prosecutor_id', { searchEnabled: true, shouldSort: false });
            new Choices('#status', { searchEnabled: false });

            const typeSelect = new Choices('#incident_type', { searchEnabled: false });
            const otherInput = document.getElementById('other_specify');

            document.getElementById('incident_type').addEventListener('change', function(event) {
                if (event.target.value === 'Others') {
                    otherInput.style.display = 'block';
                    otherInput.required = true;
                } else {
                    otherInput.style.display = 'none';
                    otherInput.required = false;
                    otherInput.value = '';
                }
            });

            // 3. DATE LOGIC
            const dateInput = document.getElementById('incident_date');
            const dayDisplay = document.getElementById('dayOfWeekDisplay');
            
            function updateDayOfWeek() {
                if(dateInput.value) {
                    const dateObj = new Date(dateInput.value);
                    const dayName = dateObj.toLocaleDateString('en-US', { timeZone: 'UTC', weekday: 'long' });
                    dayDisplay.textContent = `(${dayName})`;
                } else {
                    dayDisplay.textContent = '';
                }
            }
            
            dateInput.addEventListener('change', updateDayOfWeek);
            
            const today = new Date();
            const dateString = today.toISOString().split('T')[0];
            document.getElementById('incident_date').value = dateString;
            updateDayOfWeek();
            
            today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
            const dateTimeString = today.toISOString().slice(0, 16);
            document.getElementById('date_committed').value = dateTimeString;
        });
    </script>
</body>
</html>