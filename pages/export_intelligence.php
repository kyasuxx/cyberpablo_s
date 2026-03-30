<?php
session_start();
require '../vendor/autoload.php';
require_once 'config/connection.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// --- SYNCHRONIZED ADVANCED ENTITY RESOLUTION ENGINE ---

// Fetch memory of rejected links so they are excluded from the official report
$rejected_pairs = [];
$rej_result = $conn->query("SELECT case_a, case_b FROM rejected_links");
if ($rej_result) {
    while($r = $rej_result->fetch_assoc()){
        $rejected_pairs[] = $r['case_a'] . '-' . $r['case_b'];
        $rejected_pairs[] = $r['case_b'] . '-' . $r['case_a']; 
    }
}

function calculateDistanceKM($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 9999;
    $earthRadius = 6371; 
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

$cases = [];
$name_counts = [];
$invalid_identifiers = ['unidentified', 'unknown', 'n/a', 'none', 'unknown suspect', 'pending', '0', 'null', 'alias'];
$stopwords = ['victim', 'suspect', 'money', 'account', 'scam', 'online', 'bank', 'cash', 'report', 'police', 'person', 'using', 'through', 'pesos', 'from', 'that', 'with', 'were', 'told', 'said', 'asked', 'the', 'and', 'was'];

$result = $conn->query("SELECT case_no, incident_type, incident_date, status, accused, accused_contact, modus_operandi, barangay_id, lat, lng, complainant FROM incidents");

while ($row = $result->fetch_assoc()) {
    $raw_accused = strtolower(trim($row['accused']));
    $check_accused = trim(str_replace(['(', ')', '[', ']', '"', "'", '*'], '', $raw_accused));
    $row['is_unidentified'] = in_array($check_accused, $invalid_identifiers) || empty($check_accused) || strlen($check_accused) < 3;
    $row['clean_accused'] = $check_accused;

    // Build frequency map for the Common Name Flag
    if (!$row['is_unidentified']) {
        $name_counts[$check_accused] = ($name_counts[$check_accused] ?? 0) + 1;
    }
    
    $clean_modus = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $words = explode(' ', $clean_modus);
    $row['tokens'] = array_unique(array_filter($words, function($w) use ($stopwords) {
        return strlen($w) > 3 && !in_array($w, $stopwords);
    }));

    $row['clean_phone'] = preg_replace('/[^0-9]/', '', $row['accused_contact']);
    $row['clean_complainant'] = strtolower(trim($row['complainant']));
    $cases[] = $row;
}

$syndicate_links = [];
$count = count($cases);

for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $c1 = $cases[$i];
        $c2 = $cases[$j];
        
        if ($c1['is_unidentified'] && $c2['is_unidentified']) continue;
        
        // NEW: If an investigator manually rejected this pair, skip it immediately!
        if (in_array($c1['case_no'] . '-' . $c2['case_no'], $rejected_pairs)) continue;
        
        $score = 0;
        $reasons = [];
        $has_core_evidence = false;

        if (!empty($c1['clean_phone']) && strlen($c1['clean_phone']) >= 7 && $c1['clean_phone'] === $c2['clean_phone']) {
            $score += 50;
            $reasons[] = "Shared Phone (" . $c1['accused_contact'] . ")";
            $has_core_evidence = true;
        }

        if (!$c1['is_unidentified'] && !$c2['is_unidentified']) {
            $name1 = $c1['clean_accused'];
            $name2 = $c2['clean_accused'];
            
            if ($name1 === $name2) {
                if ($name_counts[$name1] >= 4) {
                    $score += 25;
                    $reasons[] = "Frequent Name Match";
                } else {
                    $score += 50;
                    $reasons[] = "Exact Name Match";
                }
                $has_core_evidence = true;
            } elseif (metaphone($name1) == metaphone($name2) || levenshtein($name1, $name2) <= 2) {
                $score += 30;
                $reasons[] = "Fuzzy Alias Match";
                $has_core_evidence = true;
            }
        }

        if (!empty($c1['clean_complainant']) && $c1['clean_complainant'] === $c2['clean_complainant'] && !in_array($c1['clean_complainant'], $invalid_identifiers)) {
            $score += 30;
            $reasons[] = "Serial Victim Target";
            $has_core_evidence = true;
        }

        $intersection = array_intersect($c1['tokens'], $c2['tokens']);
        $match_count = count($intersection);
        if ($match_count >= 3) {
            $score += ($match_count >= 5) ? 30 : 15;
            $reasons[] = "MO Pattern (" . implode(', ', $intersection) . ")";
            $has_core_evidence = true;
        }

        if (!$has_core_evidence) continue;

        if ($c1['incident_type'] === $c2['incident_type']) {
            $score += 10;
        }

        $distance = calculateDistanceKM($c1['lat'], $c1['lng'], $c2['lat'], $c2['lng']);
        if ($distance <= 2.0) { 
            $score += 20;
            $reasons[] = "Spatial Proximity";
        }

        if (!empty($c1['incident_date']) && !empty($c2['incident_date'])) {
            $days_apart = abs(strtotime($c1['incident_date']) - strtotime($c2['incident_date'])) / 86400; 
            if ($days_apart <= 7) {
                $score += 10;
                $reasons[] = "Temporal Cluster";
            }
        }

        // EXPORT FILTER: Only export High (75+) and Medium (45-74) confidence.
        if ($score >= 45) {
            $score_val = min(100, $score);
            $tier_label = ($score_val >= 75) ? ' (High)' : ' (Medium)';
            
            $syndicate_links[] = [
                'Case A' => $c1['case_no'],
                'Suspect A' => $c1['accused'],
                'Case B' => $c2['case_no'],
                'Suspect B' => $c2['accused'],
                'Confidence Score' => $score_val . '%' . $tier_label,
                // NEW: Use bullet points and newlines (\n) instead of the pipe symbol
                'Evidence Detected' => "• " . implode("\n• ", $reasons) 
            ];
        }
    }
}

