<?php
session_start();
require_once 'config/connection.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Filters
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Load prosecutors
$prosecutors = [];
$presult = $conn->query("SELECT id, full_name FROM prosecutors WHERE is_active = 1");
while ($p = $presult->fetch_assoc()) {
    $prosecutors[$p['id']] = $p['full_name'];
}

// Main query with JOIN - FIXED: Use incident coordinates
$sql = "SELECT i.*, 
               i.lat as incident_lat, 
               i.lng as incident_lng,
               b.lat as brgy_lat, 
               b.lng as brgy_lng, 
               b.official_name, 
               b.alt_name
        FROM incidents i 
        LEFT JOIN barangays b ON i.barangay_id = b.id
        WHERE 1=1";
$params = []; $types = "";

// Apply filters
if ($search) {
    // UPDATED: Added OR checks for 'accused_contact' and 'complainant_contact'
    $sql .= " AND (i.case_no LIKE ? 
                   OR i.accused LIKE ? 
                   OR i.complainant LIKE ? 
                   OR b.official_name LIKE ? 
                   OR b.alt_name LIKE ? 
                   OR i.modus_operandi LIKE ? 
                   OR i.accused_contact LIKE ? 
                   OR i.complainant_contact LIKE ?)";
                   
    $like = "%$search%";
    
    // UPDATED: Added 2 more $like to the array (Total 8 items now)
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
    
    // UPDATED: Added 2 more 's' to the type string (Total 8 's')
    $types .= "ssssssss";
}
if ($type) { 
    $sql .= " AND i.incident_type = ?"; 
    $params[] = $type; 
    $types .= "s"; 
}
if ($barangay) { 
    $sql .= " AND b.id = ?"; 
    $params[] = $barangay; 
    $types .= "i"; // 'i' for integer
}
if ($status) { 
    $sql .= " AND i.status = ?"; 
    $params[] = $status; 
    $types .= "s"; 
}

// NEW: Date range filter
if ($date_from && $date_to) {
    $sql .= " AND i.incident_date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
} elseif ($date_from) {
    $sql .= " AND i.incident_date >= ?";
    $params[] = $date_from;
    $types .= "s";
} elseif ($date_to) {
    $sql .= " AND i.incident_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// NEW: Month filter
if ($month && $year) {
    $sql .= " AND MONTH(i.incident_date) = ? AND YEAR(i.incident_date) = ?";
    $params[] = $month;
    $params[] = $year;
    $types .= "ii";
} elseif ($month) {
    $sql .= " AND MONTH(i.incident_date) = ?";
    $params[] = $month;
    $types .= "i";
} elseif ($year) {
    $sql .= " AND YEAR(i.incident_date) = ?";
    $params[] = $year;
    $types .= "i";
}

// Count query
$count_sql = preg_replace('/SELECT i\.\*, .+ FROM/s', 'SELECT COUNT(*) FROM', $sql);
$count_stmt = $conn->prepare($count_sql);
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_row()[0];
$pages = ceil($total / $limit);

// Main query with pagination
// $sql .= " ORDER BY i.incident_date DESC LIMIT ? OFFSET ?";
// --- SORTING ALGORITHM START ---
// Check if user clicked a sort button, otherwise default to Newest Case First
$sort_order = $_GET['sort'] ?? 'desc'; 

if ($sort_order === 'asc') {
    // Oldest First (CYBER-2025-0001 -> CYBER-2026-0001)
    $sql .= " ORDER BY i.case_no ASC LIMIT ? OFFSET ?";
} else {
    // Newest First (CYBER-2026-0001 -> CYBER-2025-0001)
    $sql .= " ORDER BY i.case_no DESC LIMIT ? OFFSET ?";
}
// --- SORTING ALGORITHM END ---
$params[] = $limit; $params[] = $offset; $types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get barangays for dropdown
$barangay_result = $conn->query("SELECT id, official_name, alt_name FROM barangays ORDER BY official_name");

// NEW: Get crime type statistics for current filters
$stats_sql = "SELECT i.incident_type, COUNT(*) as count
              FROM incidents i 
              LEFT JOIN barangays b ON i.barangay_id = b.id
              WHERE 1=1";

// Apply same filters for stats
$stats_params = [];
$stats_types = "";
if ($search) {
    $stats_sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR b.official_name LIKE ? OR b.alt_name LIKE ? OR i.modus_operandi LIKE ?)";
    $like = "%$search%";
    $stats_params = array_merge($stats_params, [$like, $like, $like, $like, $like, $like]);
    $stats_types .= "ssssss";
}
if ($type) { $stats_sql .= " AND i.incident_type = ?"; $stats_params[] = $type; $stats_types .= "s"; }
if ($barangay) { $stats_sql .= " AND b.id = ?"; $stats_params[] = $barangay; $stats_types .= "i"; }
if ($status) { $stats_sql .= " AND i.status = ?"; $stats_params[] = $status; $stats_types .= "s"; }
if ($date_from && $date_to) { $stats_sql .= " AND i.incident_date BETWEEN ? AND ?"; $stats_params[] = $date_from; $stats_params[] = $date_to; $stats_types .= "ss"; }
elseif ($date_from) { $stats_sql .= " AND i.incident_date >= ?"; $stats_params[] = $date_from; $stats_types .= "s"; }
elseif ($date_to) { $stats_sql .= " AND i.incident_date <= ?"; $stats_params[] = $date_to; $stats_types .= "s"; }
if ($month && $year) { $stats_sql .= " AND MONTH(i.incident_date) = ? AND YEAR(i.incident_date) = ?"; $stats_params[] = $month; $stats_params[] = $year; $stats_types .= "ii"; }
elseif ($month) { $stats_sql .= " AND MONTH(i.incident_date) = ?"; $stats_params[] = $month; $stats_types .= "i"; }
elseif ($year) { $stats_sql .= " AND YEAR(i.incident_date) = ?"; $stats_params[] = $year; $stats_types .= "i"; }

