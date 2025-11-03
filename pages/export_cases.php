<?php
session_start();
require_once 'config/connection.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get filters from URL (same as cases.php)
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status = $_GET['status'] ?? '';

// Build query (same as cases.php but without LIMIT)
$sql = "SELECT i.*, b.lat, b.lng, b.official_name, b.alt_name
        FROM incidents i 
        LEFT JOIN barangays b ON (
            i.barangay = b.official_name OR 
            i.barangay = b.alt_name OR
            i.barangay = REPLACE(b.official_name, 'Brgy. ', '') OR
            i.barangay = REPLACE(b.alt_name, 'Brgy. ', '')
        )
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
if ($barangay) { 
    $sql .= " AND (b.official_name = ? OR b.alt_name = ?)"; 
    $params[] = $barangay; 
    $params[] = $barangay; 
    $types .= "ss"; 
}
if ($status) { 
    $sql .= " AND i.status = ?"; 
    $params[] = $status; 
    $types .= "s"; 
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

// Set column headers - matching your database exactly
$headers = [
    'A1' => 'Case No',
    'B1' => 'Incident Type',
    'C1' => 'Barangay',
    'D1' => 'Incident Date',
    'E1' => 'Date Filed',
    'F1' => 'Status',
    'G1' => 'Accused',
    'H1' => 'Complainant',
    'I1' => 'Modus Operandi',
    'J1' => 'Prosecutor',
    'K1' => 'Branch',
    'L1' => 'NPS Docket',
    'M1' => 'Offense/Crime',
    'N1' => 'Date Committed',
    'O1' => 'Bail Recommended',
    'P1' => 'Latitude',
    'Q1' => 'Longitude'
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

$sheet->getStyle('A1:Q1')->applyFromArray($headerStyle);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(18);  // Case No
$sheet->getColumnDimension('B')->setWidth(20);  // Incident Type
$sheet->getColumnDimension('C')->setWidth(25);  // Barangay
$sheet->getColumnDimension('D')->setWidth(15);  // Incident Date
$sheet->getColumnDimension('E')->setWidth(15);  // Date Filed
$sheet->getColumnDimension('F')->setWidth(20);  // Status
$sheet->getColumnDimension('G')->setWidth(30);  // Accused
$sheet->getColumnDimension('H')->setWidth(30);  // Complainant
$sheet->getColumnDimension('I')->setWidth(45);  // Modus Operandi
$sheet->getColumnDimension('J')->setWidth(25);  // Prosecutor
$sheet->getColumnDimension('K')->setWidth(20);  // Branch
$sheet->getColumnDimension('L')->setWidth(20);  // NPS Docket
$sheet->getColumnDimension('M')->setWidth(30);  // Offense/Crime
$sheet->getColumnDimension('N')->setWidth(18);  // Date Committed
$sheet->getColumnDimension('O')->setWidth(15);  // Bail Recommended
$sheet->getColumnDimension('P')->setWidth(12);  // Latitude
$sheet->getColumnDimension('Q')->setWidth(12);  // Longitude

// Fill data
$row = 2;
while ($data = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $data['case_no']);
    $sheet->setCellValue('B' . $row, $data['incident_type']);
    $sheet->setCellValue('C' . $row, $data['official_name'] ?? $data['barangay']);
    $sheet->setCellValue('D' . $row, $data['incident_date'] ? date('Y-m-d', strtotime($data['incident_date'])) : '');
    $sheet->setCellValue('E' . $row, $data['date_filed'] ? date('Y-m-d', strtotime($data['date_filed'])) : '');
    $sheet->setCellValue('F' . $row, $data['status']);
    $sheet->setCellValue('G' . $row, $data['accused'] ?? '');
    $sheet->setCellValue('H' . $row, $data['complainant'] ?? '');
    $sheet->setCellValue('I' . $row, $data['modus_operandi'] ?? '');
    $sheet->setCellValue('J' . $row, $data['prosecutor'] ?? '');
    $sheet->setCellValue('K' . $row, $data['branch'] ?? '');
    $sheet->setCellValue('L' . $row, $data['nps_docket'] ?? '');
    $sheet->setCellValue('M' . $row, $data['offense_crime'] ?? '');
    $sheet->setCellValue('N' . $row, $data['date_committed'] ? date('Y-m-d H:i', strtotime($data['date_committed'])) : '');
    $sheet->setCellValue('O' . $row, $data['bail_recommended'] ? number_format($data['bail_recommended'], 2) : '');
    $sheet->setCellValue('P' . $row, $data['lat'] ?? '');
    $sheet->setCellValue('Q' . $row, $data['lng'] ?? '');
    
    // Apply alternating row colors
    if ($row % 2 == 0) {
        $sheet->getStyle('A' . $row . ':Q' . $row)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8F9FA']
            ]
        ]);
    }
    
    // Apply borders to data rows
    $sheet->getStyle('A' . $row . ':Q' . $row)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'DDDDDD']
            ]
        ]
    ]);
    
    // Color code status
    $statusCell = 'F' . $row;
    switch ($data['status']) {
        case 'Open':
            $sheet->getStyle($statusCell)->applyFromArray([
                'font' => ['color' => ['rgb' => 'D32F2F'], 'bold' => true]
            ]);
            break;
        case 'Under Investigation':
            $sheet->getStyle($statusCell)->applyFromArray([
                'font' => ['color' => ['rgb' => 'F9A825'], 'bold' => true]
            ]);
            break;
        case 'Closed':
            $sheet->getStyle($statusCell)->applyFromArray([
                'font' => ['color' => ['rgb' => '388E3C'], 'bold' => true]
            ]);
            break;
    }
    
    // Wrap text for Modus Operandi and Offense/Crime
    $sheet->getStyle('I' . $row)->getAlignment()->setWrapText(true);
    $sheet->getStyle('M' . $row)->getAlignment()->setWrapText(true);
    
    $row++;
}

