<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    die(json_encode(['error' => 'Unauthorized access']));
}

$incident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($incident_id <= 0) {
    die(json_encode(['error' => 'Invalid incident ID']));
}

// Fetch incident details with related information
$sql = "
    SELECT 
        ir.*,
        u.Full_Name as reporter_name,
        u.Email as reporter_email,
        v.Plate_number,
        v.Model as vehicle_model,
        r.Route_Name,
        ss.Departure_time,
        ss.Expected_Arrival,
        a_user.Full_Name as admin_action_name
    FROM incident_reports ir
    LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
    LEFT JOIN vehicle v ON ir.Vehicle_ID = v.Vehicle_ID
    LEFT JOIN shuttle_schedule ss ON ir.Schedule_ID = ss.Schedule_ID
    LEFT JOIN route r ON ss.Route_ID = r.Route_ID
    LEFT JOIN user a_user ON ir.admin_action_by = a_user.User_ID
    WHERE ir.Incident_ID = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    die(json_encode(['error' => 'Incident not found']));
}

$incident = $result->fetch_assoc();

// Format the response as HTML
$html = '
<div class="incident-details">
    <div class="details-grid">
        <div class="detail-item">
            <strong>Incident ID:</strong>
            <span>#' . $incident['Incident_ID'] . '</span>
        </div>
        <div class="detail-item">
            <strong>Type:</strong>
            <span class="badge badge-type">' . $incident['Incident_Type'] . '</span>
        </div>
        <div class="detail-item">
            <strong>Priority:</strong>
            <span class="badge priority-' . strtolower($incident['Priority']) . '">' . $incident['Priority'] . '</span>
        </div>
        <div class="detail-item">
            <strong>Status:</strong>
            <span class="badge status-' . strtolower($incident['Status']) . '">' . $incident['Status'] . '</span>
        </div>
        <div class="detail-item">
            <strong>Admin Status:</strong>
            <span class="badge admin-status-' . strtolower($incident['admin_status']) . '">' . $incident['admin_status'] . '</span>
        </div>
    </div>

    <div class="details-section">
        <h4><i class="fas fa-user"></i> Reporter Information</h4>
        <div class="detail-item">
            <strong>Name:</strong>
            <span>' . htmlspecialchars($incident['reporter_name']) . '</span>
        </div>
        <div class="detail-item">
            <strong>Email:</strong>
            <span>' . htmlspecialchars($incident['reporter_email']) . '</span>
        </div>
        <div class="detail-item">
            <strong>Reported At:</strong>
            <span>' . date('Y-m-d H:i:s', strtotime($incident['Report_time'])) . '</span>
        </div>
    </div>

    <div class="details-section">
        <h4><i class="fas fa-bus"></i> Vehicle Information</h4>
        <div class="detail-item">
            <strong>Vehicle:</strong>
            <span>' . htmlspecialchars($incident['Plate_number'] ?: 'Not specified') . '</span>
        </div>
        <div class="detail-item">
            <strong>Model:</strong>
            <span>' . htmlspecialchars($incident['vehicle_model'] ?: 'Not specified') . '</span>
        </div>
    </div>

    <div class="details-section">
        <h4><i class="fas fa-route"></i> Route & Schedule</h4>
        <div class="detail-item">
            <strong>Route:</strong>
            <span>' . htmlspecialchars($incident['Route_Name'] ?: 'Not specified') . '</span>
        </div>
        <div class="detail-item">
            <strong>Departure:</strong>
            <span>' . ($incident['Departure_time'] ? date('Y-m-d H:i', strtotime($incident['Departure_time'])) : 'Not specified') . '</span>
        </div>
    </div>

    <div class="details-section">
        <h4><i class="fas fa-file-alt"></i> Incident Description</h4>
        <div class="description-box">
            ' . nl2br(htmlspecialchars($incident['Description'])) . '
        </div>
    </div>';

if($incident['admin_notes']) {
    $html .= '
    <div class="details-section">
        <h4><i class="fas fa-sticky-note"></i> Admin Notes</h4>
        <div class="notes-box">
            ' . nl2br(htmlspecialchars($incident['admin_notes'])) . '
        </div>
    </div>';
}

if($incident['admin_action_name']) {
    $html .= '
    <div class="details-section">
        <h4><i class="fas fa-user-shield"></i> Admin Action</h4>
        <div class="detail-item">
            <strong>Action By:</strong>
            <span>' . htmlspecialchars($incident['admin_action_name']) . '</span>
        </div>
        <div class="detail-item">
            <strong>Action At:</strong>
            <span>' . ($incident['admin_action_at'] ? date('Y-m-d H:i:s', strtotime($incident['admin_action_at'])) : 'N/A') . '</span>
        </div>
    </div>';
}

$html .= '</div>';

echo $html;
?>