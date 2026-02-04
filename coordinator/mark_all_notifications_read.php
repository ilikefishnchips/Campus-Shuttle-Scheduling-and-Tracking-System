<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header('Location: ../coordinator_login.php');
    exit();
}

// Update all unread notifications for this user to read
$sql = "UPDATE notifications SET Status = 'Read', Read_At = NOW() WHERE User_ID = ? AND Status = 'Unread'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();

// Redirect back to dashboard
header('Location: index.php');
exit();
?>