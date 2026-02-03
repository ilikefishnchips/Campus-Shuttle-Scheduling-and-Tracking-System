<?php
session_start();
require_once '../includes/config.php';

if ($_SESSION['role'] !== 'Driver') exit();

$schedule_id = (int)$_GET['schedule_id'];

$stmt = $conn->prepare("
    UPDATE shuttle_schedule
    SET Status = 'In Progress'
    WHERE Schedule_ID = ?
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();

header("Location: driver_dashboard.php");
