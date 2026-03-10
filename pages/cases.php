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
    // Matches the 9 fields
    $sql .= " AND (i.case_no LIKE ? 
                   OR i.accused LIKE ? 
                   OR i.complainant LIKE ? 
                   OR b.official_name LIKE ? 
                   OR b.alt_name LIKE ? 
                   OR i.modus_operandi LIKE ? 
                   OR i.accused_contact LIKE ? 
                   OR i.complainant_contact LIKE ?
                   OR i.received_by LIKE ?)";
                   
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= "sssssssss";
}
if ($type) { 
    $sql .= " AND i.incident_type = ?"; 
    $params[] = $type; 
    $types .= "s"; 
}
if ($barangay) { 
    $sql .= " AND b.id = ?"; 
    $params[] = $barangay; 
    $types .= "i"; 
}
if ($status) { 
    $sql .= " AND i.status = ?"; 
    $params[] = $status; 
    $types .= "s"; 
}

// Date range filter
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

// Month filter
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

// Sorting logic
$sort_order = $_GET['sort'] ?? 'desc'; 
if ($sort_order === 'asc') {
    $sql .= " ORDER BY i.case_no ASC LIMIT ? OFFSET ?";
} else {
    $sql .= " ORDER BY i.case_no DESC LIMIT ? OFFSET ?";
}
$params[] = $limit; $params[] = $offset; $types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get barangays for dropdown
$barangay_result = $conn->query("SELECT id, official_name, alt_name FROM barangays ORDER BY official_name");

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

        <div class="modern-filters-card">
            <form method="GET" id="filterForm">
                <div class="search-bar-row">
                    <input type="text" name="search" class="main-search-input" placeholder="Search Case No, Names, or Modus Operandi keywords..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-search">Search</button>
                    <button type="button" class="btn-toggle-filters" onclick="toggleAdvancedFilters()">
                        Advanced Filters
                    </button>
                    <a href="cases.php" class="btn-clear">Clear</a>
                    <button type="button" class="btn-toggle-filters" style="background-color: #17a2b8; color: white;" 
                        onclick="window.location.href='cases.php?search=<?= urlencode($_SESSION['username']) ?>'">
                        My Cases
                    </button>
                </div>

                <div id="advancedFilters" class="advanced-filters-grid" style="display: <?= ($type || $barangay || $status || $date_from || $month || $year) ? 'grid' : 'none' ?>;">
                    
                    <div class="filter-group">
                        <label>Incident Type</label>
                        <select name="type" class="modern-select">
                            <option value="">All Types</option>
                            <option value="Republic Act No. 10175 (Phishing)" <?= $type=='Republic Act No. 10175 (Phishing)'?'selected':'' ?>>Republic Act No. 10175 (Phishing)</option>
                            <option value="Republic Act No. 10175 (Online Fraud)" <?= $type=='Republic Act No. 10175 (Online Fraud)'?'selected':'' ?>>Republic Act No. 10175 (Online Fraud)</option>
                            <option value="Republic Act No. 10175 (Cyber Harassment)" <?= $type=='Republic Act No. 10175 (Cyber Harassment)'?'selected':'' ?>>Republic Act No. 10175 (Cyber Harassment)</option>
                            <option value="Republic Act No. 10175 (Identity Theft)" <?= $type=='Republic Act No. 10175 (Identity Theft)'?'selected':'' ?>>Republic Act No. 10175 (Identity Theft)</option>
                            <option value="Republic Act No. 10175 (Sextortion)" <?= $type=='Republic Act No. 10175 (Sextortion)'?'selected':'' ?>>Republic Act No. 10175 (Sextortion)</option>
                            <option value="Republic Act No. 10175 (Online Libel)" <?= $type=='Republic Act No. 10175 (Online Libel)'?'selected':'' ?>>Republic Act No. 10175 (Online Libel)</option>
                            <option value="Republic Act No. 10175 (Hacking)" <?= $type=='Republic Act No. 10175 (Hacking)'?'selected':'' ?>>Republic Act No. 10175 (Hacking)</option>
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
                        <label>Date Range <span id="dayFrom" style="color: #003366; font-weight: normal; margin-left: 5px;"></span> <span id="dayTo" style="color: #003366; font-weight: normal; margin-left: 5px;"></span></label>
                        <div style="display:flex; gap:5px;">
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="modern-input" id="filterDateFrom">
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="modern-input" id="filterDateTo">
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
        <div style="overflow-x: auto; width: 100%;">
            <table>
                <tr>
                    <th>
                        <a href="?sort=<?= ($sort_order === 'desc') ? 'asc' : 'desc' ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type) ?>&barangay=<?= urlencode($barangay) ?>&status=<?= $status ?>" 
                        style="color: white; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                            Case No 
                            <?php if($sort_order === 'asc'): ?> ASC <?php else: ?> DESC <?php endif; ?>
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
                
                <?php if ($result->num_rows > 0): ?>
                    <?php 
                    while ($row = $result->fetch_assoc()): 
                        $prosecutor_display = 'Not Assigned';
                        if ($row['prosecutor_id'] && isset($prosecutors[$row['prosecutor_id']])) {
                            $prosecutor_display = $prosecutors[$row['prosecutor_id']];
                        } elseif ($row['prosecutor']) {
                            $prosecutor_display = $row['prosecutor'];
                        }
                    ?>
                    <tr class="case-row" onclick="toggleDetails(<?= $row['id'] ?>)">
                        <td><strong><?= $row['case_no'] ?></strong></td>
                        <td><?= htmlspecialchars($row['incident_type']) ?></td>
                        <td><?= htmlspecialchars($row['accused'] ?? 'Not Specified') ?></td>
                        <td><?= htmlspecialchars($row['complainant'] ?? 'Not Specified') ?></td>
                        <td>
                            <?= htmlspecialchars($row['official_name'] ?? $row['barangay']) ?>
                            <?php if ($row['alt_name'] && $row['official_name']): ?>
                                <div class="alt-name">(<?= $row['alt_name'] ?>)</div>
                            <?php endif; ?>
                        </td>
                        <td><?= date('l, M d, Y', strtotime($row['incident_date'])) ?></td>
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
                                    <div class="detail-value"><?= htmlspecialchars($row['nps_docket'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Date Committed</div>
                                    <div class="detail-value">
                                        <?= (!empty($row['date_committed'])) 
                                            ? date('l, M d, Y H:i', strtotime($row['date_committed'])) 
                                            : 'Not Specified' ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Date Filed</div>
                                    <div class="detail-value">
                                        <?= (!empty($row['date_filed'])) 
                                            ? date('l, M d, Y', strtotime($row['date_filed'])) 
                                            : 'Not Specified' ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Bail Recommended</div>
                                    <div class="detail-value">
                                        <?= $row['bail_recommended'] ? 'Php ' . number_format($row['bail_recommended'], 2) : 'Not Specified' ?>
                                    </div>
                                </div>
                            </div>

                            <div class="section-title">Parties Involved</div>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Complainant Address</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['complainant_address'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Complainant Contact</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['complainant_contact'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Accused Address</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['accused_address'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Accused Contact</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['accused_contact'] ?? 'Not Specified') ?></div>
                                </div>
                            </div>

                            <div class="section-title">Processing Details</div>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Received By</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['received_by'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Received Date</div>
                                    <div class="detail-value">
                                        <?= (!empty($row['received_date'])) 
                                            ? date('M d, Y H:i', strtotime($row['received_date'])) 
                                            : 'Not Specified' ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Returned To</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['returned_to'] ?? 'Not Specified') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Returned Date</div>
                                    <div class="detail-value">
                                        <?= (!empty($row['returned_date'])) 
                                            ? date('M d, Y H:i', strtotime($row['returned_date'])) 
                                            : 'Not Specified' ?>
                                    </div>
                                </div>
                            </div>

                            <div class="section-title">Investigation Details</div>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Modus Operandi</div>
                                    <div class="detail-value"><?= nl2br(htmlspecialchars($row['modus_operandi'] ?? 'Not Specified')) ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Evidence Notes</div>
                                    <div class="detail-value"><?= nl2br(htmlspecialchars($row['evidence_notes'] ?? 'Not Specified')) ?></div>
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
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #666; background: #f9f9f9;">
                            <div style="font-size: 18px; font-weight: bold; margin-bottom: 5px;">No Cases Found</div>
                            <div style="font-size: 14px;">Try adjusting your search keywords, dates, or filters to find what you are looking for.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        

        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type) ?>&barangay=<?= urlencode($barangay) ?>&status=<?= $status ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&month=<?= $month ?>&year=<?= $year ?>" 
                   class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>

            <?php endfor; ?>
        </div>

        <button onclick="startExportProcess()" type="button" class="btn-export" style="background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; float: right; font-size: 14px; font-weight: bold;">
          Export Current Data
        </button>
    </div>

<div id="otpExportModal" class="alert-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; padding: 25px; border-radius: 8px; width: 300px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h3 style="margin-top: 0; color: #003366;">Security Verification</h3>
        
        <p style="font-size: 13px; color: #555;">An OTP has been sent to your registered email address. Please enter it to authorize this export.</p>
        
        <input type="text" id="exportOtpInput" placeholder="Enter 6-digit OTP" style="width: 80%; padding: 10px; margin: 15px 0; text-align: center; font-size: 18px; letter-spacing: 2px; border: 1px solid #ccc; border-radius: 4px;" maxlength="6">
        
        <p id="otpErrorMsg" style="color: red; font-size: 12px; display: none;">Invalid OTP.</p>
        
        <div style="display: flex; gap: 10px; justify-content: center; margin-top: 15px;">
            <button onclick="closeOtpModal()" style="padding: 8px 15px; border: none; background: #ccc; border-radius: 4px; cursor: pointer;">Cancel</button>
            <button onclick="verifyExportOtp()" id="btnVerifyOtp" style="padding: 8px 15px; border: none; background: #003366; color: white; border-radius: 4px; cursor: pointer;">Verify & Export</button>
        </div>
    </div>
</div>



<script>
    window.addEventListener('load', function() {
        const searchParam = new URLSearchParams(window.location.search).get('search');
        if (searchParam && searchParam.trim() !== '') {
            const regex = new RegExp('(' + searchParam.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            document.querySelectorAll('.case-row td').forEach(td => {
                if (td.children.length === 0 || td.innerText.includes(searchParam)) {
                    td.innerHTML = td.innerHTML.replace(regex, '<mark style="background-color: #ffeb3b; padding: 0 2px; border-radius: 2px;">$1</mark>');
                }
            });
        }
    });

    // QoL FEATURE: Dynamic Day of Week Display for Filters
    function updateFilterDays() {
        const fromInput = document.getElementById('filterDateFrom');
        const toInput = document.getElementById('filterDateTo');
        const dayFrom = document.getElementById('dayFrom');
        const dayTo = document.getElementById('dayTo');

        if(fromInput && fromInput.value) {
            const d1 = new Date(fromInput.value);
            dayFrom.textContent = '(From: ' + d1.toLocaleDateString('en-US', { timeZone: 'UTC', weekday: 'long' }) + ')';
        } else {
            dayFrom.textContent = '';
        }

        if(toInput && toInput.value) {
            const d2 = new Date(toInput.value);
            dayTo.textContent = '(To: ' + d2.toLocaleDateString('en-US', { timeZone: 'UTC', weekday: 'long' }) + ')';
        } else {
            dayTo.textContent = '';
        }
    }
    
    document.getElementById('filterDateFrom').addEventListener('change', updateFilterDays);
    document.getElementById('filterDateTo').addEventListener('change', updateFilterDays);
    window.addEventListener('load', updateFilterDays);


    function toggleDetails(id) {
        const row = document.getElementById('details-' + id);
        const isVisible = row.style.display === 'table-row';
        
        document.querySelectorAll('.details-row').forEach(r => {
            r.style.display = 'none';
        });
        
        if (!isVisible) {
            row.style.display = 'table-row';
            
            const historyDiv = document.getElementById('history-' + id);
            if (historyDiv.getAttribute('data-loaded') === 'true') {
                return; 
            }

            fetch('get_case_details.php?id=' + id)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network error or not authenticated');
                    }
                    return response.json();
                })
                .then(data => {
                    historyDiv.setAttribute('data-loaded', 'true');
                    
                    const attachmentsDiv = document.getElementById('attachments-' + id);
                    attachmentsDiv.innerHTML = data.attachments;
                    historyDiv.innerHTML = data.history;

                    if (data.print_url) {
                        const printBtnHtml = `<div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px; text-align: right;">
                            <a href="${data.print_url}" target="_blank" style="background: #003366; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 13px;">
                                Generate Official Blotter
                            </a>
                        </div>`;
                        historyDiv.insertAdjacentHTML('beforeend', printBtnHtml);
                    }
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    const attachmentsDiv = document.getElementById('attachments-' + id);
                    attachmentsDiv.innerHTML = '<p style="color: red;">Could not load attachments.</p>';
                    historyDiv.innerHTML = '<p style="color: red;">Could not load history.</p>';
                });    
        }
    }

    // --- RESTORED FUNCTIONS ---
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
                input.value = '';
                event.target.querySelector('button').innerText = originalBtnText;
                event.target.querySelector('button').disabled = false;
                
                // Force reload of this specific details row
                historyDiv.setAttribute('data-loaded', 'false'); 
                toggleDetails(incidentId); 
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
        if (grid.style.display === 'none' || grid.style.display === '') {
            grid.style.display = 'grid';
        } else {
            grid.style.display = 'none';
        }
    }

    // --- REAL OTP EXPORT LOGIC ---
    let exportParams = "";

    function startExportProcess() {
        const exportBtn = document.querySelector('.btn-export');
        const originalText = exportBtn.innerText;
        exportBtn.innerText = "Sending OTP Email...";
        exportBtn.disabled = true;
        exportBtn.style.opacity = "0.7";

        // 1. Grab all the current filters... (Keep your existing param code here)
        const search = document.querySelector('input[name="search"]')?.value || "";
        const type = document.querySelector('select[name="type"]')?.value || "";
        const barangay = document.querySelector('select[name="barangay"]')?.value || "";
        const status = document.querySelector('select[name="status"]')?.value || "";
        const dateFrom = document.querySelector('input[name="date_from"]')?.value || "";
        const dateTo = document.querySelector('input[name="date_to"]')?.value || "";
        const month = document.querySelector('select[name="month"]')?.value || "";
        const year = document.querySelector('select[name="year"]')?.value || "";

        exportParams = new URLSearchParams({
            search: search, type: type, barangay: barangay, status: status,
            date_from: dateFrom, date_to: dateTo, month: month, year: year
        }).toString();

        // 2. Tell the API to generate and send the Email
        fetch('api_otp.php?action=generate')
            .then(response => response.json())
            .then(data => {
                // Reset button state
                exportBtn.innerText = originalText;
                exportBtn.disabled = false;
                exportBtn.style.opacity = "1";

                if (data.success) {
                    document.getElementById('otpExportModal').style.display = 'flex';
                    document.getElementById('exportOtpInput').value = '';
                    document.getElementById('otpErrorMsg').style.display = 'none';
                    document.getElementById('exportOtpInput').focus();
                } else {
                    alert("Error: " + data.message);
                }
            })
            .catch(error => {
                exportBtn.innerText = originalText;
                exportBtn.disabled = false;
                exportBtn.style.opacity = "1";
                console.error('Error:', error);
                alert('Failed to trigger OTP. Check console.');
            });
    }

    function verifyExportOtp() {
        const code = document.getElementById('exportOtpInput').value.trim();
        const btn = document.getElementById('btnVerifyOtp');
        
        if (code.length !== 6) {
            showOtpError("Please enter a valid 6-digit code.");
            return;
        }

        btn.innerHTML = "Verifying...";
        btn.disabled = true;

        // 3. Send the typed code to the API for verification
        let formData = new FormData();
        formData.append('code', code);

        fetch('api_otp.php?action=verify', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btn.innerHTML = "Verify & Export";
            btn.disabled = false;

            if (data.success) {
                // 4. SUCCESS! Close modal and trigger the actual Excel download
                closeOtpModal();
                window.location.href = 'export_cases.php?' + exportParams;
            } else {
                showOtpError(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            btn.innerHTML = "Verify & Export";
            btn.disabled = false;
            showOtpError("Verification failed. Try again.");
        });
    }

    function closeOtpModal() {
        document.getElementById('otpExportModal').style.display = 'none';
    }

    function showOtpError(msg) {
        const errorEl = document.getElementById('otpErrorMsg');
        errorEl.textContent = msg;
        errorEl.style.display = 'block';
    }
   
</script>
</body>
</html>