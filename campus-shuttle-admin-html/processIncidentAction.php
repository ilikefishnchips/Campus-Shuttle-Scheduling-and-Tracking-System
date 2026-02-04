<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

// Get the action and incident ID
$action = isset($_POST['action']) ? $_POST['action'] : '';
$incident_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if($incident_id <= 0) {
    die(json_encode(['success' => false, 'message' => 'Invalid incident ID']));
}

$admin_id = $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

try {
    if($action == 'approve') {
        // Get incident details with route information
        $incident_sql = "
            SELECT ir.*, ss.Route_ID, r.Route_Name 
            FROM incident_reports ir
            LEFT JOIN shuttle_schedule ss ON ir.Schedule_ID = ss.Schedule_ID
            LEFT JOIN route r ON ss.Route_ID = r.Route_ID
            WHERE ir.Incident_ID = ?
        ";
        
        $incident_stmt = $conn->prepare($incident_sql);
        $incident_stmt->bind_param("i", $incident_id);
        $incident_stmt->execute();
        $incident = $incident_stmt->get_result()->fetch_assoc();
        
        if (!$incident) {
            die(json_encode(['success' => false, 'message' => 'Incident not found']));
        }
        
        // Approve incident and send to transport coordinator
        $sql = "UPDATE incident_reports 
                SET admin_status = 'Approved', 
                    admin_notes = ?, 
                    admin_action_by = ?, 
                    admin_action_at = ?,
                    Status = 'Under Review'
                WHERE Incident_ID = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sisi", $notes, $admin_id, $now, $incident_id);
        
        if($stmt->execute()) {
            // Create notification for transport coordinators
            $coordinator_sql = "
                SELECT u.User_ID 
                FROM user u
                JOIN user_roles ur ON u.User_ID = ur.User_ID
                JOIN roles r ON ur.Role_ID = r.Role_ID
                WHERE r.Role_name = 'Transport Coordinator'
            ";
            
            $coordinators = $conn->query($coordinator_sql);
            
            while($coordinator = $coordinators->fetch_assoc()) {
                $route_id = $incident['Route_ID'] ?? null;
                
                if ($route_id) {
                    $notification_sql = "
                        INSERT INTO notifications (User_ID, Title, Message, Type, Priority, Related_Route_ID)
                        VALUES (?, 'New Incident Requires Attention', 
                                CONCAT('An incident (ID: #', ?, ') has been approved by admin and requires your attention. Type: ', ?), 
                                'Alert', 'High', ?)
                    ";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $notification_stmt->bind_param("iisi", 
                        $coordinator['User_ID'], 
                        $incident_id, 
                        $incident['Incident_Type'],
                        $route_id
                    );
                } else {
                    $notification_sql = "
                        INSERT INTO notifications (User_ID, Title, Message, Type, Priority)
                        VALUES (?, 'New Incident Requires Attention', 
                                CONCAT('An incident (ID: #', ?, ') has been approved by admin and requires your attention. Type: ', ?), 
                                'Alert', 'High')
                    ";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $notification_stmt->bind_param("iis", 
                        $coordinator['User_ID'], 
                        $incident_id, 
                        $incident['Incident_Type']
                    );
                }
                $notification_stmt->execute();
            }
            
            echo json_encode(['success' => true, 'message' => 'Incident approved and sent to transport coordinator']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to approve incident']);
        }
        
    } elseif($action == 'decline') {
        // Decline incident (delete it)
        if(empty($reason)) {
            die(json_encode(['success' => false, 'message' => 'Reason is required for declining']));
        }
        
        // First, log the decline action
        $log_sql = "
            INSERT INTO deleted_incidents_log 
            (original_incident_id, incident_type, description, reporter_id, 
             vehicle_id, report_time, decline_reason, declined_by, declined_at)
            SELECT 
                Incident_ID, Incident_Type, Description, Reporter_ID,
                Vehicle_ID, Report_time, ?, ?, ?
            FROM incident_reports 
            WHERE Incident_ID = ?
        ";
        
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("sisi", $reason, $admin_id, $now, $incident_id);
        $log_stmt->execute();
        
        // Then delete the incident
        $delete_sql = "DELETE FROM incident_reports WHERE Incident_ID = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $incident_id);
        
        if($delete_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Incident report deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete incident']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>