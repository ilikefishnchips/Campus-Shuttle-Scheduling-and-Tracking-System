<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header('Location: ../coordinator_login.php');
    exit();
}

// Get notification ID from POST request
$notification_id = $_POST['notification_id'] ?? null;

if($notification_id) {
    // Update notification status to read
    $sql = "UPDATE notifications SET Status = 'Read', Read_At = NOW() WHERE Notification_ID = ? AND User_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
    $stmt->execute();
}

// Redirect back to dashboard
header('Location: index.php');
exit();
?>