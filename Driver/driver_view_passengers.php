<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['driver_id'])) {
    header("Location: ../driver_login.php");
    exit();
}

$schedule_id = $_GET['id'];

$sql = "
SELECT sr.Reservation_ID, sp.Student_Number, sr.Seat_number, sr.Status
FROM seat_reservation sr
JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
WHERE sr.Schedule_ID = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$result = $stmt->get_result();

if (isset($_GET['verify'])) {
    $rid = $_GET['verify'];
    $conn->query("UPDATE seat_reservation SET Status='Used' WHERE Reservation_ID=$rid");
    header("Location: view_passengers.php?id=$schedule_id");
    exit();
}
?>

<h3>Passenger List</h3>

<table border="1">
<tr>
    <th>Student ID</th>
    <th>Seat</th>
    <th>Status</th>
    <th>Action</th>
</tr>

<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= $row['Student_Number'] ?></td>
    <td><?= $row['Seat_number'] ?></td>
    <td><?= $row['Status'] ?></td>
    <td>
        <?php if ($row['Status'] !== 'Used'): ?>
        <a href="?id=<?= $schedule_id ?>&verify=<?= $row['Reservation_ID'] ?>">
            Verify Boarding
        </a>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</table>

<a href="driver_dashboard.php">Back</a>
