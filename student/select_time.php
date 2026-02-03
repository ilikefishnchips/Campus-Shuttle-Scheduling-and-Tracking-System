<?php
session_start();

/* ===============================
   PHP TIMEZONE (IMPORTANT)
================================ */
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once '../includes/config.php';

/* ===============================
   ACCESS CONTROL
================================ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* ===============================
   SAVE ROUTE + STOPS (PRG)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pickup_stop_id'])) {

    $_SESSION['route_id']        = (int) $_POST['route_id'];
    $_SESSION['pickup_stop_id']  = (int) $_POST['pickup_stop_id'];
    $_SESSION['dropoff_stop_id'] = (int) $_POST['dropoff_stop_id'];
    $_SESSION['travel_date']     = $_POST['travel_date'];

    /* Validate pickup < dropoff */
    $stmt = $conn->prepare("
        SELECT Stop_ID, Stop_Order
        FROM route_stops
        WHERE Stop_ID IN (?, ?)
    ");
    $stmt->bind_param("ii", $_POST['pickup_stop_id'], $_POST['dropoff_stop_id']);
    $stmt->execute();

    $orders = [];
    foreach ($stmt->get_result() as $row) {
        $orders[$row['Stop_ID']] = (int)$row['Stop_Order'];
    }

    if ($orders[$_POST['pickup_stop_id']] >= $orders[$_POST['dropoff_stop_id']]) {
        die("Invalid stop selection.");
    }

    header("Location: select_time.php");
    exit();
}

/* ===============================
   TIME SELECTED → SEAT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_id'])) {
    $_SESSION['schedule_id'] = (int) $_POST['schedule_id'];
    $_SESSION['travel_date'] = $_POST['travel_date'];

    header("Location: select_seat.php");
    exit();
}

/* ===============================
   REQUIRED SESSION DATA
================================ */
if (
    !isset($_SESSION['route_id']) ||
    !isset($_SESSION['pickup_stop_id']) ||
    !isset($_SESSION['dropoff_stop_id'])
) {
    header("Location: book_shuttle.php");
    exit();
}

$route_id = (int) $_SESSION['route_id'];
$pickup_stop_id  = (int) $_SESSION['pickup_stop_id'];
$dropoff_stop_id = (int) $_SESSION['dropoff_stop_id'];

/* ===============================
   SELECT DATE (DEFAULT TODAY)
================================ */
if (isset($_GET['date'])) {
    $travel_date = $_GET['date'];
    $_SESSION['travel_date'] = $travel_date;
} elseif (isset($_SESSION['travel_date'])) {
    $travel_date = $_SESSION['travel_date'];
} else {
    $travel_date = date('Y-m-d');
    $_SESSION['travel_date'] = $travel_date;
}

/* ===============================
   ROUTE + STOP INFO
================================ */
$route = $conn->query("
    SELECT Route_Name 
    FROM route 
    WHERE Route_ID = $route_id
")->fetch_assoc();

$pickup = $conn->query("
    SELECT Stop_Name, Estimated_Time_From_Start
    FROM route_stops
    WHERE Stop_ID = $pickup_stop_id
")->fetch_assoc();

$dropoff = $conn->query("
    SELECT Stop_Name, Estimated_Time_From_Start
    FROM route_stops
    WHERE Stop_ID = $dropoff_stop_id
")->fetch_assoc();

/* ===============================
   GET SCHEDULED SHUTTLES ONLY
================================ */
$stmt = $conn->prepare("
    SELECT 
        Schedule_ID,
        Departure_time
    FROM shuttle_schedule
    WHERE Route_ID = ?
      AND DATE(Departure_time) = ?
      AND Status = 'Scheduled'
    ORDER BY Departure_time
");
$stmt->bind_param("is", $route_id, $travel_date);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$now   = new DateTime();
$today = date('Y-m-d');
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
.date-bar {
    margin-bottom:20px;
}
</style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">

<h2>Select Departure Time</h2>

<p>
    <strong>Route:</strong> <?= htmlspecialchars($route['Route_Name']); ?><br>
    <strong>Pickup:</strong> <?= htmlspecialchars($pickup['Stop_Name']); ?><br>
    <strong>Drop-off:</strong> <?= htmlspecialchars($dropoff['Stop_Name']); ?>
</p>

<!-- DATE SELECT -->
<form method="GET" class="date-bar">
    <input type="date" name="date"
           value="<?= htmlspecialchars($travel_date); ?>"
           min="<?= date('Y-m-d'); ?>"
           onchange="this.form.submit()">
</form>

<?php
$hasVisible = false;

foreach ($schedules as $s):

    $routeStart = new DateTime($s['Departure_time']);

    // ⛔ Hide past times ONLY if today
    if ($travel_date === $today && $routeStart <= $now) {
        continue;
    }

    $hasVisible = true;

    $pickupTime  = (clone $routeStart)->modify("+{$pickup['Estimated_Time_From_Start']} minutes");
    $arrivalTime = (clone $routeStart)->modify("+{$dropoff['Estimated_Time_From_Start']} minutes");
?>

<div class="time-card">
    <p>🕒 Pickup: <strong><?= $pickupTime->format('H:i'); ?></strong></p>

    <div class="time-info">
        🏁 Arrival: <?= $arrivalTime->format('H:i'); ?><br>
        🚍 Starts: <?= $routeStart->format('H:i'); ?>
    </div>

    <form method="POST">
        <input type="hidden" name="schedule_id" value="<?= $s['Schedule_ID']; ?>">
        <input type="hidden" name="travel_date" value="<?= $travel_date; ?>">
        <button>Select Seat</button>
    </form>
</div>

<?php endforeach; ?>

<?php if (!$hasVisible): ?>
    <div class="no-data">
        No scheduled shuttles available for this date.
    </div>
<?php endif; ?>

</div>
</body>
</html>
