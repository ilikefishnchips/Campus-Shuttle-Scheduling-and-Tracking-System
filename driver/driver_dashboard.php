<?php
session_start();
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
   Unread notification count (FOR RED DOT)
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS unread_count
    FROM notifications
    WHERE User_ID = ?
    AND Status = 'Unread'
");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$unread = $stmt->get_result()->fetch_assoc();
$unread_count = (int)$unread['unread_count'];

/* -----------------------------------------
   Driver profile
----------------------------------------- */
$sql = "
SELECT u.Full_Name, u.Username, dp.*
FROM user u
JOIN driver_profile dp ON u.User_ID = dp.User_ID
WHERE u.User_ID = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();

/* -----------------------------------------
   Assigned routes (today & upcoming)
----------------------------------------- */
$sql_routes = "
SELECT ss.*, r.Route_Name, r.Start_Location, r.End_Location,
       v.Plate_number
FROM shuttle_schedule ss
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
WHERE ss.Driver_ID = ?
AND ss.Departure_time >= NOW()
ORDER BY ss.Departure_time ASC
LIMIT 5
";
$stmt = $conn->prepare($sql_routes);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$assigned_routes = $stmt->get_result();

/* -----------------------------------------
   Incident notifications (reported by driver)
----------------------------------------- */
$sql_incidents = "
SELECT Incident_Type, Status, Priority, Reported_At
FROM incident_reports
WHERE Reporter_ID = ?
ORDER BY Reported_At DESC
LIMIT 5
";
$stmt = $conn->prepare($sql_incidents);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$incidents = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Driver Dashboard</title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Tahoma;
}
body{ background:#f2f2f2; }

/* Navbar */
.navbar{
    background:#fff;
    height:80px;
    padding:0 40px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid #ddd;
}
.nav-logo{ height:45px; }
.nav-right{ display:flex; align-items:center; gap:15px; }
.nav-btn{
    background:#333;
    color:white;
    border:none;
    padding:8px 16px;
    border-radius:6px;
    cursor:pointer;
}
.nav-btn:hover{ background:#000; }

/* Layout */
.dashboard-container{
    max-width:1200px;
    margin:40px auto;
    padding:0 20px;
}
.page-header h1{ font-size:36px; }
.page-header p{ color:#555; }

/* Cards */
.section-card{
    background:white;
    padding:25px;
    border-radius:8px;
    border:1px solid #ddd;
    margin-bottom:25px;
}

/* Grid */
.dashboard-grid{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:25px;
}

/* Info */
.info-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:20px;
}
.info-label{ font-size:12px; color:#777; }
.info-value{ font-size:16px; font-weight:600; }

/* Items */
.item{
    border:1px solid #ddd;
    border-radius:6px;
    padding:15px;
    margin-bottom:12px;
}

/* Status badge */
.badge{
    background:#4CAF50;
    color:white;
    padding:3px 10px;
    border-radius:20px;
    font-size:12px;
}

/* Buttons */
.quick-actions{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:15px;
}
.action-btn{
    background:#222;
    color:white;
    border:none;
    padding:14px;
    border-radius:6px;
    cursor:pointer;
    position:relative;
}
.action-btn:hover{ background:#000; }

/* 🔴 Notification dot */
.notif-dot{
    position:absolute;
    top:8px;
    right:12px;
    width:8px;
    height:8px;
    background:#F44336;
    border-radius:50%;
}

@media(max-width:900px){
    .dashboard-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <img src="../assets/mmuShuttleLogo2.png" class="nav-logo">
    <div class="nav-right">
        <span><?= $_SESSION['username']; ?> (Driver)</span>
        <button class="nav-btn" onclick="logout()">Logout</button>
    </div>
</nav>

<div class="dashboard-container">

<div class="page-header">
    <h1>Driver Dashboard</h1>
    <p>Your assigned routes & shuttle operations</p>
</div>

<!-- Driver Info -->
<div class="section-card">
<h2>👋 Welcome, <?= htmlspecialchars($driver['Full_Name']); ?></h2>
<div class="info-grid">
    <div><span class="info-label">License No</span><div class="info-value"><?= $driver['License_Number']; ?></div></div>
    <div><span class="info-label">License Expiry</span><div class="info-value"><?= $driver['License_Expiry']; ?></div></div>
    <div><span class="info-label">Phone</span><div class="info-value"><?= $driver['Phone']; ?></div></div>
    <div><span class="info-label">Vehicle</span><div class="info-value"><?= $driver['Assigned_Vehicle']; ?></div></div>
</div>
</div>

<div class="dashboard-grid">

<!-- LEFT -->
<div>
<div class="section-card">
<h3>🛣️ Assigned Routes</h3>

<?php if($assigned_routes->num_rows): ?>
<?php while($r = $assigned_routes->fetch_assoc()): ?>
<div class="item">
<strong><?= $r['Route_Name']; ?></strong>
<span class="badge"><?= $r['Status']; ?></span><br>
📍 <?= $r['Start_Location']; ?> → <?= $r['End_Location']; ?><br>
🕒 <?= date('M d, H:i', strtotime($r['Departure_time'])); ?><br>
🚌 <?= $r['Plate_number']; ?>
</div>
<?php endwhile; ?>
<?php else: ?>
<p>No assigned routes.</p>
<?php endif; ?>

</div>

<!-- Quick Actions -->
<div class="quick-actions">
<button class="action-btn" onclick="location.href='driver_routes.php'">🛣️ View All Routes</button>
<button class="action-btn" onclick="location.href='view_passengers.php'">🧑‍🎓 View Passengers</button>
<button class="action-btn" onclick="location.href='driver_reports.php'">🚨 Incident Reports</button>

<button class="action-btn" onclick="location.href='driver_notification.php'">
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
<h3>🔔 Recent Reports</h3>
<?php if($incidents->num_rows): ?>
<?php while($i=$incidents->fetch_assoc()): ?>
<div class="item">
<strong><?= $i['Incident_Type']; ?></strong><br>
Priority: <?= $i['Priority']; ?><br>
Status: <?= $i['Status']; ?><br>
🕒 <?= date('M d, H:i', strtotime($i['Reported_At'])); ?>
</div>
<?php endwhile; ?>
<?php else: ?>
<p>No recent reports</p>
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
