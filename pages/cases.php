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
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR b.official_name LIKE ? OR b.alt_name LIKE ? OR i.modus_operandi LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types .= "ssssss";
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
$sql .= " ORDER BY i.incident_date DESC LIMIT ? OFFSET ?";
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
    <style>
        body { margin: 0; font-family: Arial; background: #f4f6f9; }
        .header { background: #003366; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .nav a { color: #ffcc00; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .container { padding: 20px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-box { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); flex: 1; text-align: center; }
        .stat-box h3 { margin: 0; color: #003366; }
        
        /* NEW: Crime Type Stats */
        .crime-stats { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .crime-stats h3 { 
            margin: 0 0 15px 0; 
            color: #003366; 
            font-size: 18px;
        }
        .crime-stat-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding: 8px 0; 
            border-bottom: 1px solid #eee;
        }
        .crime-stat-item:last-child { border-bottom: none; }
        .crime-stat-bar {
            flex: 1;
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            margin: 0 15px;
            overflow: hidden;
        }
        .crime-stat-fill {
            height: 100%;
            background: linear-gradient(90deg, #003366, #0066cc);
            transition: width 0.3s ease;
        }
        .crime-stat-count {
            font-weight: bold;
            color: #003366;
            min-width: 40px;
            text-align: right;
        }
        
        .filters { 
            background: white; 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .filter-row {
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap; 
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-row:last-child { margin-bottom: 0; }
        .filter-label {
            font-weight: bold;
            color: #003366;
            margin-right: 10px;
            min-width: 100px;
        }
        .filters input, .filters select, .filters button { 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .filters button { background: #003366; color: white; cursor: pointer; }
        .filters button:hover { background: #004d99; }
        .filters .clear-btn { 
            background: #d32f2f; 
            color: white; 
            text-decoration: none; 
            padding: 10px 15px;
            border-radius: 5px;
            display: inline-block;
        }
        
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #003366; color: white; }
        tr:hover { background: #f8f9fa; }
        .status-open { color: #d32f2f; font-weight: bold; }
        .status-under-investigation { color: #f9a825; font-weight: bold; }
        .status-closed { color: #388e3c; font-weight: bold; }
        .pagination { text-align: center; margin: 20px 0; }
        .pagination a { margin: 0 5px; padding: 8px 12px; background: #003366; color: white; text-decoration: none; border-radius: 5px; }
        .pagination a.active { background: #ffcc00; color: #003366; }
        .export { background: #d32f2f; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; float: right; }
        .alt-name { font-size: 0.85em; color: #666; }
        .case-row { cursor: pointer; transition: background 0.2s; }
        .case-row:hover { background: #e3f2fd !important; }
        .details-row { background: #f9f9f9; }
        .details-row td { border-top: 2px solid #003366; padding: 20px !important; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; font-size: 0.95em; margin-bottom: 15px; }
        .detail-item { padding: 8px; background: white; border-radius: 5px; border-left: 3px solid #003366; }
        .detail-label { font-weight: bold; color: #003366; margin-bottom: 3px; }
        .detail-value { color: #333; }
        .section-title { 
            font-size: 16px; 
            font-weight: bold; 
            color: #003366; 
            margin: 15px 0 8px 0; 
            padding-bottom: 5px; 
            border-bottom: 2px solid #003366; 
        }
        .action-buttons { margin-top: 15px; text-align: right; }
        .action-buttons a { 
            background: #003366; 
            color: white; 
            padding: 8px 15px; 
            border-radius: 5px; 
            text-decoration: none; 
            margin-left: 5px; 
            display: inline-block;
        }
        .action-buttons a:hover { background: #004d99; }
        .action-buttons a.print { background: #d32f2f; }
        .action-buttons a.print:hover { background: #b71c1c; }
        
        /* Active filter indicator */
        .active-filters {
            background: #e3f2fd;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-tag {
            background: #003366;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CYBERPABLO</h1>
        <div class="nav">
            <a href="dashboard.php">Map</a>
            <a href="cases.php">Cases</a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="import_cases.php">Import Excel</a>
                <a href="add_cases.php">Add New Case</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </div>

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

        <!-- NEW: Crime Type Statistics -->
        <?php if ($crime_stats->num_rows > 0): ?>
        <div class="crime-stats">
            <h3>📊 Crime Type Distribution <?= ($date_from || $date_to || $month || $year) ? '(Filtered Period)' : '(All Time)' ?></h3>
            <?php 
            $max_count = 0;
            $crime_data = [];
            while ($stat = $crime_stats->fetch_assoc()) {
                $crime_data[] = $stat;
                if ($stat['count'] > $max_count) $max_count = $stat['count'];
            }
            
            foreach ($crime_data as $stat): 
                $percentage = ($max_count > 0) ? ($stat['count'] / $max_count * 100) : 0;
            ?>
            <div class="crime-stat-item">
                <span style="min-width: 150px; font-weight: 600;"><?= htmlspecialchars($stat['incident_type']) ?></span>
                <div class="crime-stat-bar">
                    <div class="crime-stat-fill" style="width: <?= $percentage ?>%;"></div>
                </div>
                <span class="crime-stat-count"><?= $stat['count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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
        <div class="filters">
            <form method="GET">
                <!-- Row 1: Basic Filters -->
                <div class="filter-row">
                    <span class="filter-label">Search:</span>
                    <input type="text" name="search" placeholder="Case No, Accused, Barangay..." 
                           value="<?= htmlspecialchars($search) ?>" style="flex: 1; min-width: 250px;">
                    
                    <span class="filter-label">Type:</span>
                    <select name="type">
                        <option value="">All Types</option>
                        <option value="Phishing" <?= $type=='Phishing'?'selected':'' ?>>Phishing</option>
                        <option value="Online Fraud" <?= $type=='Online Fraud'?'selected':'' ?>>Online Fraud</option>
                        <option value="Cyber Harassment" <?= $type=='Cyber Harassment'?'selected':'' ?>>Cyber Harassment</option>
                        <option value="Identity Theft" <?= $type=='Identity Theft'?'selected':'' ?>>Identity Theft</option>
                        <option value="Others" <?= $type=='Others'?'selected':'' ?>>Others</option>
                    </select>
                    
                    <span class="filter-label">Status:</span>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                        <option value="Under Investigation" <?= $status=='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                        <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                    </select>
                </div>

                <!-- Row 2: Location -->
                <div class="filter-row">
                    <span class="filter-label">Barangay:</span>
                    <select name="barangay" style="flex: 1; max-width: 300px;">
                        <option value="">All Barangays</option>
                        <?php 
                        $barangay_result->data_seek(0);
                        while ($b = $barangay_result->fetch_assoc()): 
                        ?>
                            <option value="<?= $b['id'] ?>" <?= $barangay==$b['id']?'selected':'' ?>>
                                <?= $b['official_name'] ?><?= $b['alt_name'] ? " ({$b['alt_name']})" : '' ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Row 3: Date Filters -->
                <div class="filter-row">
                    <span class="filter-label">Date Range:</span>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                           placeholder="From Date">
                    <span style="margin: 0 5px;">to</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                           placeholder="To Date">
                    
                    <span style="margin: 0 15px; color: #999;">OR</span>
                    
                    <span class="filter-label">Month:</span>
                    <select name="month">
                        <option value="">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $month==$m?'selected':'' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    
                    <span class="filter-label">Year:</span>
                    <select name="year">
                        <option value="">All Years</option>
                        <?php while ($y = $years_result->fetch_assoc()): ?>
                            <option value="<?= $y['year'] ?>" <?= $year==$y['year']?'selected':'' ?>>
                                <?= $y['year'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="filter-row">
                    <button type="submit">🔍 Apply Filters</button>
                    <a href="cases.php" class="clear-btn">✖ Clear All</a>
                </div>
            </form>
        </div>

        <!-- Table -->
        <table>
            <tr>
                <th>Case No</th>
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
                        <a href="print_blotter.php?id=<?= $row['id'] ?>" target="_blank" class="print">Print Blotter</a>
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

        <a href="export_cases.php" class="export">Export to Excel</a>
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
                })
                .catch(error => {
                    console.error('Error fetching details:', error);
                    const attachmentsDiv = document.getElementById('attachments-' + id);
                    attachmentsDiv.innerHTML = '<p style="color: red;">Could not load attachments.</p>';
                    historyDiv.innerHTML = '<p style="color: red;">Could not load history.</p>';
                });
        }
    }
</script>
</body>
</html>
                            