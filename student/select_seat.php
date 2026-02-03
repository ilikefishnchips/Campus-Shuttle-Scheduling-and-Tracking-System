<?php
session_start();
require_once '../includes/config.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===============================
   ACCESS CONTROL
================================ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* ===============================
   REQUIRED SESSION DATA
================================ */
if (
    !isset($_SESSION['route_id']) ||
    !isset($_SESSION['time_id']) ||
    !isset($_SESSION['travel_date']) ||
    !isset($_SESSION['pickup_stop_id']) ||
    !isset($_SESSION['dropoff_stop_id'])
) {
    header('Location: book_shuttle.php');
    exit();
}

$route_id    = (int) $_SESSION['route_id'];
$time_id     = (int) $_SESSION['time_id'];
$travel_date = $_SESSION['travel_date'];

/* ===============================
   GET DEPARTURE TIME FROM Time_ID
================================ */
$stmt = $conn->prepare("
    SELECT Departure_Time
    FROM route_time
    WHERE Time_ID = ? AND Route_ID = ?
");
$stmt->bind_param("ii", $time_id, $route_id);
$stmt->execute();
$timeRow = $stmt->get_result()->fetch_assoc();

if (!$timeRow) {
    die("Invalid time selection.");
}

$departure_time = $timeRow['Departure_Time']; // HH:MM:SS

/* ===============================
   FIND EXACT SCHEDULE
================================ */
$stmt = $conn->prepare("
    SELECT ss.Schedule_ID, r.Route_Name, v.Capacity
    FROM shuttle_schedule ss
    JOIN route r   ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    WHERE ss.Route_ID = ?
      AND DATE(ss.Departure_time) = ?
      AND TIME(ss.Departure_time) = ?
      AND ss.Status IN ('Scheduled', 'In Progress')
    LIMIT 1
");
$stmt->bind_param("iss", $route_id, $travel_date, $departure_time);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();

if (!$schedule) {
    die("❌ No shuttle available for the selected date and time.");
}

$schedule_id = (int) $schedule['Schedule_ID'];
$route_name  = $schedule['Route_Name'];
$capacity    = (int) $schedule['Capacity'];

/* ===============================
   GET RESERVED SEATS (EXACT SCHEDULE)
================================ */
$reservedSeats = [];

$stmt = $conn->prepare("
    SELECT Seat_number
    FROM seat_reservation
    WHERE Schedule_ID = ?
      AND Status = 'Reserved'
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservedSeats[] = (int) $row['Seat_number'];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Select Seat</title>
<style>
body {
    font-family: 'Segoe UI', sans-serif;
    background:#f5f5f5;
    margin: 0;
}

.container {
    max-width:600px;
    margin:40px auto;
    background:white;
    padding:30px;
    border-radius:10px;
}

.bus {
    display:grid;
    grid-template-columns: repeat(4, 1fr);
    gap:10px;
    margin-top:20px;
}

.seat {
    padding:15px;
    text-align:center;
    border-radius:6px;
    cursor:pointer;
    font-weight:bold;
}

.available {
    background:#4CAF50;
    color:white;
}

.reserved {
    background:#ccc;
    color:#666;
    cursor:not-allowed;
}

.selected {
    background:#FF9800;
    color:white;
}

.driver {
    grid-column: span 4;
    text-align:center;
    font-weight:bold;
    margin-bottom:10px;
}

button {
    margin-top:20px;
    padding:12px;
    width:100%;
    background:#2196F3;
    color:white;
    border:none;
    border-radius:6px;
    font-size:16px;
    cursor:pointer;
}
</style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">
    <h2>🚌 <?= htmlspecialchars($route_name); ?></h2>
    <p>
        <strong>Date:</strong> <?= htmlspecialchars($travel_date); ?><br>
        <strong>Departure:</strong> <?= date('H:i', strtotime($departure_time)); ?>
    </p>

    <p>Select your seat</p>

    <div class="bus">
        <div class="driver">🚍 Driver</div>

        <?php for ($i = 1; $i <= $capacity; $i++): ?>
            <?php if (in_array($i, $reservedSeats)): ?>
                <div class="seat reserved">#<?= $i ?></div>
            <?php else: ?>
                <div class="seat available" onclick="selectSeat(<?= $i ?>, this)">#<?= $i ?></div>
            <?php endif; ?>
        <?php endfor; ?>
    </div>

    <form action="confirm_booking.php" method="POST">
        <input type="hidden" name="seat_number" id="seat_number" required>
        <button type="submit">Confirm Seat</button>
    </form>
</div>

<script>
function selectSeat(num, el) {
    document.querySelectorAll('.seat').forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('seat_number').value = num;
}
</script>

</body>
</html>
