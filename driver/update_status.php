<?php
session_start();
require_once '../includes/config.php';

$schedule_id = (int)$_POST['schedule_id'];
$status = $_POST['status'];

$stmt = $conn->prepare("
    UPDATE shuttle_schedule
    SET Status = ?
    WHERE Schedule_ID = ?
");
$stmt->bind_param("si", $status, $schedule_id);
$stmt->execute();

header("Location: driver_dashboard.php");
