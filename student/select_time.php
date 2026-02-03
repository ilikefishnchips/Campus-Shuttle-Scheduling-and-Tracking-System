<?php
session_start();
require_once '../includes/config.php';

/* ===============================
   TIMEZONE (IMPORTANT)
================================ */
date_default_timezone_set('Asia/Kuala_Lumpur');

/* Prevent cache issues */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* -----------------------------------------
   Access control
----------------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* -----------------------------------------
   SAVE POST → REDIRECT (PRG PATTERN)
----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_stop_id'])) {

    $_SESSION['route_id'] = (int)$_POST['route_id'];
    $_SESSION['pickup_stop_id'] = (int)$_POST['pickup_stop_id'];
    $_SESSION['dropoff_stop_id'] = (int)$_POST['dropoff_stop_id'];

    /* Validate pickup < dropoff */
    $stmt = $conn->prepare("
        SELECT Stop_ID, Stop_Order
        FROM route_stops
        WHERE Stop_ID IN (?, ?)
    ");
    $stmt->bind_param("ii", $_POST['pickup_stop_id'], $_POST['dropoff_stop_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[$row['Stop_ID']] = (int)$row['Stop_Order'];
    }

    if (
        !isset($orders[$_POST['pickup_stop_id']]) ||
        !isset($orders[$_POST['dropoff_stop_id']]) ||
        $orders[$_POST['pickup_stop_id']] >= $orders[$_POST['dropoff_stop_id']]
    ) {
        die("Invalid stop selection. Drop-off must be after pick-up.");
    }

    header("Location: select_time.php");
    exit();
}

/* -----------------------------------------
   TIME SELECTED → REDIRECT TO SEAT
----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_id'])) {
    $_SESSION['time_id'] = (int)$_POST['time_id'];
    header("Location: select_seat.php");
    exit();
}

/* -----------------------------------------
   Required session data
----------------------------------------- */
if (
    !isset($_SESSION['route_id']) ||
    !isset($_SESSION['pickup_stop_id']) ||
    !isset($_SESSION['dropoff_stop_id'])
) {
    header("Location: book_shuttle.php");
    exit();
}

$route_id = (int)$_SESSION['route_id'];
$pickup_stop_id = (int)$_SESSION['pickup_stop_id'];
$dropoff_stop_id = (int)$_SESSION['dropoff_stop_id'];

/* -----------------------------------------
   Get route name
----------------------------------------- */
$stmt = $conn->prepare("SELECT Route_Name FROM route WHERE Route_ID = ?");
$stmt->bind_param("i", $route_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();

/* -----------------------------------------
   Get pickup stop offset
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Stop_Name, Estimated_Time_From_Start
    FROM route_stops
    WHERE Stop_ID = ?
");
$stmt->bind_param("i", $pickup_stop_id);
$stmt->execute();
$pickupStop = $stmt->get_result()->fetch_assoc();

$pickupOffset = (int)$pickupStop['Estimated_Time_From_Start'];
$pickupStopName = $pickupStop['Stop_Name'];

/* -----------------------------------------
   Get drop-off stop offset (NEW)
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Stop_Name, Estimated_Time_From_Start
    FROM route_stops
    WHERE Stop_ID = ?
");
$stmt->bind_param("i", $dropoff_stop_id);
$stmt->execute();
$dropoffStop = $stmt->get_result()->fetch_assoc();

$dropoffOffset = (int)$dropoffStop['Estimated_Time_From_Start'];
$dropoffStopName = $dropoffStop['Stop_Name'];

/* -----------------------------------------
   Get route start times
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Time_ID, Departure_Time
    FROM route_time
    WHERE Route_ID = ?
    ORDER BY Departure_Time
");
$stmt->bind_param("i", $route_id);
$stmt->execute();
$times = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Current date & time */
$today = date('Y-m-d');
$now = new DateTime();
?>
<!DOCTYPE html>
<html>
<head>
<title>Select Time</title>
<style>
body {
    font-family:'Segoe UI', sans-serif;
    background:#f4f4f4;
    margin:0;
}
.container {
    max-width:600px;
    margin:40px auto;
    background:white;
    padding:30px;
    border-radius:10px;
}
.time-card {
    border:1px solid #ddd;
    padding:20px;
    border-radius:8px;
    margin-bottom:15px;
}
.time-info {
    font-size:14px;
    color:#555;
    margin-top:5px;
}
button {
    width:100%;
    padding:12px;
    background:#2196F3;
    color:white;
    border:none;
    border-radius:8px;
    cursor:pointer;
}
.no-data {
    text-align:center;
    color:#777;
    margin-top:30px;
}
</style>
</head>
<body>

<?php include __DIR__ . '/student_navbar.php'; ?>

<div class="container">
<h2>Select Departure Time</h2>
<p>
    <strong>Route:</strong> <?= htmlspecialchars($route['Route_Name']); ?><br>
    <strong>Pickup:</strong> <?= htmlspecialchars($pickupStopName); ?><br>
    <strong>Drop-off:</strong> <?= htmlspecialchars($dropoffStopName); ?>
</p>

<?php
$shown = false;

foreach ($times as $t):

    $routeStart = new DateTime($today . ' ' . $t['Departure_Time']);

    /* Pickup time */
    $pickupDateTime = clone $routeStart;
    $pickupDateTime->modify("+{$pickupOffset} minutes");

    /* Arrival time */
    $arrivalDateTime = clone $routeStart;
    $arrivalDateTime->modify("+{$dropoffOffset} minutes");

    /* Skip past pickup times */
    if ($pickupDateTime <= $now) {
        continue;
    }

    $shown = true;
?>
<div class="time-card">
    <p>🕒 Pickup: <strong><?= $pickupDateTime->format('H:i'); ?></strong></p>

    <div class="time-info">
        🏁 Estimated Arrival: <strong><?= $arrivalDateTime->format('H:i'); ?></strong><br>
        🚍 Shuttle starts at <?= date('H:i', strtotime($t['Departure_Time'])); ?>
    </div>

    <form method="POST">
        <input type="hidden" name="time_id" value="<?= $t['Time_ID']; ?>">
        <button>Select Seat</button>
    </form>
</div>
<?php endforeach; ?>

<?php if (!$shown): ?>
    <div class="no-data">
        No remaining shuttle for today.<br>
        Please check again tomorrow.
    </div>
<?php endif; ?>

</div>
</body>
</html>
