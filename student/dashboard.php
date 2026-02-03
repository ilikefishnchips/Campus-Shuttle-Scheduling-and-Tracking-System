<?php
session_start();
require_once '../includes/config.php';

/* -----------------------------------------
   Access control
----------------------------------------- */
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* -----------------------------------------
   Get student info
----------------------------------------- */
$user_id = $_SESSION['user_id'];
$sql = "
SELECT u.*, sp.*
FROM user u
JOIN student_profile sp ON u.User_ID = sp.User_ID
WHERE u.User_ID = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

/* -----------------------------------------
   Upcoming bookings
----------------------------------------- */
$sql_bookings = "
SELECT sr.*, ss.Departure_time, r.Route_Name, v.Plate_number
FROM seat_reservation sr
JOIN shuttle_schedule ss ON sr.Schedule_ID = ss.Schedule_ID
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
WHERE sr.Student_ID = ?
AND sr.Status = 'Reserved'
AND ss.Departure_time > NOW()
ORDER BY ss.Departure_time ASC
LIMIT 5
";
$stmt = $conn->prepare($sql_bookings);
$stmt->bind_param("i", $student['Student_ID']);
$stmt->execute();
$upcoming_bookings = $stmt->get_result();

/* -----------------------------------------
   Active shuttles
----------------------------------------- */
$stmt = $conn->prepare("
SELECT 
    ss.*, 
    r.Route_Name, 
    v.Plate_number, 
    u.Full_Name AS Driver_Name
FROM shuttle_schedule ss
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
JOIN user u ON ss.Driver_ID = u.User_ID
WHERE 
    NOW() BETWEEN ss.Departure_time AND ss.Expected_Arrival
ORDER BY ss.Expected_Arrival ASC
LIMIT 3
");
$stmt->execute();
$active_shuttles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


/* -----------------------------------------
   Unread notification count
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS unread_count
    FROM notifications
    WHERE User_ID = ?
    AND Status = 'Unread'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread = $stmt->get_result()->fetch_assoc();
$unread_count = (int)$unread['unread_count'];

?>
<!DOCTYPE html>
<html>
<head>
<title>Student Dashboard</title>
<style>
* {
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Tahoma;
}
body { background:#f2f2f2; }

/* Navbar */
.navbar {
    background:#fff;
    height:80px;
    padding:0 40px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid #ddd;
}

.notif-btn {
    position: relative;
}

.notif-dot {
    position: absolute;
    top: -4px;
    right: -4px;
    width: 10px;
    height: 10px;
    background: #F44336;
    border-radius: 50%;
}

.nav-logo { height:45px; }
.nav-right { display:flex; align-items:center; gap:15px; }
.nav-btn {
    background:#333;
    color:white;
    border:none;
    padding:8px 16px;
    border-radius:6px;
    cursor:pointer;
}
.nav-btn:hover { background:#000; }

/* Layout */
.dashboard-container {
    max-width:1200px;
    margin:40px auto;
    padding:0 20px;
}
.page-header h1 { font-size:36px; }
.page-header p { color:#555; }

/* Cards */
.section-card {
    background:white;
    padding:25px;
    border-radius:8px;
    border:1px solid #ddd;
    margin-bottom:25px;
}

/* Grid */
.dashboard-grid {
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:25px;
}

/* Student Info */
.student-info {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:20px;
    margin-top:20px;
}
.info-label { font-size:12px; color:#777; }
.info-value { font-size:16px; font-weight:600; }

/* Booking */
.booking-item {
    border:1px solid #ddd;
    border-radius:6px;
    padding:15px;
    margin-bottom:12px;
}
.booking-route { font-weight:600; margin-bottom:6px; }

/* Status */
.status-badge {
    background:#4CAF50;
    color:white;
    padding:3px 10px;
    border-radius:20px;
    font-size:12px;
}

/* Buttons */
.quick-actions {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:15px;
}
.action-btn {
    background:#222;
    color:white;
    border:none;
    padding:14px;
    border-radius:6px;
    cursor:pointer;
    font-size:15px;
}
.action-btn:hover { background:#000; }

@media (max-width:900px){
    .dashboard-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <img src="../assets/mmuShuttleLogo2.png" class="nav-logo">
    <div class="nav-right">
        <span><?= $_SESSION['username']; ?> (Student)</span>
        <button class="nav-btn" onclick="logout()">Logout</button>
    </div>
</nav>

<div class="dashboard-container">

<div class="page-header">
    <h1>Student Dashboard</h1>
    <p>Manage your shuttle bookings & campus transport</p>
</div>

<!-- Student Info -->
<div class="section-card">
<h2>👋 Welcome, <?= htmlspecialchars($student['Full_Name']); ?></h2>
<div class="student-info">
    <div><span class="info-label">Student Number</span><div class="info-value"><?= $student['Student_Number']; ?></div></div>
    <div><span class="info-label">Faculty</span><div class="info-value"><?= $student['Faculty']; ?></div></div>
    <div><span class="info-label">Year</span><div class="info-value">Year <?= $student['Year_Of_Study']; ?></div></div>
    <div><span class="info-label">Emergency</span><div class="info-value"><?= $student['Emergency_contact']; ?></div></div>
</div>
</div>

<div class="dashboard-grid">

<!-- LEFT -->
<div>
<div class="section-card">
<h3>📅 Upcoming Bookings</h3>
<?php if($upcoming_bookings->num_rows): ?>
<?php while($b=$upcoming_bookings->fetch_assoc()): ?>
<div class="booking-item">
<div class="booking-route"><?= $b['Route_Name']; ?> <span class="status-badge">Reserved</span></div>
<div>
🕒 <?= date('M d, H:i', strtotime($b['Departure_time'])); ?><br>
🚍 <?= $b['Plate_number']; ?> | Seat #<?= $b['Seat_number']; ?>
</div>
</div>
<?php endwhile; ?>
<?php else: ?>
<p>No upcoming bookings</p>
<?php endif; ?>
</div>

<!-- QUICK ACTIONS -->
<div class="quick-actions">
<button class="action-btn" onclick="location.href='book_shuttle.php'">🚌 Book Shuttle</button>
<button class="action-btn" onclick="location.href='my_bookings.php'">📋 View Bookings</button>
<button class="action-btn" onclick="location.href='../track_shuttle.php'">🗺️ Track Shuttle</button>
<button class="action-btn" onclick="location.href='report_incident.php'">🚨 Report Incident</button>
<button class="action-btn notif-btn" onclick="location.href='notifications.php'">
    🔔 Notifications
    <?php if ($unread_count > 0): ?>
        <span class="notif-dot"></span>
    <?php endif; ?>
</button>

</div>
</div>

<!-- RIGHT -->
<div>
<div class="section-card">
<h3>🚍 Active Shuttles</h3>
<?php if($active_shuttles): ?>
<?php foreach($active_shuttles as $s): ?>
<div class="booking-item">
<strong><?= $s['Route_Name']; ?></strong><br>
👨‍✈️ <?= $s['Driver_Name']; ?><br>
🚌 <?= $s['Plate_number']; ?><br>
⏱ ETA <?= $s['Expected_Arrival'] ? date('H:i', strtotime($s['Expected_Arrival'])) : 'Pending'; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<p>No active shuttles now</p>
<?php endif; ?>
</div>
</div>

</div>
</div>

<script>
function logout(){
    if(confirm('Logout now?')){
        location.href='../logout.php';
    }
}
</script>
</body>
</html>
