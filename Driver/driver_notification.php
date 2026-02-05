<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$result = $conn->query("
SELECT Status, Message, Update_time
FROM shuttle_status_update
ORDER BY Update_time DESC
");
?>

<h3>Notifications</h3>

<ul>
<?php while ($row = $result->fetch_assoc()): ?>
<li>
    <b><?= $row['Status'] ?></b> —
    <?= $row['Message'] ?> 
    (<?= $row['Update_time'] ?>)
</li>
<?php endwhile; ?>
</ul>

<a href="driver_dashboard.php">Back</a>
