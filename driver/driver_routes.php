<?php
session_start();

/* -----------------------------------------
   IMPORTANT: PHP TIMEZONE FIX (DO NOT REMOVE)
----------------------------------------- */
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once '../includes/config.php';

/* -----------------------------------------
   Access control
----------------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Driver') {
    header('Location: ../driver_login.php');
    exit();
}

$driver_id = (int)$_SESSION['user_id'];

/* -----------------------------------------
   START route action (PHP decides time)
----------------------------------------- */
if (isset($_POST['start_route'], $_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];

    $stmt = $conn->prepare("
        UPDATE shuttle_schedule
        SET Status = 'In Progress'
        WHERE Schedule_ID = ?
        AND Driver_ID = ?
        AND Status = 'Scheduled'
    ");
    $stmt->bind_param("ii", $schedule_id, $driver_id);
    $stmt->execute();

    header("Location: driver_routes.php");
    exit();
}

/* -----------------------------------------
   FINISH route action
----------------------------------------- */
if (isset($_POST['finish_route'], $_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];

    $stmt = $conn->prepare("
        UPDATE shuttle_schedule
        SET Status = 'Completed',
            Expected_Arrival = NOW()
        WHERE Schedule_ID = ?
        AND Driver_ID = ?
        AND Status = 'In Progress'
    ");
    $stmt->bind_param("ii", $schedule_id, $driver_id);
    $stmt->execute();

    header("Location: driver_routes.php");
    exit();
}

/* -----------------------------------------
   Get Scheduled + In Progress routes
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        ss.Schedule_ID,
        ss.Departure_time,
        ss.Expected_Arrival,
        ss.Status,
        r.Route_ID,
        r.Route_Name,
        r.Start_Location,
        r.End_Location,
        r.Estimated_Duration_Minutes
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE ss.Driver_ID = ?
    AND ss.Status IN ('Scheduled', 'In Progress')
    ORDER BY ss.Departure_time ASC
");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$schedules = $stmt->get_result();

/* -----------------------------------------
   Get route stops
----------------------------------------- */
$stops = [];
$result = $conn->query("
    SELECT Route_ID, Stop_Name, Stop_Order, Estimated_Time_From_Start
    FROM route_stops
    ORDER BY Route_ID, Stop_Order
");
while ($row = $result->fetch_assoc()) {
    $stops[$row['Route_ID']][] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>My Assigned Routes</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Tahoma;
}
body{ background:#f2f2f2; }

.container{
    max-width:1200px;
    margin:40px auto;
    padding:0 20px;
}

h1{ margin-bottom:8px; }
p{ color:#666; margin-bottom:25px; }

.card{
    background:white;
    padding:25px;
    border-radius:8px;
    border:1px solid #ddd;
    margin-bottom:25px;
}

.badge{
    color:white;
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
    margin-left:10px;
}
.badge.upcoming{ background:#4CAF50; }
.badge.active{ background:#FF9800; }

table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
}
th, td{
    padding:12px;
    border-bottom:1px solid #ddd;
    text-align:left;
}
th{ background:#f9f9f9; }

button{
    border:none;
    padding:10px 18px;
    border-radius:6px;
    cursor:pointer;
}

.start-btn{ background:#2196F3; color:white; }
.finish-btn{ background:#4CAF50; color:white; }

.disabled{
    background:#ccc;
    cursor:not-allowed;
}

.back-btn{
    background:#333;
    color:white;
    margin-top:15px;
}
</style>
</head>

<body>
<div class="container">

<h1>🛣️ My Assigned Routes</h1>
<p>Only scheduled and active routes are displayed</p>

<?php if ($schedules->num_rows): ?>
<?php while ($s = $schedules->fetch_assoc()): ?>

<?php
    /* -----------------------------------------
       PHP TIME COMPARISON (NOW FIXED)
    ----------------------------------------- */
    $now = time();
    $depart = strtotime($s['Departure_time']);

    if ($s['Status'] === 'Scheduled') {
        $statusText = 'Upcoming';
        $statusClass = 'upcoming';
    } else {
        $statusText = 'In Progress';
        $statusClass = 'active';
    }

    $canStart = ($s['Status'] === 'Scheduled' && $now >= $depart);
?>

<div class="card">
    <h2>
        <?= htmlspecialchars($s['Route_Name']); ?>
        <span class="badge <?= $statusClass; ?>">
            <?= $statusText; ?>
        </span>
    </h2>

    <p>
        📍 <?= $s['Start_Location']; ?> → <?= $s['End_Location']; ?><br>
        🕒 Departure: <?= date('M d, H:i', $depart); ?><br>
        ⏱ Estimated Duration: <?= $s['Estimated_Duration_Minutes']; ?> minutes
    </p>

    <h3>🛑 Route Stops</h3>
    <table>
        <tr>
            <th>#</th>
            <th>Stop</th>
            <th>Est. (min)</th>
            <th>Arrival</th>
        </tr>
        <?php foreach ($stops[$s['Route_ID']] ?? [] as $stop): ?>
        <tr>
            <td><?= $stop['Stop_Order']; ?></td>
            <td><?= htmlspecialchars($stop['Stop_Name']); ?></td>
            <td><?= $stop['Estimated_Time_From_Start']; ?></td>
            <td><?= date('H:i', strtotime($s['Departure_time'].' +'.$stop['Estimated_Time_From_Start'].' minutes')); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- ACTION BUTTONS -->
    <?php if ($s['Status'] === 'Scheduled'): ?>
        <form method="post" style="margin-top:15px;">
            <input type="hidden" name="schedule_id" value="<?= $s['Schedule_ID']; ?>">
            <button
                type="submit"
                name="start_route"
                class="start-btn <?= $canStart ? '' : 'disabled'; ?>"
                <?= $canStart ? '' : 'disabled'; ?>>
                ▶ Start Route
            </button>
        </form>

    <?php elseif ($s['Status'] === 'In Progress'): ?>
        <form method="post" style="margin-top:15px;">
            <input type="hidden" name="schedule_id" value="<?= $s['Schedule_ID']; ?>">
            <button type="submit" name="finish_route" class="finish-btn">
                ✅ Finish Route
            </button>
        </form>
    <?php endif; ?>

</div>

<?php endwhile; ?>
<?php else: ?>
<p>No routes assigned.</p>
<?php endif; ?>

<button class="back-btn" onclick="location.href='driver_dashboard.php'">
    ⬅ Back to Dashboard
</button>

</div>
</body>
</html>
