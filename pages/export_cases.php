<?php
session_start();
require_once 'config/connection.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function safeFormatDate($dateString, $format = 'Y-m-d') {
    if (empty($dateString) || str_contains($dateString, '0000-00-00')) {
        return ''; // Return blank for NULL or zero dates
    }
    $timestamp = strtotime($dateString);
    if ($timestamp === false || $timestamp < 0) {
        return ''; // Return blank for other invalid dates
    }
    return date($format, $timestamp);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get filters from URL
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

// Build query with prosecutor JOIN
$sql = "SELECT i.*, 
               b.lat, b.lng, b.official_name, b.alt_name,
               p.full_name as prosecutor_name
        FROM incidents i 
        LEFT JOIN barangays b ON i.barangay_id = b.id
        LEFT JOIN prosecutors p ON i.prosecutor_id = p.id
        WHERE 1=1";
$params = []; 
$types = "";

// Apply filters
if ($search) {
    $sql .= " AND (i.case_no LIKE ? OR i.accused LIKE ? OR i.complainant LIKE ? OR b.official_name LIKE ? OR b.alt_name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= "sssss";
}
if ($type) { 
    $sql .= " AND i.incident_type = ?"; 
    $params[] = $type; 
    $types .= "s"; 
}
if ($barangay){
    $sql .= " AND b.id = ?";
    $params[] = $barangay;
    $types .= "i";
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

// NEW: Month/Year filter
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

$sql .= " ORDER BY i.incident_date DESC";

// Execute query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("CyberPablo System")
    ->setTitle("Cybercrime Cases Export - " . date('Y-m-d'))
    ->setSubject("Incident Report")
    ->setDescription("Exported cybercrime incident cases from San Pablo City");

// Set column headers - ALL fields from your database
$headers = [
    'A1' => 'Case No',
    'B1' => 'Incident Type',
    'C1' => 'Barangay',
    'D1' => 'Incident Date',
    'E1' => 'Date Filed',
    'F1' => 'Status',
    'G1' => 'Accused',
    'H1' => 'Accused Address',
    'I1' => 'Accused Contact',
    'J1' => 'Complainant',
    'K1' => 'Complainant Address',
    'L1' => 'Complainant Contact',
    'M1' => 'Modus Operandi',
    'N1' => 'Prosecutor',
    'O1' => 'Branch',
    'P1' => 'NPS Docket',
    'Q1' => 'Offense/Crime',
    'R1' => 'Date Committed',
    'S1' => 'Bail Recommended',
    'T1' => 'Received By',
    'U1' => 'Received Date',
    'V1' => 'Returned To',
    'W1' => 'Returned Date',
    'X1' => 'Evidence Notes',
    'Y1' => 'Latitude',
    'Z1' => 'Longitude',
    'AA1' => 'Attachments'
];

foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// Style the header row
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '003366']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A1:AA1')->applyFromArray($headerStyle);

// Set column widths
$columnWidths = [
    'A' => 18,  'B' => 20,  'C' => 25,  'D' => 15,  'E' => 15,
    'F' => 20,  'G' => 30,  'H' => 35,  'I' => 15,  'J' => 30,
    'K' => 35,  'L' => 15,  'M' => 45,  'N' => 25,  'O' => 20,
    'P' => 20,  'Q' => 30,  'R' => 18,  'S' => 15,  'T' => 25,
    'U' => 18,  'V' => 25,  'W' => 18,  'X' => 40,  'Y' => 12, 'Z' => 12,
    'AA' => 45
];

foreach ($columnWidths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}


