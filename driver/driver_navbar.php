<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Driver') {
    return;
}

require_once '../includes/config.php';

/* -----------------------------------------
   Unread notification count
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS unread_count
    FROM notifications
    WHERE User_ID = ?
    AND Status = 'Unread'
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$unread = $stmt->get_result()->fetch_assoc();
$unread_count = (int)$unread['unread_count'];
?>

<style>
/* ===== DRIVER NAVBAR ===== */
.driver-navbar {
    background:#111;
    color:white;
    height:70px;
    padding:0 25px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.driver-title {
    font-size:22px;
    font-weight:bold;
}

.driver-actions {
    display:flex;
    align-items:center;
    gap:18px;
}

.driver-actions a {
    color:white;
    text-decoration:none;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:5px;
    position:relative;
}

.driver-actions a:hover {
    text-decoration:underline;
}

.notif-dot {
    position:absolute;
    top:-3px;
    right:-3px;
    width:8px;
    height:8px;
    background:#F44336;
    border-radius:50%;
}
</style>

<div class="driver-navbar">
    <div class="driver-title">
        🚌 Campus Shuttle (Driver)
    </div>

    <div class="driver-actions">
        <a href="driver_dashboard.php">Dashboard</a>
        <a href="driver_routes.php">Routes</a>
        <a href="view_passengers.php">Passengers</a>

        <!-- 🔔 Notifications -->
        <a href="driver_notification.php" title="Notifications">
            🔔
            <?php if ($unread_count > 0): ?>
                <span class="notif-dot"></span>
            <?php endif; ?>
        </a>

        <a href="../logout.php">Logout</a>
    </div>
</div>
