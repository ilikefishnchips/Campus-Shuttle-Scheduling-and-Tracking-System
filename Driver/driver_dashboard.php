<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$driver_id = $_SESSION['driver_id'];

$sql = "
SELECT ss.Schedule_ID, ss.Status, r.Route_Name, v.Plate_number
FROM shuttle_schedule ss
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
WHERE ss.Driver_ID = ?
ORDER BY ss.Departure_time DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<h2>Driver Dashboard</h2>

<table border="1" cellpadding="10">
<tr>
    <th>Route</th>
    <th>Vehicle</th>
    <th>Status</th>
    <th>Actions</th>
</tr>

<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= $row['Route_Name'] ?></td>
    <td><?= $row['Plate_number'] ?></td>
    <td><?= $row['Status'] ?></td>
    <td>
        <a href="start_route.php?id=<?= $row['Schedule_ID'] ?>">Start / End</a> |
        <a href="update_status.php?id=<?= $row['Schedule_ID'] ?>">Update Status</a> |
        <a href="view_passengers.php?id=<?= $row['Schedule_ID'] ?>">Passengers</a> |
        <a href="report_incident.php?id=<?= $row['Schedule_ID'] ?>">Incident</a>
    </td>
</tr>
<?php endwhile; ?>
</table>

<br>
<a href="driver_notification.php">View Notifications</a> |
<a href="../logout.php">Logout</a>