// Fill data
$row = 2;
$openCases = 0;
$underInvestigation = 0;
$closedCases = 0;
$att_stmt = $conn->prepare("SELECT file_name FROM attachments WHERE incident_id = ?");
while ($data = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $data['case_no']);
    $sheet->setCellValue('B' . $row, $data['incident_type']);
    $sheet->setCellValue('C' . $row, $data['official_name'] ?? $data['barangay']);
    $sheet->setCellValue('D' . $row, safeFormatDate($data['incident_date'], 'Y-m-d'));
    $sheet->setCellValue('E' . $row, safeFormatDate($data['date_filed'], 'Y-m-d'));
    $sheet->setCellValue('F' . $row, $data['status']);
    $sheet->setCellValue('G' . $row, $data['accused'] ?? '');
    $sheet->setCellValue('H' . $row, $data['accused_address'] ?? '');
    $sheet->setCellValue('I' . $row, $data['accused_contact'] ?? '');
    $sheet->setCellValue('J' . $row, $data['complainant'] ?? '');
    $sheet->setCellValue('K' . $row, $data['complainant_address'] ?? '');
    $sheet->setCellValue('L' . $row, $data['complainant_contact'] ?? '');
    $sheet->setCellValue('M' . $row, $data['modus_operandi'] ?? '');
    $sheet->setCellValue('N' . $row, $data['prosecutor_name'] ?? ''); // ✅ FIXED: Now uses JOIN
    $sheet->setCellValue('O' . $row, $data['branch'] ?? '');
    $sheet->setCellValue('P' . $row, $data['nps_docket'] ?? '');
    $sheet->setCellValue('Q' . $row, $data['offense_crime'] ?? '');
    $sheet->setCellValue('R' . $row, safeFormatDate($data['date_committed'], 'Y-m-d H:i'));
    $sheet->setCellValue('S' . $row, $data['bail_recommended'] ? number_format($data['bail_recommended'], 2) : '');
    $sheet->setCellValue('T' . $row, $data['received_by'] ?? '');
    $sheet->setCellValue('U' . $row, safeFormatDate($data['received_date'], 'Y-m-d H:i'));
    $sheet->setCellValue('V' . $row, $data['returned_to'] ?? '');
    $sheet->setCellValue('W' . $row, safeFormatDate($data['returned_date'], 'Y-m-d H:i'));
    $sheet->setCellValue('X' . $row, $data['evidence_notes'] ?? '');
    $sheet->setCellValue('Y' . $row, $data['lat'] ?? '');
    $sheet->setCellValue('Z' . $row, $data['lng'] ?? '');

   
    $att_stmt->bind_param("i", $data['id']);
    $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    
    $filenames = [];
    while ($att_row = $att_result->fetch_assoc()) {
        $filenames[] = $att_row['file_name'];
    }
    // Set cell value, comma-separated
    $sheet->setCellValue('AA' . $row, implode(', ', $filenames));
   
    
    // Apply alternating row colors
    if ($row % 2 == 0) {
        $sheet->getStyle('A' . $row . ':Z' . $row)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8F9FA']
            ]
        ]);
    }
    
    // Apply borders
    $sheet->getStyle('A' . $row . ':Z' . $row)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'DDDDDD']
            ]
        ]
    ]);

    
    // Color code status
    switch ($data['status']) {
        case 'Open':
            $sheet->getStyle('F' . $row)->applyFromArray([
                'font' => ['color' => ['rgb' => 'D32F2F'], 'bold' => true]
            ]);
            break;
        case 'Under Investigation':
            $sheet->getStyle('F' . $row)->applyFromArray([
                'font' => ['color' => ['rgb' => 'F9A825'], 'bold' => true]
            ]);
            break;
        case 'Closed':
            $sheet->getStyle('F' . $row)->applyFromArray([
                'font' => ['color' => ['rgb' => '388E3C'], 'bold' => true]
            ]);
            break;
    }

    switch ($data['status']) {
        case 'Open': $openCases++; break;
        case 'Under Investigation': $underInvestigation++; break;
        case 'Closed': $closedCases++; break;
    }
    
    // Wrap text for long fields
    $sheet->getStyle('H' . $row)->getAlignment()->setWrapText(true); // Accused Address
    $sheet->getStyle('K' . $row)->getAlignment()->setWrapText(true); // Complainant Address
    $sheet->getStyle('M' . $row)->getAlignment()->setWrapText(true); // Modus Operandi
    $sheet->getStyle('Q' . $row)->getAlignment()->setWrapText(true); // Offense/Crime
    $sheet->getStyle('X' . $row)->getAlignment()->setWrapText(true); // Evidence Notes
    $sheet->getStyle('AA' . $row)->getAlignment()->setWrapText(true); // Attachments
    $row++;
}

// Auto-fit row heights
for ($i = 2; $i < $row; $i++) {
    $sheet->getRowDimension($i)->setRowHeight(-1);
}

// Freeze header row
$sheet->freezePane('A2');

// Add summary statistics
$summaryRow = $row + 2;
$sheet->setCellValue('A' . $summaryRow, 'SUMMARY STATISTICS');
$sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
$sheet->getStyle('A' . $summaryRow)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '003366']
    ],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// Count statistics
$totalCases = $row - 2;
// $openCases = 0;
// $underInvestigation = 0;
// $closedCases = 0;

// // Re-query for statistics
// $stmt->execute();
// $result = $stmt->get_result();
// while ($data = $result->fetch_assoc()) {
//     switch ($data['status']) {
//         case 'Open': $openCases++; break;
//         case 'Under Investigation': $underInvestigation++; break;
//         case 'Closed': $closedCases++; break;
//     }
// }

$summaryData = [
    ['Total Cases:', $totalCases],
    ['Open Cases:', $openCases],
    ['Under Investigation:', $underInvestigation],
    ['Closed Cases:', $closedCases]
];

$summaryStartRow = $summaryRow + 1;
foreach ($summaryData as $idx => $data) {
    $currentRow = $summaryStartRow + $idx;
    $sheet->setCellValue('A' . $currentRow, $data[0]);
    $sheet->setCellValue('B' . $currentRow, $data[1]);
}

// Style summary
$sheet->getStyle('A' . $summaryStartRow . ':B' . ($summaryStartRow + 3))->applyFromArray([
    'font' => ['bold' => true],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN
        ]
    ]
]);

// Log audit trail
$audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'export_cases', ?)");
$audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
$audit->execute();

// Generate filename with timestamp and filters
$filterSuffix = '';
if ($search) $filterSuffix .= '_Search';
if ($type) $filterSuffix .= '_' . str_replace(' ', '', $type);
if ($status) $filterSuffix .= '_' . str_replace(' ', '', $status);
if ($date_from || $date_to) $filterSuffix .= '_Date';
if ($month) $filterSuffix .= '_Month';
if ($year) $filterSuffix .= '_Year';

$filename = 'CyberPablo_Cases' . $filterSuffix . '_' . date('Y-m-d_His') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Pragma: public');

// Write file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// Clean up
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
?>