<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$schedule_id = $_GET['id'];

$sql = "SELECT Status FROM shuttle_schedule WHERE Schedule_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$status = $stmt->get_result()->fetch_assoc()['Status'];

$newStatus = ($status === "In Progress") ? "Completed" : "In Progress";

$update = $conn->prepare(
    "UPDATE shuttle_schedule SET Status = ? WHERE Schedule_ID = ?"
);
$update->bind_param("si", $newStatus, $schedule_id);
$update->execute();

header("Location: driver_dashboard.php");
exit();
