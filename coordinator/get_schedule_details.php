<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if(isset($_GET['schedule_id'])) {
    $schedule_id = intval($_GET['schedule_id']);
    
    $sql = "SELECT ss.*, r.Route_Name, v.Plate_number, u.Full_Name 
            FROM shuttle_schedule ss
            LEFT JOIN route r ON ss.Route_ID = r.Route_ID
            LEFT JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
            LEFT JOIN user u ON ss.Driver_ID = u.User_ID
            WHERE ss.Schedule_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $schedule = $result->fetch_assoc();
        echo json_encode(['success' => true, 'schedule' => $schedule]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No schedule ID provided']);
}
?>