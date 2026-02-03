<?php
session_start();
require_once '../includes/config.php';

/* ===============================
   ACCESS CONTROL
================================ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Driver') {
    header("Location: driver_login.php");
    exit();
}

/* ===============================
   VALIDATE schedule_id
================================ */
if (!isset($_GET['schedule_id'])) {
    die("Invalid access.");
}

$schedule_id = (int)$_GET['schedule_id'];

/* ===============================
   GET SCHEDULE DETAILS
================================ */
$stmt = $conn->prepare("
    SELECT ss.Route_ID, ss.Departure_time, r.Route_Name
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE ss.Schedule_ID = ?
      AND ss.Driver_ID = ?
");
$stmt->bind_param("ii", $schedule_id, $_SESSION['user_id']);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();

if (!$schedule) {
    die("Schedule not found.");
}

$route_id = (int)$schedule['Route_ID'];
$departure_time = $schedule['Departure_time'];
$route_name = $schedule['Route_Name'];

/* ===============================
   GET Time_ID from route_time
================================ */
$stmt = $conn->prepare("
    SELECT Time_ID
    FROM route_time
    WHERE Route_ID = ?
      AND Departure_Time = ?
    LIMIT 1
");
$stmt->bind_param("is", $route_id, $departure_time);
$stmt->execute();
$time = $stmt->get_result()->fetch_assoc();

if (!$time) {
    die("Time slot not found for this schedule.");
}

$time_id = (int)$time['Time_ID'];

/* ===============================
   GET PASSENGER LIST
================================ */
$stmt = $conn->prepare("
    SELECT 
        sp.Student_Number,
        sr.Seat_number
    FROM seat_reservation sr
    JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
    WHERE sr.Route_ID = ?
      AND sr.Time_ID = ?
      AND sr.Status = 'Reserved'
    ORDER BY sr.Seat_number
");
$stmt->bind_param("ii", $route_id, $time_id);
$stmt->execute();
$passengers = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Passenger List</title>
<style>
body {
    font-family: 'Segoe UI', sans-serif;
    background: #f4f4f4;
    margin: 0;
}
.container {
    max-width: 900px;
    margin: 40px auto;
    background: white;
    padding: 30px;
    border-radius: 10px;
}
h2 {
    margin-bottom: 10px;
}
.route-info {
    color: #555;
    margin-bottom: 20px;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    background: #222;
    color: white;
    padding: 12px;
    text-align: left;
}
td {
    padding: 12px;
    border-bottom: 1px solid #eee;
}
.no-data {
    text-align: center;
    color: #777;
    margin-top: 30px;
}
</style>
</head>
<body>

<div class="container">
    <h2>🧑‍🎓 Passenger List</h2>
    <div class="route-info">
        <strong>Route:</strong> <?= htmlspecialchars($route_name); ?><br>
        <strong>Departure:</strong> <?= date('H:i', strtotime($departure_time)); ?>
    </div>

    <?php if ($passengers->num_rows > 0): ?>
        <table>
            <tr>
                <th>Seat</th>
                <th>Student Number</th>
            </tr>
            <?php while ($p = $passengers->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $p['Seat_number']; ?></td>
                    <td><?= htmlspecialchars($p['Student_Number']); ?></td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <div class="no-data">
            No passengers booked for this trip.
        </div>
    <?php endif; ?>
</div>

</body>
</html>
