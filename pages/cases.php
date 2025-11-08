<?php
session_start();
require_once 'config/connection.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Filters
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Load prosecutors
$prosecutors = [];
$presult = $conn->query("SELECT id, full_name FROM prosecutors WHERE is_active = 1");
while ($p = $presult->fetch_assoc()) {
    $prosecutors[$p['id']] = $p['full_name'];
}

// Main query with JOIN
$sql = "SELECT i.*, b.lat, b.lng, b.official_name, b.alt_name
        FROM incidents i 
        LEFT JOIN barangays b ON (
            i.barangay = b.official_name OR 
            i.barangay = b.alt_name OR
            i.barangay = REPLACE(b.official_name, 'Brgy. ', '') OR
            i.barangay = REPLACE(b.alt_name, 'Brgy. ', '')
        )
        WHERE 1=1";
$params = []; $types = "";

// Apply filters
if ($search) {
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR b.official_name LIKE ? OR b.alt_name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= "sssss";
}
if ($type) { $sql .= " AND i.incident_type = ?"; $params[] = $type; $types .= "s"; }
if ($barangay) { $sql .= " AND (b.official_name = ? OR b.alt_name = ?)"; $params[] = $barangay; $params[] = $barangay; $types .= "ss"; }
if ($status) { $sql .= " AND i.status = ?"; $params[] = $status; $types .= "s"; }

