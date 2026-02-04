<?php
session_start();
require_once '../includes/config.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $schedule_id = $_GET['schedule_id'] ?? 0;
    
    // Only cancel if schedule is in the future
    $sql = "UPDATE shuttle_schedule 
            SET Status = 'Cancelled' 
            WHERE Schedule_ID = ? 
            AND Departure_time > NOW() 
            AND Status = 'Scheduled'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $schedule_id);
    
    if($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel this schedule']);
    }
}