<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* -----------------------------------------
   Get Student_ID
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Student_ID
    FROM student_profile
    WHERE User_ID = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student profile not found.");
}

$student_id = (int)$student['Student_ID'];

/* -----------------------------------------
   Get recent booking activities (Route + Time)
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        sr.Status,
        r.Route_Name,
        rt.Departure_Time,
        sr.Booking_Time
    FROM seat_reservation sr
    JOIN route r ON sr.Route_ID = r.Route_ID
    JOIN route_time rt ON sr.Time_ID = rt.Time_ID
    WHERE sr.Student_ID = ?
    ORDER BY sr.Booking_Time DESC
    LIMIT 5
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$activities = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Notifications</title>
<style>
body {
    font-family:'Segoe UI', Tahoma, sans-serif;
    background:#f5f5f5;
    margin:0;
}

/* ===== CONTENT ===== */
.container {
    max-width:900px;
    margin:30px auto;
    padding:0 20px;
}

.title {
    font-size:26px;
    margin-bottom:20px;
    color:#333;
}

.card {
    background:white;
    padding:20px;
    border-radius:10px;
    box-shadow:0 5px 15px rgba(0,0,0,0.05);
    margin-bottom:15px;
}

.notif-title {
    font-weight:600;
    color:#2196F3;
    margin-bottom:5px;
}

.notif-time {
    font-size:13px;
    color:#777;
    margin-top:5px;
}

.badge {
    display:inline-block;
    padding:3px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    margin-bottom:10px;
}

.success { background:#4CAF50; color:white; }
.danger { background:#F44336; color:white; }
.info { background:#2196F3; color:white; }
.warning { background:#FF9800; color:white; }
</style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">
    <div class="title">Your Notifications</div>

    <!-- Booking Notifications -->
    <?php if ($activities->num_rows > 0): ?>
        <?php while ($row = $activities->fetch_assoc()): ?>
            <div class="card">
                <?php if ($row['Status'] === 'Reserved'): ?>
                    <span class="badge success">Booking Confirmed</span>
                    <div class="notif-title">✅ Shuttle Booking Confirmed</div>
                    <p>
                        Your seat for <strong><?= htmlspecialchars($row['Route_Name']); ?></strong>
                        at <?= date('H:i', strtotime($row['Departure_Time'])); ?>
                        has been successfully booked.
                    </p>
                <?php else: ?>
                    <span class="badge danger">Booking Cancelled</span>
                    <div class="notif-title">❌ Booking Cancelled</div>
                    <p>
                        Your booking for <strong><?= htmlspecialchars($row['Route_Name']); ?></strong>
                        at <?= date('H:i', strtotime($row['Departure_Time'])); ?>
                        has been cancelled.
                    </p>
                <?php endif; ?>

                <div class="notif-time">
                    <?= date('M d, H:i', strtotime($row['Booking_Time'])); ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="card">
            <span class="badge info">Info</span>
            <div class="notif-title">No Notifications</div>
            <p>You have no recent booking activity.</p>
        </div>
    <?php endif; ?>

    <!-- Demo / Prototype announcements -->
    <div class="card">
        <span class="badge warning">Delay Alert</span>
        <div class="notif-title">🚧 Shuttle Delay</div>
        <p>Route A (Main Gate → Library) is delayed by approximately 10 minutes due to traffic.</p>
        <div class="notif-time">Today</div>
    </div>

    <div class="card">
        <span class="badge info">Arrival Alert</span>
        <div class="notif-title">🚌 Shuttle Arriving Soon</div>
        <p>Your shuttle will arrive at the next stop in approximately 5 minutes.</p>
        <div class="notif-time">Today</div>
    </div>

</div>

</body>
</html>
