<?php
// get_case_details.php
session_start();
require_once 'config/connection.php';

// 1. Check Authentication
if (!isset($_SESSION['user_id'])) { 
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Not authenticated']);
    exit; 
}

// 2. Get and Validate the ID
$incident_id = (int)($_GET['id'] ?? 0);
if (!$incident_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$output = [
    'history' => '',
    'attachments' => ''
];

// 3. Fetch Status History
$hist_stmt = $conn->prepare("
    SELECT h.*, u.username 
    FROM case_status_history h 
    LEFT JOIN users u ON h.changed_by = u.id 
    WHERE h.incident_id = ? 
    ORDER BY h.changed_at DESC
");
$hist_stmt->bind_param("i", $incident_id);
$hist_stmt->execute();
$history = $hist_stmt->get_result();

$history_html = '';
if ($history->num_rows > 0) {
    $history_html .= '<ol style="margin: 10px 0; padding-left: 25px;">';
    while ($h = $history->fetch_assoc()) {
        $history_html .= '<li style="margin: 8px 0;">';
        $history_html .= '<strong style="color: #003366;">' . date('M d, Y H:i', strtotime($h['changed_at'])) . '</strong>: ';
        $history_html .= 'Changed to <strong>' . htmlspecialchars($h['status']) . '</strong> ';
        $history_html .= 'by ' . htmlspecialchars($h['username'] ?? 'System');
        if ($h['remarks']) {
            $history_html .= '<br><em style="color: #666; margin-left: 20px;">"' . htmlspecialchars($h['remarks']) . '"</em>';
        }
        $history_html .= '</li>';
    }
    $history_html .= '</ol>';
} else {
    $history_html = '<p style="color: #999; font-style: italic; margin: 10px 0;">No history recorded</p>';
}
$output['history'] = $history_html;


// 4. Fetch Attachments
$att_stmt = $conn->prepare("SELECT * FROM attachments WHERE incident_id = ?");
$att_stmt->bind_param("i", $incident_id);
$att_stmt->execute();
$attachments = $att_stmt->get_result();

$attachments_html = '';
if ($attachments->num_rows > 0) {
    $attachments_html .= '<ul style="margin: 10px 0; padding-left: 25px;">';
    while ($a = $attachments->fetch_assoc()) {
        $attachments_html .= '<li style="margin: 5px 0;">';
        $attachments_html .= '<a href="' . htmlspecialchars($a['file_path']) . '" target="_blank" style="color: #003366; text-decoration: none; font-weight: bold;">';
        $attachments_html .= '📄 ' . htmlspecialchars($a['file_name']);
        $attachments_html .= '</a>';
        if ($a['description']) {
            $attachments_html .= '<span style="color: #666;"> — ' . htmlspecialchars($a['description']) . '</span>';
        }
        $attachments_html .= ' <span style="color: #999; font-size: 0.85em;">(Uploaded ' . date('M d, Y', strtotime($a['uploaded_at'])) . ')</span>';
        $attachments_html .= '</li>';
    }
    $attachments_html .= '</ul>';
} else {
    $attachments_html = '<p style="color: #999; font-style: italic; margin: 10px 0;">No attachments</p>';
}
$output['attachments'] = $attachments_html;

// 5. Send the data back as JSON
header('Content-Type: application/json');
echo json_encode($output);
?>