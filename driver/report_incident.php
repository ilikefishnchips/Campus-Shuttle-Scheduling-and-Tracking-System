<?php
session_start();
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("
        INSERT INTO incident_reports
        (Schedule_ID, Report_Type, Description, Reported_By)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issi",
        $_POST['schedule_id'],
        $_POST['type'],
        $_POST['description'],
        $_SESSION['user_id']
    );
    $stmt->execute();
}
?>
<form method="POST">
    <input type="hidden" name="schedule_id">
    <select name="type">
        <option>Breakdown</option>
        <option>Accident</option>
        <option>Traffic</option>
        <option>Passenger Issue</option>
    </select>
    <textarea name="description"></textarea>
    <button>Submit</button>
</form>
