<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    return;
}
?>

<style>
/* ===== STUDENT NAVBAR (BLACK STYLE) ===== */
.navbar {
    background: #111;
    color: white;
    padding: 0 20px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.navbar-title {
    font-size: 22px;
    font-weight: bold;
    color: #fff;
}

.nav-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.nav-actions a {
    color: white;
    text-decoration: none;
    font-weight: 500;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.nav-actions a:hover {
    text-decoration: underline;
}

/* Icon-only links */
.nav-icon {
    font-size: 18px;
}

.nav-icon:hover {
    transform: scale(1.1);
}
</style>

<div class="navbar">
    <div class="navbar-title">
        🚌 Campus Shuttle
    </div>

    <div class="nav-actions">
        <a href="dashboard.php">Dashboard</a>
        <a href="book_shuttle.php">🚌 Book Shuttle</a>
        <a href="my_bookings.php">📋 My Bookings</a>

        <!-- 🔔 Notifications -->
        <a href="notifications.php" class="nav-icon" title="Notifications">
            🔔
        </a>

        <!-- 🚨 Report Incident -->
        <a href="report_incident.php" class="nav-icon" title="Report Incident">
            🚨
        </a>

        <a href="../logout.php">Logout</a>
    </div>
</div>
