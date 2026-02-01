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

// ==============================================================================
// 3. FETCH CASE TIMELINE (REPLACED: Now uses case_logs table with enhanced UI)
// ==============================================================================

// A. Visual Configuration (Colors for specific log types)
$type_colors = [
    'General' => '#607d8b',      // Grey
    'Interview' => '#2196f3',    // Blue
    'Evidence' => '#ff9800',     // Orange
    'Legal' => '#f44336',        // Red
    'Surveillance' => '#9c27b0'  // Purple
];

// B. Start the HTML Container
$history_html = '<div style="background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #eee;">';

// C. The "Add Entry" Form (Only visible if user has rights, assuming all logged-in users can add notes for now)
$history_html .= '
<h4 style="margin-top:0; color:#003366; border-bottom:2px solid #f0f0f0; padding-bottom:10px;">📋 Case Progress Log</h4>
<form onsubmit="submitNote(event, '.$incident_id.')" style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
        <select id="type-'.$incident_id.'" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold; color: #333;">
            <option value="General">General Update</option>
            <option value="Interview">🗣️ Subject Interview</option>
            <option value="Evidence">📂 Evidence Collection</option>
            <option value="Surveillance">👁️ Surveillance/Tracking</option>
            <option value="Legal">⚖️ Legal/Court Order</option>
        </select>
        <input type="text" id="note-'.$incident_id.'" placeholder="Enter detailed progress report..." required 
               style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
    </div>
    <div style="text-align: right;">
        <button type="submit" style="background: #003366; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">
            + Add Official Entry
        </button>
    </div>
</form>';

// D. Fetch the Logs from Database
$h_sql = "SELECT cl.*, u.username, u.role 
          FROM case_logs cl 
          JOIN users u ON cl.user_id = u.id 
          WHERE cl.incident_id = ? 
          ORDER BY cl.created_at DESC";

$stmt = $conn->prepare($h_sql);
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$history_result = $stmt->get_result();

// E. Render the Timeline
if ($history_result->num_rows > 0) {
    $history_html .= '<ul style="list-style: none; padding: 0; margin: 0; position: relative;">';
    // The vertical line
    $history_html .= '<div style="position: absolute; left: 24px; top: 0; bottom: 0; width: 2px; background: #e0e0e0; z-index: 0;"></div>';
    
    while ($log = $history_result->fetch_assoc()) {
        $date = date('M d, Y', strtotime($log['created_at']));
        $time = date('H:i', strtotime($log['created_at']));
        $user = htmlspecialchars($log['username']);
        $role = strtoupper($log['role']);
        $details = nl2br(htmlspecialchars($log['details']));
        $type = $log['log_type'] ?? 'General';
        
        // Pick color based on type
        $color = $type_colors[$type] ?? '#607d8b';
        
        $history_html .= "
        <li style='position: relative; margin-bottom: 20px; padding-left: 60px; z-index: 1;'>
            <div style='position: absolute; left: 16px; top: 0; width: 18px; height: 18px; background: $color; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'></div>
            
            <div style='background: white; border: 1px solid #e0e0e0; border-left: 4px solid $color; border-radius: 6px; padding: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.02);'>
                <div style='display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px; color: #777;'>
                    <span><strong>$user</strong> ($role)</span>
                    <span>$date at $time</span>
                </div>
                <div style='font-weight: bold; color: $color; font-size: 11px; text-transform: uppercase; margin-bottom: 4px;'>$type</div>
                <div style='color: #333; line-height: 1.4;'>$details</div>
            </div>
        </li>";
    }
    $history_html .= '</ul>';
} else {
    $history_html .= '<div style="text-align: center; padding: 20px; color: #999; font-style: italic;">No investigation updates have been recorded yet.</div>';
}

$history_html .= '</div>'; // Close container
$output['history'] = $history_html;


// ==============================================================================
// 4. Fetch Attachments (UNCHANGED)
// ==============================================================================
$att_stmt = $conn->prepare("SELECT * FROM attachments WHERE incident_id = ?");
$att_stmt->bind_param("i", $incident_id);
$att_stmt->execute();
$attachments = $att_stmt->get_result();

$attachments_html = '';
if ($attachments->num_rows > 0) {
    $attachments_html .= '<ul style="margin: 10px 0; padding-left: 25px;">';
    while ($a = $attachments->fetch_assoc()) {
        $attachments_html .= '<li style="margin: 5px 0;">';
        $attachments_html .= '<a href="view_evidence.php?id=' . $a['id'] . '" target="_blank" style="color: #003366; text-decoration: none; font-weight: bold;">';
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
$output['print_url'] = 'print_blotter.php?id=' . $incident_id;
echo json_encode($output);
?>