$stats_sql .= " GROUP BY i.incident_type ORDER BY count DESC";
$stats_stmt = $conn->prepare($stats_sql);
if ($stats_params) $stats_stmt->bind_param($stats_types, ...$stats_params);
$stats_stmt->execute();
$crime_stats = $stats_stmt->get_result();

// Get available years for dropdown
$years_result = $conn->query("SELECT DISTINCT YEAR(incident_date) as year FROM incidents ORDER BY year DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cases - CyberPablo</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/cases.css">
</head>
<body>

<?php require_once 'header.php'; ?>

    <div class="container">
        <h2>Cases Dashboard (<?= $total ?> Total)</h2>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Open'")->fetch_row()[0] ?></h3>
                <p>Open Cases</p>
            </div>
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Under Investigation'")->fetch_row()[0] ?></h3>
                <p>Investigating</p>
            </div>
            <div class="stat-box">
                <h3><?= $conn->query("SELECT COUNT(*) FROM incidents WHERE status='Closed'")->fetch_row()[0] ?></h3>
                <p>Closed</p>
            </div>
        </div>

        

        <!-- Active Filters Display -->
        <?php if ($search || $type || $barangay || $status || $date_from || $date_to || $month || $year): ?>
        <div class="active-filters">
            <strong>Active Filters:</strong>
            <?php if ($search): ?><span class="filter-tag">Search: <?= htmlspecialchars($search) ?></span><?php endif; ?>
            <?php if ($type): ?><span class="filter-tag">Type: <?= htmlspecialchars($type) ?></span><?php endif; ?>
            <?php if ($barangay): ?><span class="filter-tag">Barangay: <?= htmlspecialchars($barangay) ?></span><?php endif; ?>
            <?php if ($status): ?><span class="filter-tag">Status: <?= htmlspecialchars($status) ?></span><?php endif; ?>
            <?php if ($date_from && $date_to): ?>
                <span class="filter-tag">Date: <?= date('M d, Y', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?></span>
            <?php elseif ($date_from): ?>
                <span class="filter-tag">From: <?= date('M d, Y', strtotime($date_from)) ?></span>
            <?php elseif ($date_to): ?>
                <span class="filter-tag">Until: <?= date('M d, Y', strtotime($date_to)) ?></span>
            <?php endif; ?>
            <?php if ($month): ?><span class="filter-tag">Month: <?= date('F', mktime(0, 0, 0, $month, 1)) ?></span><?php endif; ?>
            <?php if ($year): ?><span class="filter-tag">Year: <?= $year ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="modern-filters-card">
            <form method="GET" id="filterForm">
                <div class="search-bar-row">
                    <input type="text" name="search" class="main-search-input" placeholder="Search Case No, Accused, Complainant..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-search">Search</button>
                    <button type="button" class="btn-toggle-filters" onclick="toggleAdvancedFilters()">
                        Advanced Filters
                    </button>
                    <a href="cases.php" class="btn-clear">✖ Clear</a>
                </div>

                <div id="advancedFilters" class="advanced-filters-grid" style="display: <?= ($type || $barangay || $status || $date_from || $month || $year) ? 'grid' : 'none' ?>;">
                    
                    <div class="filter-group">
                        <label>Incident Type</label>
                        <select name="type" class="modern-select">
                            <option value="">All Types</option>
                            <option value="Phishing" <?= $type=='Phishing'?'selected':'' ?>>Phishing</option>
                            <option value="Online Fraud" <?= $type=='Online Fraud'?'selected':'' ?>>Online Fraud</option>
                            <option value="Cyber Harassment" <?= $type=='Cyber Harassment'?'selected':'' ?>>Cyber Harassment</option>
                            <option value="Identity Theft" <?= $type=='Identity Theft'?'selected':'' ?>>Identity Theft</option>
                            <option value="Others" <?= $type=='Others'?'selected':'' ?>>Others</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="modern-select">
                            <option value="">All Status</option>
                            <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                            <option value="Under Investigation" <?= $status=='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                            <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Barangay</label>
                        <select name="barangay" class="modern-select">
                            <option value="">All Barangays</option>
                            <?php 
                            $barangay_result->data_seek(0);
                            while ($b = $barangay_result->fetch_assoc()): 
                            ?>
                                <option value="<?= $b['id'] ?>" <?= $barangay==$b['id']?'selected':'' ?>>
                                    <?= $b['official_name'] ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Date Range (From - To)</label>
                        <div style="display:flex; gap:5px;">
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="modern-input">
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="modern-input">
                        </div>
                    </div>

                    <div class="filter-group">
                        <label>Specific Month / Year</label>
                        <div style="display:flex; gap:5px;">
                            <select name="month" class="modern-select">
                                <option value="">Month</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $month==$m?'selected':'' ?>><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="year" class="modern-select">
                                <option value="">Year</option>
                                <?php $years_result->data_seek(0); while ($y = $years_result->fetch_assoc()): ?>
                                    <option value="<?= $y['year'] ?>" <?= $year==$y['year']?'selected':'' ?>><?= $y['year'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                </div>
            </form>
        </div>

        <!-- Table -->
        <table>
            <tr>
                <th>
                    <a href="?sort=<?= ($sort_order === 'desc') ? 'asc' : 'desc' ?>&search=<?= urlencode($search) ?>&type=<?= $type ?>&barangay=<?= urlencode($barangay) ?>&status=<?= $status ?>" 
                    style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                        Case No 
                        <?php if($sort_order === 'asc'): ?> ▲ <?php else: ?> ▼ <?php endif; ?>
                    </a>
                </th>
                <th>Type</th>
                <th>Accused</th>
                <th>Complainant</th>
                <th>Barangay</th>
                <th>Incident Date</th>
                <th>Status</th>
                <th>Map</th>
            </tr>
            <?php 
            while ($row = $result->fetch_assoc()): 
                // Get prosecutor name
                $prosecutor_display = '—';
                if ($row['prosecutor_id'] && isset($prosecutors[$row['prosecutor_id']])) {
                    $prosecutor_display = $prosecutors[$row['prosecutor_id']];
                } elseif ($row['prosecutor']) {
                    $prosecutor_display = $row['prosecutor'];
                }
            ?>
            <tr class="case-row" onclick="toggleDetails(<?= $row['id'] ?>)">
                <td><strong><?= $row['case_no'] ?></strong></td>
                <td><?= $row['incident_type'] ?></td>
                <td><?= htmlspecialchars($row['accused'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['complainant'] ?? '—') ?></td>
                <td>
                    <?= htmlspecialchars($row['official_name'] ?? $row['barangay']) ?>
                    <?php if ($row['alt_name'] && $row['official_name']): ?>
                        <div class="alt-name">(<?= $row['alt_name'] ?>)</div>
                    <?php endif; ?>
                </td>
                <td><?= date('M d, Y', strtotime($row['incident_date'])) ?></td>
                <td>
                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                        <?= $row['status'] ?>
                    </span>
                </td>
                <td>
                    <?php if ($row['incident_lat'] && $row['incident_lng']): ?>
                        <a href="dashboard.php?lat=<?= $row['incident_lat'] ?>&lng=<?= $row['incident_lng'] ?>&case=<?= urlencode($row['case_no']) ?>" 
                           style="color:green; font-weight:bold; text-decoration:none;">View on Map</a>
                    <?php else: ?>
                        <span style="color:orange;">No GPS</span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <!-- EXPANDABLE DETAILS ROW -->
            <tr id="details-<?= $row['id'] ?>" class="details-row" style="display:none;">
                <td colspan="8">
                    <div class="section-title">Case Information</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Prosecutor</div>
                            <div class="detail-value"><?= htmlspecialchars($prosecutor_display) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">NPS Docket</div>
                            <div class="detail-value"><?= htmlspecialchars($row['nps_docket'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date Committed</div>
                            <div class="detail-value">
                                <?= (!empty($row['date_committed'])) 
                                    ? date('M d, Y H:i', strtotime($row['date_committed'])) 
                                    : '—' ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date Filed</div>
                            <div class="detail-value">
                                <?= (!empty($row['date_filed'])) 
                                    ? date('M d, Y', strtotime($row['date_filed'])) 
                                    : '—' ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Bail Recommended</div>
                            <div class="detail-value">
                                <?= $row['bail_recommended'] ? '₱' . number_format($row['bail_recommended'], 2) : '—' ?>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">Parties Involved</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Complainant Address</div>
                            <div class="detail-value"><?= htmlspecialchars($row['complainant_address'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Complainant Contact</div>
                            <div class="detail-value"><?= htmlspecialchars($row['complainant_contact'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Accused Address</div>
                            <div class="detail-value"><?= htmlspecialchars($row['accused_address'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Accused Contact</div>
                            <div class="detail-value"><?= htmlspecialchars($row['accused_contact'] ?? '—') ?></div>
                        </div>
                    </div>

                    <div class="section-title">Processing Details</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Received By</div>
                            <div class="detail-value"><?= htmlspecialchars($row['received_by'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Received Date</div>
                            <div class="detail-value">
                                <?= (!empty($row['received_date'])) 
                                    ? date('M d, Y H:i', strtotime($row['received_date'])) 
                                    : '—' ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Returned To</div>
                            <div class="detail-value"><?= htmlspecialchars($row['returned_to'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Returned Date</div>
                            <div class="detail-value">
                                <?= (!empty($row['returned_date'])) 
                                    ? date('M d, Y H:i', strtotime($row['returned_date'])) 
                                    : '—' ?>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">Investigation Details</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Modus Operandi</div>
                            <div class="detail-value"><?= nl2br(htmlspecialchars($row['modus_operandi'] ?? '—')) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Evidence Notes</div>
                            <div class="detail-value"><?= nl2br(htmlspecialchars($row['evidence_notes'] ?? '—')) ?></div>
                        </div>
                    </div>

                    <div class="section-title">Attachments</div>
                    <div id="attachments-<?= $row['id'] ?>" class="details-content-loader">
                        <p style="color: #999; font-style: italic; margin: 10px 0;">Loading attachments...</p>
                    </div>

                    <div class="section-title">Status History</div>
                    <div id="history-<?= $row['id'] ?>" class="details-content-loader">
                        <p style="color: #999; font-style: italic; margin: 10px 0;">Loading history...</p>
                    </div>

                    <div class="action-buttons">
                        <a href="edit_cases.php?id=<?= urlencode($row['case_no']) ?>">Edit Case</a>
                        <!-- <a href="print_blotter.php?id=<?= $row['id'] ?>" target="_blank" class="action-btn print-btn">Print Blotter </a> -->
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= $type ?>&barangay=<?= urlencode($barangay) ?>&status=<?= $status ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&month=<?= $month ?>&year=<?= $year ?>" 
                   class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>

            <?php endfor; ?>
        </div>

        <button onclick="exportSmartData()" class="export" style="background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; float: right; font-size: 14px; font-weight: bold;">
          Export Current Data
        </button>
    </div>

<div id="otpModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:8px; width:350px; text-align:center; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h3 style="color:#003366; margin-top:0;">Security Verification</h3>
        <p style="font-size:13px; color:#666;">To prevent unauthorized data export, a verification code has been generated.</p>
        
        <input type="text" id="otpInput" placeholder="Enter 6-digit Code" maxlength="6" style="width:100%; padding:12px; margin:15px 0; text-align:center; font-size:20px; letter-spacing:5px; border:2px solid #ccc; border-radius:5px;">
        
        <div style="display:flex; gap:10px;">
            <button onclick="closeOtpModal()" style="flex:1; padding:10px; background:#ddd; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
            <button onclick="verifyAndExport()" style="flex:1; padding:10px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">Verify & Export</button>
        </div>
    </div>
</div>

<script>
    function toggleDetails(id) {
        const row = document.getElementById('details-' + id);
        const isVisible = row.style.display === 'table-row';
        
        // Close all other details rows
        document.querySelectorAll('.details-row').forEach(r => {
            r.style.display = 'none';
        });
        
        // If we are opening this row
        if (!isVisible) {
            row.style.display = 'table-row';
            
            // Check if we already loaded the data
            const historyDiv = document.getElementById('history-' + id);
            if (historyDiv.getAttribute('data-loaded') === 'true') {
                return; // Data is already loaded, just show the row
            }

            // --- NEW: Fetch data from our PHP file ---
            fetch('get_case_details.php?id=' + id)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network error or not authenticated');
                    }
                    return response.json();
                })
                .then(data => {
                    // Mark as loaded
                    historyDiv.setAttribute('data-loaded', 'true');
                    
                    // Inject the new HTML
                    const attachmentsDiv = document.getElementById('attachments-' + id);
                    attachmentsDiv.innerHTML = data.attachments;
                    historyDiv.innerHTML = data.history;

                    // --- ADD THIS BLOCK HERE ---
                    // Dynamic Print Button Injection
                    // Ensure you have a container for this button in your HTML structure, 
                    // or append it to an existing container like 'detailsRow'
                    
                    // Option A: Append to the History Section (Easiest)
                    if (data.print_url) {
                        const printBtnHtml = `<div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px; text-align: right;">
                            <a href="${data.print_url}" target="_blank" style="background: #003366; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 13px;">
                                Generate Official Blotter
                            </a>
                        </div>`;
                        historyDiv.insertAdjacentHTML('beforeend', printBtnHtml);
                    }
                    // ---------------------------
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    const attachmentsDiv = document.getElementById('attachments-' + id);
                    attachmentsDiv.innerHTML = '<p style="color: red;">Could not load attachments.</p>';
                    historyDiv.innerHTML = '<p style="color: red;">Could not load history.</p>';
                });    
        }
    }

    function exportSmartData() {
        // 1. Get current values from the filter form
        const search = document.querySelector('input[name="search"]').value;
        const type = document.querySelector('select[name="type"]').value;
        const barangay = document.querySelector('select[name="barangay"]').value;
        const status = document.querySelector('select[name="status"]').value;
        const dateFrom = document.querySelector('input[name="date_from"]').value;
        const dateTo = document.querySelector('input[name="date_to"]').value;
        const month = document.querySelector('select[name="month"]').value;
        const year = document.querySelector('select[name="year"]').value;

        // 2. Build the URL params
        const params = new URLSearchParams({
            search: search,
            type: type,
            barangay: barangay,
            status: status,
            date_from: dateFrom,
            date_to: dateTo,
            month: month,
            year: year
        });

        // 3. Trigger the download
        window.location.href = 'export_cases.php?' + params.toString();
    }

function submitNote(event, incidentId) {
        event.preventDefault(); // Stop page reload
        
        const input = document.getElementById('note-' + incidentId);
        const typeSelect = document.getElementById('type-' + incidentId);
        const note = input.value;
        const type = typeSelect.value;
        const historyDiv = document.getElementById('history-' + incidentId);

        // UI: Show loading state
        const originalBtnText = event.target.querySelector('button').innerText;
        event.target.querySelector('button').innerText = "Saving...";
        event.target.querySelector('button').disabled = true;

        // Send to backend
        const formData = new FormData();
        formData.append('incident_id', incidentId);
        formData.append('log_type', type);
        formData.append('note', note);

        fetch('add_case_notes.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Clear input
                input.value = '';
                // Reset button
                event.target.querySelector('button').innerText = originalBtnText;
                event.target.querySelector('button').disabled = false;
                
                // Force reload of this specific details row to show new data
                historyDiv.setAttribute('data-loaded', 'false'); 
                toggleDetails(incidentId); // Re-open triggers re-fetch
            } else {
                alert('Error: ' + data.message);
                event.target.querySelector('button').disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('A network error occurred.');
        });
    }

    function toggleAdvancedFilters() {
        const grid = document.getElementById('advancedFilters');
        if (grid.style.display === 'none') {
            grid.style.display = 'grid';
        } else {
            grid.style.display = 'none';
        }
    }

    // --- SECURE EXPORT LOGIC WITH OTP ---
let exportParams = "";

function exportSmartData() {
    // 1. Get current filter values
    const search = document.querySelector('input[name="search"]').value;
    const type = document.querySelector('select[name="type"]').value;
    const barangay = document.querySelector('select[name="barangay"]').value;
    const status = document.querySelector('select[name="status"]').value;
    const dateFrom = document.querySelector('input[name="date_from"]').value;
    const dateTo = document.querySelector('input[name="date_to"]').value;
    const month = document.querySelector('select[name="month"]').value;
    const year = document.querySelector('select[name="year"]').value;

    exportParams = new URLSearchParams({
        search: search, type: type, barangay: barangay, status: status,
        date_from: dateFrom, date_to: dateTo, month: month, year: year
    }).toString();

    // 2. Trigger OTP Generation
    fetch('api_otp.php?action=generate')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                // FOR THESIS DEFENSE: We alert the code so you can type it.
                // In production, this fetch() would trigger an SMS API (like Semaphore).
                alert("SYSTEM MESSAGE (Simulated SMS):\n\nYour CyberPablo Export Code is: " + data.code);
                
                // Show the modal
                document.getElementById('otpModal').style.display = 'flex';
                document.getElementById('otpInput').value = '';
                document.getElementById('otpInput').focus();
            }
        });
}

function closeOtpModal() {
    document.getElementById('otpModal').style.display = 'none';
}

function verifyAndExport() {
    const code = document.getElementById('otpInput').value;
    
    // 3. Verify the Code
    fetch('api_otp.php?action=verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'code=' + code
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            alert("Verification Successful. Downloading data...");
            closeOtpModal();
            // 4. Actually download the file
            window.location.href = 'export_cases.php?' + exportParams;
        } else {
            alert("Invalid Code. Export denied.");
        }
    });
}
</script>
</body>
</html>
                            