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

// Set headers - Matched EXACTLY to export_cases.php
$headers = [
    'Case No', 'Incident Type', 'Barangay', 'Incident Date', 'Date Filed',
    'Status', 'Accused', 'Accused Address', 'Accused Contact',
    'Complainant', 'Complainant Address', 'Complainant Contact',
    'Modus Operandi', 'Prosecutor', 'Branch', 'NPS Docket', 'Offense/Crime',
    'Date Committed', 'Bail Recommended', 'Received By', 'Received Date',
    'Returned To', 'Returned Date', 'Evidence Notes', 'Latitude', 'Longitude',
    'Attachments'
];

$sheet->fromArray($headers, NULL, 'A1');

// Style header row
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003366']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];

$lastColumn = $sheet->getHighestColumn();
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

// Add sample data with ALL columns
$sample = [
    'CYBER-2025-XXXX',                         // Case No
    'Phishing',                                // Incident Type
    'Brgy. VI-A',                              // Barangay
    '2025-11-03',                              // Incident Date
    '2025-11-03',                              // Date Filed
    'Open',                                    // Status
    'Juan Dela Cruz',                          // Accused
    '123 Street, Barangay',                    // Accused Address
    '09171234567',                             // Accused Contact
    'Maria Santos',                            // Complainant
    '456 Avenue, City',                        // Complainant Address
    '09189876543',                             // Complainant Contact
    'Fake GCash link sent via SMS',            // Modus Operandi
    'Atty. Pedro Reyes',                       // Prosecutor
    '',                                        // Branch (Not in import, but in export)
    'NPS-2025-001',                            // NPS Docket
    'Estafa thru Electronic Means',            // Offense/Crime
    '2025-11-01 14:30',                        // Date Committed
    '12000.00',                                // Bail Recommended
    'Police Officer Juan',                     // Received By
    '2025-11-03 09:00',                        // Received Date
    'Fiscal Office',                           // Returned To
    '2025-11-05 15:30',                        // Returned Date
    'Evidence includes screenshots and SMS',   // Evidence Notes
    '',                                        // Latitude (Handled by import)
    '',                                        // Longitude (Handled by import)
    'report.pdf, evidence_01.jpg'              // Attachments
];

$sheet->fromArray($sample, NULL, 'A2');

// Add data validation for incident_type (Column B)
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

// Add data validation for status (Column F)
$validation2 = $sheet->getCell('F2')->getDataValidation();
$validation2->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$validation2->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
$validation2->setAllowBlank(false);
$validation2->setShowInputMessage(true);
$validation2->setShowErrorMessage(true);
$validation2->setShowDropDown(true);
$validation2->setFormula1('"Open,Under Investigation,Closed"');

// Auto-size all columns (A through AA)
foreach (range('A', 'Z') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->getColumnDimension('AA')->setAutoSize(true);


// Add instructions sheet
$instructionsSheet = $spreadsheet->createSheet();
$instructionsSheet->setTitle('Instructions');

$instructions = [
    ['CyberPablo Excel Import Template - Complete Guide'],
    [''],
    ['INSTRUCTIONS:'],
    ['1. Fill in the data starting from row 2 (row 1 contains headers)'],
    ['2. Do not modify or delete the header row'],
    ['3. Use the dropdown menus for Incident Type (Column B) and Status (Column F)'],
    ['4. Date formats:'],
    ['    - Incident Date, Date Filed: YYYY-MM-DD (e.g., 2025-11-03)'],
    ['    - Date Committed, Received Date, Returned Date: YYYY-MM-DD HH:MM (e.g., 2025-11-03 14:30)'],
    ['5. Barangay names must match the official names in the system (e.g., "Brgy. VI-A")'],
    ['6. Latitude and Longitude columns are for reference; they will be auto-filled by the system based on the Barangay.'],
    [''],
    ['REQUIRED FIELDS (Must be filled):'],
    ['- Case No: Unique identifier (e.g., CYBER-2025-0001)'],
    ['- Incident Type: Select from dropdown'],
    ['- Barangay: Use exact barangay name (with "Brgy." prefix)'],
    ['- Incident Date: When the crime occurred (YYYY-MM-DD)'],
    ['- Modus Operandi: Description of how the crime was committed'],
    ['- Status: Select from dropdown'],
    ['- Complainant: Name of the victim/complainant'],
    [''],
    ['OPTIONAL FIELDS (Can be left blank):'],
    ['- Date Filed, Accused, Accused Address, Accused Contact, Complainant Address, Complainant Contact'],
    ['- NPS Docket, Offense/Crime, Date Committed, Bail Recommended, Prosecutor, Received By, Received Date'],
    ['- Returned To, Returned Date, Evidence Notes, Attachments'],
    [''],
    ['ATTACHMENTS (Column AA):'],
    ['1. Manually upload your files (PDFs, JPGs) to the "../uploads/import_staging/" folder on the server.'],
    ['2. In the "Attachments" column, list the exact filenames, separated by a comma (e.g., "file1.pdf, photo.jpg")'],
    [''],
    ['TIPS:'],
    ['- Duplicate Case Numbers will UPDATE existing records (not create new ones)'],
    ['- Prosecutor names will be automatically added to the system roster if they don\'t exist.'],
];

$instructionsSheet->fromArray($instructions, NULL, 'A1');
$instructionsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$instructionsSheet->getStyle('A3')->getFont()->setBold(true);
$instructionsSheet->getStyle('A13')->getFont()->setBold(true);
$instructionsSheet->getStyle('A21')->getFont()->setBold(true);
$instructionsSheet->getStyle('A26')->getFont()->setBold(true);
$instructionsSheet->getStyle('A31')->getFont()->setBold(true);
$instructionsSheet->getColumnDimension('A')->setWidth(100);

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