<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$driver_id = $_SESSION['driver_id'];
$schedule_id = $_GET['id'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $type = $_POST['type'];
    $desc = $_POST['description'];

    $sql = "
    INSERT INTO incident_reports
    (Reporter_ID, Schedule_ID, Incident_Type, Description)
    VALUES (?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $driver_id, $schedule_id, $type, $desc);
    $stmt->execute();

    header("Location: driver_dashboard.php");
    exit();
}
?>

<h3>Report Incident</h3>

<form method="POST">
<select name="type" required>
    <option>Breakdown</option>
    <option>Accident</option>
    <option>Traffic obstruction</option>
    <option>Passenger behavior</option>
</select><br><br>

<textarea name="description" required></textarea><br><br>
<button type="submit">Submit</button>
</form>