// Count query
$count_sql = preg_replace('/SELECT i\.\*, b\.lat, b\.lng, b\.official_name, b\.alt_name/', 'SELECT COUNT(*)', $sql);
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
$barangay_result = $conn->query("SELECT official_name, alt_name FROM barangays ORDER BY official_name");
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
        .filters { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filters input, .filters select, .filters button { padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .filters button { background: #003366; color: white; cursor: pointer; }
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

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display: flex; gap: 10px; flex: 1;">
                <input type="text" name="search" placeholder="Search Case No, Accused, Barangay..." value="<?= htmlspecialchars($search) ?>">
                <select name="type">
                    <option value="">All Types</option>
                    <option value="Phishing" <?= $type=='Phishing'?'selected':'' ?>>Phishing</option>
                    <option value="Online Fraud" <?= $type=='Online Fraud'?'selected':'' ?>>Online Fraud</option>
                    <option value="Cyber Harassment" <?= $type=='Cyber Harassment'?'selected':'' ?>>Cyber Harassment</option>
                    <option value="Identity Theft" <?= $type=='Identity Theft'?'selected':'' ?>>Identity Theft</option>
                    <option value="Others" <?= $type=='Others'?'selected':'' ?>>Others</option>
                </select>
                <select name="barangay">
                    <option value="">All Barangays</option>
                    <?php while ($b = $barangay_result->fetch_assoc()): ?>
                        <option value="<?= $b['official_name'] ?>" <?= $barangay==$b['official_name']?'selected':'' ?>>
                            <?= $b['official_name'] ?><?= $b['alt_name'] ? " ({$b['alt_name']})" : '' ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                    <option value="Under Investigation" <?= $status=='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                    <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                </select>
                <button type="submit">Filter</button>
                <a href="cases.php" style="color:#d32f2f;">Clear</a>
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
                <th>Date Filed</th>
                <th>Status</th>
                <th>Map</th>
            </tr>
            <?php 
            $barangay_result->data_seek(0);
            while ($row = $result->fetch_assoc()): 
                // Get prosecutor name
                $prosecutor_display = '—';
                if ($row['prosecutor_id'] && isset($prosecutors[$row['prosecutor_id']])) {
                    $prosecutor_display = $prosecutors[$row['prosecutor_id']];
                } elseif ($row['prosecutor']) {
                    $prosecutor_display = $row['prosecutor'];
                }

                // Fetch status history
                $hist_stmt = $conn->prepare("
                    SELECT h.*, u.username 
                    FROM case_status_history h 
                    LEFT JOIN users u ON h.changed_by = u.id 
                    WHERE h.incident_id = ? 
                    ORDER BY h.changed_at DESC
                ");
                $hist_stmt->bind_param("i", $row['id']);
                $hist_stmt->execute();
                $history = $hist_stmt->get_result();

                // Fetch attachments
                $att_stmt = $conn->prepare("SELECT * FROM attachments WHERE incident_id = ?");
                $att_stmt->bind_param("i", $row['id']);
                $att_stmt->execute();
                $attachments = $att_stmt->get_result();
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
                <td>
                    <?php 
                    if (!empty($row['date_filed']) && $row['date_filed'] !== '0000-00-00') {
                        echo date('M d, Y', strtotime($row['date_filed']));
                    } else {
                        echo '<span style="color:#999;">Not set</span>';
                    }
                    ?>
                </td>
                <td>
                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                        <?= $row['status'] ?>
                    </span>
                </td>
                <td>
                    <?php if ($row['lat'] && $row['lng']): ?>
                        <a href="dashboard.php?lat=<?= $row['lat'] ?>&lng=<?= $row['lng'] ?>&case=<?= urlencode($row['case_no']) ?>" 
                           style="color:green; font-weight:bold; text-decoration:none;">View on Map</a>
                    <?php else: ?>
                        <span style="color:orange;">No GPS</span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <!-- EXPANDABLE DETAILS ROW -->
            <tr id="details-<?= $row['id'] ?>" class="details-row" style="display:none;">
                <td colspan="8">
                    <!-- Case Information -->
                    <div class="section-title">📋 Case Information</div>
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
                                <?= (strtotime($row['date_committed']) > 0) ? date('M d, Y H:i', strtotime($row['date_committed'])) : '—' ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Bail Recommended</div>
                            <div class="detail-value">
                                <?= $row['bail_recommended'] ? '₱' . number_format($row['bail_recommended'], 2) : '—' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Parties Information -->
                    <div class="section-title">👥 Parties Involved</div>
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

                    <!-- Processing Details -->
                    <div class="section-title">📄 Processing Details</div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Received By</div>
                            <div class="detail-value"><?= htmlspecialchars($row['received_by'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Received Date</div>
                            <div class="detail-value">
                                <?= (strtotime($row['received_date']) > 0) ? date('M d, Y H:i', strtotime($row['received_date'])) : '—' ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Returned To</div>
                            <div class="detail-value"><?= htmlspecialchars($row['returned_to'] ?? '—') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Returned Date</div>
                            <div class="detail-value">
                                <?= (strtotime($row['returned_date']) > 0) ? date('M d, Y H:i', strtotime($row['returned_date'])) : '—' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Modus & Evidence -->
                    <div class="section-title">🔍 Investigation Details</div>
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

                    <!-- Attachments -->
                    <div class="section-title">📎 Attachments</div>
                    <?php if ($attachments->num_rows > 0): ?>
                        <ul style="margin: 10px 0; padding-left: 25px;">
                            <?php while ($a = $attachments->fetch_assoc()): ?>
                                <li style="margin: 5px 0;">
                                    <a href="<?= htmlspecialchars($a['file_path']) ?>" target="_blank" 
                                       style="color: #003366; text-decoration: none; font-weight: bold;">
                                        📄 <?= htmlspecialchars($a['file_name']) ?>
                                    </a>
                                    <?php if ($a['description']): ?>
                                        <span style="color: #666;"> — <?= htmlspecialchars($a['description']) ?></span>
                                    <?php endif; ?>
                                    <span style="color: #999; font-size: 0.85em;">
                                        (Uploaded <?= date('M d, Y', strtotime($a['uploaded_at'])) ?>)
                                    </span>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: #999; font-style: italic; margin: 10px 0;">No attachments</p>
                    <?php endif; ?>

                    <!-- Status History -->
                    <div class="section-title">📊 Status History</div>
                    <?php if ($history->num_rows > 0): ?>
                        <ol style="margin: 10px 0; padding-left: 25px;">
                            <?php while ($h = $history->fetch_assoc()): ?>
                                <li style="margin: 8px 0;">
                                    <strong style="color: #003366;">
                                        <?= date('M d, Y H:i', strtotime($h['changed_at'])) ?>
                                    </strong>: 
                                    Changed to <strong><?= htmlspecialchars($h['status']) ?></strong> 
                                    by <?= htmlspecialchars($h['username'] ?? 'System') ?>
                                    <?php if ($h['remarks']): ?>
                                        <br><em style="color: #666; margin-left: 20px;">
                                            "<?= htmlspecialchars($h['remarks']) ?>"
                                        </em>
                                    <?php endif; ?>
                                </li>
                            <?php endwhile; ?>
                        </ol>
                    <?php else: ?>
                        <p style="color: #999; font-style: italic; margin: 10px 0;">No history recorded</p>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="edit_cases.php?id=<?= urlencode($row['case_no']) ?>">✏️ Edit Case</a>
                        <a href="print_blotter.php?id=<?= $row['id'] ?>" target="_blank" class="print">🖨️ Print Blotter</a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

        <!-- Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= $type ?>&barangay=<?= urlencode($barangay) ?>&status=<?= $status ?>" 
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
            
            // Toggle current row
            row.style.display = isVisible ? 'none' : 'table-row';
        }
    </script>
</body>
</html>