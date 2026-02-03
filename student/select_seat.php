<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

if (
    !isset($_SESSION['route_id']) ||
    !isset($_SESSION['time_id']) ||
    !isset($_SESSION['pickup_stop_id']) ||
    !isset($_SESSION['dropoff_stop_id'])
) {
    header('Location: book_shuttle.php');
    exit();
}

/* ✅ USE SESSION ONLY */
$route_id = (int) $_SESSION['route_id'];
$time_id  = (int) $_SESSION['time_id'];
$pickup_stop_id  = (int) $_SESSION['pickup_stop_id'];
$dropoff_stop_id = (int) $_SESSION['dropoff_stop_id'];

/* ------------------------------------
   Get route & vehicle capacity
------------------------------------ */
$sql = "
SELECT r.Route_Name, v.Capacity
FROM shuttle_schedule ss
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
WHERE ss.Route_ID = ?
ORDER BY ss.Departure_time ASC
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $route_id);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();

if (!$info) {
    die("No shuttle scheduled for this route.");
}

$capacity = (int)$info['Capacity'];

/* ------------------------------------
   Get reserved seats (route + time)
------------------------------------ */
$reservedSeats = [];

$sql = "
SELECT Seat_number
FROM seat_reservation
WHERE Route_ID = ?
AND Time_ID = ?
AND Status = 'Reserved'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $route_id, $time_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservedSeats[] = (int)$row['Seat_number'];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Select Seat</title>
<style>

body {
    font-family: Arial;
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
.available { background:#4CAF50; color:white; }
.reserved { background:#ccc; color:#666; cursor:not-allowed; }
.selected { background:#FF9800; }
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
}
</style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">
<h2>🚌 <?= htmlspecialchars($info['Route_Name']); ?></h2>
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
