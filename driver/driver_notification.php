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

$user_id = (int)$_SESSION['user_id'];

/* -----------------------------------------
   Mark notifications as READ
----------------------------------------- */
$stmt = $conn->prepare("
    UPDATE notifications
    SET Status = 'Read', Read_At = NOW()
    WHERE User_ID = ?
    AND Status = 'Unread'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();

/* -----------------------------------------
   ROUTE / SYSTEM notifications (Driver)
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        Title,
        Message,
        Type,
        Priority,
        Created_At
    FROM notifications
    WHERE User_ID = ?
    AND Status != 'Deleted'
    ORDER BY Created_At DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$route_notifications = $stmt->get_result();

/* -----------------------------------------
   INCIDENT notifications (ADMIN APPROVED)
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        Incident_Type,
        Description,
        Priority,
        Reported_At
    FROM incident_reports
    WHERE admin_status = 'Approved'
    ORDER BY Reported_At DESC
    LIMIT 5
");
$stmt->execute();
$incident_notifications = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Driver Notifications</title>

<style>
body {
    font-family:'Segoe UI', Tahoma, sans-serif;
    background:#f5f5f5;
    margin:0;
}
.container {
    max-width:900px;
    margin:30px auto;
    padding:0 20px;
}
.title {
    font-size:26px;
    margin-bottom:20px;
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
.section-divider {
    margin:30px 0 15px;
    border-top:2px solid #ddd;
}
.section-title {
    background:#f5f5f5;
    padding:0 12px;
    position:relative;
    top:-12px;
    font-size:14px;
    font-weight:600;
    color:#555;
}
.info { background:#2196F3; color:white; }
.warning { background:#FF9800; color:white; }
.danger { background:#F44336; color:white; }
.success { background:#4CAF50; color:white; }
.empty {
    color:#777;
    margin-bottom:15px;
}
</style>
</head>
<body>

<?php include 'driver_navbar.php'; ?>

<div class="container">
<div class="title">Driver Notifications</div>

<!-- ================= ROUTE / ASSIGNMENT UPDATES ================= -->
<div class="section-divider">
    <span class="section-title">Route Updates & Assignments</span>
</div>

<?php if ($route_notifications->num_rows > 0): ?>
    <?php while ($n = $route_notifications->fetch_assoc()): ?>

        <?php
            $badge = 'info';
            if ($n['Priority'] === 'High' || $n['Priority'] === 'Urgent') {
                $badge = 'danger';
            } elseif ($n['Type'] === 'Route_Assigned' || $n['Type'] === 'Route_Update') {
                $badge = 'info'; // blue for route related
            }
        ?>

        <div class="card">
            <span class="badge <?= $badge; ?>">
                <?= htmlspecialchars($n['Type']); ?>
            </span>

            <div class="notif-title">
                <?= htmlspecialchars($n['Title']); ?>
            </div>

            <p><?= nl2br(htmlspecialchars($n['Message'])); ?></p>

            <div class="notif-time">
                <?= date('M d, H:i', strtotime($n['Created_At'])); ?>
            </div>
        </div>

    <?php endwhile; ?>
<?php else: ?>
    <p class="empty">No route updates or assignments.</p>
<?php endif; ?>

<!-- ================= INCIDENT ALERTS ================= -->
<div class="section-divider">
    <span class="section-title">Incident Alerts</span>
</div>

<?php if ($incident_notifications->num_rows > 0): ?>
    <?php while ($i = $incident_notifications->fetch_assoc()): ?>

        <?php
            $badge = ($i['Priority'] === 'High' || $i['Priority'] === 'Critical')
                ? 'danger' : 'warning';
        ?>

        <div class="card">
            <span class="badge <?= $badge; ?>">
                🚨 Incident Alert
            </span>

            <div class="notif-title">
                <?= htmlspecialchars($i['Incident_Type']); ?>
            </div>

            <p><?= nl2br(htmlspecialchars($i['Description'])); ?></p>

            <div class="notif-time">
                <?= date('M d, H:i', strtotime($i['Reported_At'])); ?>
                • Approved by Admin
            </div>
        </div>

    <?php endwhile; ?>
<?php else: ?>
    <p class="empty">No incident alerts.</p>
<?php endif; ?>

<!-- ================= EMPTY STATE ================= -->
<?php if (
    $route_notifications->num_rows == 0 &&
    $incident_notifications->num_rows == 0
): ?>
    <div class="card">
        <span class="badge info">Info</span>
        <div class="notif-title">No Notifications</div>
        <p>You currently have no notifications.</p>
    </div>
<?php endif; ?>

</div>
</body>
</html>
