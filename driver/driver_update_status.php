<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$schedule_id = $_GET['id'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $status = $_POST['status'];
    $message = $_POST['message'];

    $sql = "INSERT INTO shuttle_status_update 
            (Schedule_ID, Status, Message)
            VALUES (?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $schedule_id, $status, $message);
    $stmt->execute();

    header("Location: driver_dashboard.php");
    exit();
}
?>

<h3>Update Shuttle Status</h3>

<form method="POST">
    <select name="status" required>
        <option>On Time</option>
        <option>Delayed</option>
        <option>Breakdown</option>
        <option>Accident</option>
        <option>Traffic</option>
    </select><br><br>

    <textarea name="message" placeholder="Message"></textarea><br><br>
    <button type="submit">Update</button>
</form>
