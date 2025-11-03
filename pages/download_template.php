<?php
session_start();
require_once 'config/connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized');
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
$headers = [
    'case_no', 'incident_type', 'barangay', 'incident_date', 
    'modus_operandi', 'status', 'accused', 'complainant', 
    'date_filed', 'prosecutor'
];

$sheet->fromArray($headers, NULL, 'A1');

// Style header row
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003366']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];

$sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

// Add sample data
$sample = [
    'CYBER-2025-XXXX',
    'Phishing',
    'Brgy. VI-A',
    '2025-11-03',
    'Fake GCash link sent via SMS',
    'Open',
    'Juan Dela Cruz',
    'Maria Santos',
    '2025-11-03',
    'Atty. Pedro Reyes'
];

$sheet->fromArray($sample, NULL, 'A2');

// Add data validation for incident_type
$validation = $sheet->getCell('B2')->getDataValidation();
$validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
$validation->setAllowBlank(false);
$validation->setShowInputMessage(true);
$validation->setShowErrorMessage(true);
$validation->setShowDropDown(true);
$validation->setErrorTitle('Invalid Type');
$validation->setError('Please select from dropdown');
$validation->setPromptTitle('Select Type');
$validation->setPrompt('Choose incident type');
$validation->setFormula1('"Phishing,Online Fraud,Identity Theft,Cyber Harassment,Others"');

// Add data validation for status
$validation2 = $sheet->getCell('F2')->getDataValidation();
$validation2->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$validation2->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
$validation2->setAllowBlank(false);
$validation2->setShowInputMessage(true);
$validation2->setShowErrorMessage(true);
$validation2->setShowDropDown(true);
$validation2->setFormula1('"Open,Under Investigation,Closed"');

// Auto-size columns
foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add instructions sheet
$instructionsSheet = $spreadsheet->createSheet();
$instructionsSheet->setTitle('Instructions');

$instructions = [
    ['CyberPablo Excel Import Template'],
    [''],
    ['INSTRUCTIONS:'],
    ['1. Fill in the data starting from row 2 (row 1 contains headers)'],
    ['2. Do not modify or delete the header row'],
    ['3. Use the dropdown menus for incident_type and status columns'],
    ['4. Date format must be: YYYY-MM-DD (e.g., 2025-11-03)'],
    ['5. Barangay names must match the official names in the system'],
    [''],
    ['REQUIRED FIELDS:'],
    ['- case_no: Must be unique (e.g., CYBER-2025-0001)'],
    ['- incident_type: Select from dropdown'],
    ['- barangay: Use exact barangay name (with "Brgy." prefix)'],
    ['- incident_date: When the crime occurred'],
    ['- status: Select from dropdown'],
    [''],
    ['OPTIONAL FIELDS:'],
    ['- modus_operandi: Description of how the crime was committed'],
    ['- accused: Name of the accused person'],
    ['- complainant: Name of the victim/complainant'],
    ['- date_filed: When the case was officially filed'],
    ['- prosecutor: Assigned prosecutor name'],
    [''],
    ['VALID BARANGAY NAMES (Sample):'],
    ['Brgy. I-A, Brgy. I-B, Brgy. II-A, Brgy. VI-A, Brgy. Santo Angel'],
    ['For full list, check the Cases page dropdown'],
];

$instructionsSheet->fromArray($instructions, NULL, 'A1');
$instructionsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$instructionsSheet->getStyle('A3')->getFont()->setBold(true);
$instructionsSheet->getStyle('A10')->getFont()->setBold(true);
$instructionsSheet->getStyle('A18')->getFont()->setBold(true);
$instructionsSheet->getStyle('A25')->getFont()->setBold(true);
$instructionsSheet->getColumnDimension('A')->setWidth(80);

// Set active sheet back to data sheet
$spreadsheet->setActiveSheetIndex(0);

// Output file
$filename = 'CyberPablo_Import_Template_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// Log audit
$audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'download_template', ?)");
$audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
$audit->execute();

exit;
?>