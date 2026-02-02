<?php
// get_log_details.php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get log ID from request
$log_id = isset($_GET['log_id']) ? intval($_GET['log_id']) : 0;

if ($log_id <= 0) {
    echo json_encode(['error' => 'Invalid log ID']);
    exit();
}

// Fetch log details
$log_sql = "SELECT al.*, u.Full_Name, u.Email 
           FROM audit_logs al
           LEFT JOIN user u ON al.user_id = u.User_ID
           WHERE al.log_id = ?";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("i", $log_id);
$log_stmt->execute();
$log_result = $log_stmt->get_result();

if ($log_result->num_rows == 0) {
    echo json_encode(['error' => 'Log entry not found']);
    exit();
}

$log = $log_result->fetch_assoc();

// Return data as JSON
echo json_encode(['log' => $log]);
?>