usort($syndicate_links, function($a, $b) { return (int)$b['Confidence Score'] <=> (int)$a['Confidence Score']; });

// --- GENERATING THE EXCEL FILE ---

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("CyberPablo System")
    ->setTitle("Intelligence Report")
    ->setSubject("Automated Link Analysis")
    ->setDescription("Generated report of medium to high-confidence serial offender patterns.");

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003366']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Syndicate Network Links');

// Set Headers
$headers = ['Case A', 'Suspect A', 'Case B', 'Suspect B', 'Confidence Tier', 'Evidence Detected'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}
$sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

// Populate Data
$row = 2;
foreach ($syndicate_links as $link) {
    $sheet->setCellValue('A' . $row, $link['Case A']);
    $sheet->setCellValue('B' . $row, $link['Suspect A']);
    $sheet->setCellValue('C' . $row, $link['Case B']);
    $sheet->setCellValue('D' . $row, $link['Suspect B']);
    $sheet->setCellValue('E' . $row, $link['Confidence Score']);
    $sheet->setCellValue('F' . $row, $link['Evidence Detected']);
    $row++;
}

// Auto-size columns A through E
foreach(range('A','E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// FORMATTING FOR BETTER READABILITY
// 1. Give the Evidence column a fixed width so it doesn't stretch infinitely
$sheet->getColumnDimension('F')->setWidth(65);

$lastRow = $row - 1;
if ($lastRow >= 2) {
    // 2. Align everything to the top of the cell
    $sheet->getStyle("A2:F{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    
    // 3. Force Text Wrapping on the Evidence column so the bullets stack nicely
    $sheet->getStyle("F2:F{$lastRow}")->getAlignment()->setWrapText(true);
}

// AUDIT LOG
$audit = $conn->prepare("INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, 'Exported Verified Intelligence Report (High/Med Only)', ?)");
if ($audit) {
    $audit->bind_param("is", $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']);
    $audit->execute();
}

// OUTPUT
$filename = "CyberPablo_Network_Intelligence_" . date('Y-m-d_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>