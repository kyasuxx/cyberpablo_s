<?php
session_start();
require '../vendor/autoload.php';
require_once 'config/connection.php';

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

// --- INTELLIGENCE ALGORITHMS ---

// 1. REPEAT OFFENDERS (Red Card Logic)
$suspects = [];
$suspect_links = [];
$result = $conn->query("SELECT case_no, accused FROM incidents WHERE accused IS NOT NULL AND accused != '' AND accused != 'Unknown'");
while ($row = $result->fetch_assoc()) $suspects[] = $row;

$count = count($suspects);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $name1 = strtolower(trim($suspects[$i]['accused']));
        $name2 = strtolower(trim($suspects[$j]['accused']));
        
        $is_exact = ($name1 === $name2);
        $sound_match = metaphone($name1) == metaphone($name2);
        $dist = levenshtein($name1, $name2);
        $len = max(strlen($name1), strlen($name2));
        $ratio = ($len > 0) ? (1 - ($dist / $len)) * 100 : 0;

        if ($is_exact || $sound_match || $ratio > 80) {
            $method = $is_exact ? "Exact Match" : ($sound_match ? "Phonetic Match" : "Spelling Variation");
            $suspect_links[] = [
                $suspects[$i]['accused'], 
                $suspects[$i]['case_no'], 
                $suspects[$j]['accused'], 
                $suspects[$j]['case_no'], 
                $method
            ];
        }
    }
}

// 2. MODUS CLUSTERS (Orange Card Logic)
$modus_clusters = [];
$cases = [];
$result = $conn->query("SELECT case_no, modus_operandi FROM incidents WHERE modus_operandi IS NOT NULL");
$stop_words = [
    'the', 'and', 'is', 'in', 'at', 'of', 'to', 'a', 'was', 'via', 'sent', 
    'using', 'link', 'specific', 'crime', 'for', 'on', 'with', 'by', 'that', 
    'it', 'as', 'an', 'or', 'be', 'from', 'suspect', 'victim', 'accused', 
    'complainant', 'reported', 'incident', 'person', 'unknown', 'stated', 
    'allegedly', 'investigation', 'police', 'barangay'
];

while ($row = $result->fetch_assoc()) {
    $clean = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $words = explode(' ', $clean);
    $tokens = array_filter($words, function($w) use ($stop_words) {
        return !empty($w) && !in_array($w, $stop_words) && strlen($w) > 2;
    });
    if (!empty($tokens)) {
        $cases[] = ['case_no' => $row['case_no'], 'tokens' => array_unique($tokens)];
    }
}

for ($i = 0; $i < count($cases); $i++) {
    for ($j = $i + 1; $j < count($cases); $j++) {
        $intersection = array_intersect($cases[$i]['tokens'], $cases[$j]['tokens']);
        if (count($intersection) >= 3) {
            $modus_clusters[] = [
                $cases[$i]['case_no'], 
                $cases[$j]['case_no'], 
                implode(', ', $intersection)
            ];
        }
    }
}

// 3. SERIAL VICTIMS (Blue Card Logic)
$serial_victims = [];
$v_sql = "SELECT complainant, COUNT(*) as count 
          FROM incidents 
          WHERE complainant IS NOT NULL 
          AND complainant != '' 
          AND complainant != 'Unknown'
          GROUP BY complainant 
          HAVING count > 1 
          ORDER BY count DESC";
$v_result = $conn->query($v_sql);

while ($row = $v_result->fetch_assoc()) {
    $serial_victims[] = [$row['complainant'], $row['count']];
}

// --- GENERATING THE EXCEL FILE ---

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("CyberPablo System")
    ->setTitle("Intelligence Report")
    ->setSubject("Automated Link Analysis")
    ->setDescription("Generated report of detected criminal patterns.");

// Global Style for Headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003366']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

// SHEET 1: Repeat Offenders
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Suspect Links');
$sheet->setCellValue('A1', 'Suspect A');
$sheet->setCellValue('B1', 'Case A');
$sheet->setCellValue('C1', 'Suspect B');
$sheet->setCellValue('D1', 'Case B');
$sheet->setCellValue('E1', 'Detection Method');

$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

$row = 2;
foreach ($suspect_links as $link) {
    $sheet->fromArray($link, NULL, 'A' . $row++);
}
foreach(range('A','E') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

// SHEET 2: Modus Patterns
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Modus Patterns');
$sheet->setCellValue('A1', 'Case A');
$sheet->setCellValue('B1', 'Case B');
$sheet->setCellValue('C1', 'Shared Keywords');

$sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

$row = 2;
foreach ($modus_clusters as $cluster) {
    $sheet->fromArray($cluster, NULL, 'A' . $row++);
}
foreach(range('A','C') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

// SHEET 3: Serial Victims
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('High Risk Victims');
$sheet->setCellValue('A1', 'Victim Name');
$sheet->setCellValue('B1', 'Incident Count');

$sheet->getStyle('A1:B1')->applyFromArray($headerStyle);

$row = 2;
foreach ($serial_victims as $victim) {
    $sheet->fromArray($victim, NULL, 'A' . $row++);
}
foreach(range('A','B') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

// AUDIT LOG (Optional - mirrors your existing logic)
$audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'export_intelligence', ?)");
if ($audit) {
    $audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
    $audit->execute();
}

// OUTPUT
$filename = "CyberPablo_Intelligence_" . date('Y-m-d_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>