// Auto-fit row heights
for ($i = 2; $i < $row; $i++) {
    $sheet->getRowDimension($i)->setRowHeight(-1);
}

// Freeze header row
$sheet->freezePane('A2');

// Add summary at the bottom
$summaryRow = $row + 2;
$sheet->setCellValue('A' . $summaryRow, 'SUMMARY STATISTICS');
$sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
$sheet->getStyle('A' . $summaryRow)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E0E0']
    ]
]);

// Count statistics
$totalCases = $row - 2;
$openCases = 0;
$underInvestigation = 0;
$closedCases = 0;

// Re-query for statistics
$stmt->execute();
$result = $stmt->get_result();
while ($data = $result->fetch_assoc()) {
    switch ($data['status']) {
        case 'Open': $openCases++; break;
        case 'Under Investigation': $underInvestigation++; break;
        case 'Closed': $closedCases++; break;
    }
}

$sheet->setCellValue('A' . ($summaryRow + 1), 'Total Cases:');
$sheet->setCellValue('B' . ($summaryRow + 1), $totalCases);
$sheet->setCellValue('A' . ($summaryRow + 2), 'Open Cases:');
$sheet->setCellValue('B' . ($summaryRow + 2), $openCases);
$sheet->setCellValue('A' . ($summaryRow + 3), 'Under Investigation:');
$sheet->setCellValue('B' . ($summaryRow + 3), $underInvestigation);
$sheet->setCellValue('A' . ($summaryRow + 4), 'Closed Cases:');
$sheet->setCellValue('B' . ($summaryRow + 4), $closedCases);

// Style summary
$sheet->getStyle('A' . ($summaryRow + 1) . ':B' . ($summaryRow + 4))->applyFromArray([
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

// Generate filename with timestamp
$filename = 'CyberPablo_Cases_' . date('Y-m-d_His') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// Clean up
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

exit;
?>