<?php
session_start();
require_once '../includes/config.php';

/* -----------------------------------------
   Access control (Driver only)
----------------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Driver') {
    header('Location: ../driver_login.php');
    exit();
}

$driver_id = $_SESSION['user_id'];

/* -----------------------------------------
   Get passengers for driver's routes
----------------------------------------- */
$sql = "
SELECT 
    ss.Schedule_ID,
    ss.Departure_time,
    r.Route_Name,
    sp.Student_Number,
    sr.Seat_number
FROM shuttle_schedule ss
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN seat_reservation sr ON ss.Schedule_ID = sr.Schedule_ID
JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
WHERE ss.Driver_ID = ?
AND sr.Status = 'Reserved'
ORDER BY ss.Departure_time ASC, sr.Seat_number ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();

/* -----------------------------------------
   Group by schedule
----------------------------------------- */
$routes = [];
while ($row = $result->fetch_assoc()) {
    $routes[$row['Schedule_ID']]['info'] = [
        'Route_Name' => $row['Route_Name'],
        'Departure_time' => $row['Departure_time']
    ];
    $routes[$row['Schedule_ID']]['passengers'][] = [
        'Student_Number' => $row['Student_Number'],
        'Seat_number' => $row['Seat_number']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>View Passengers</title>
<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Tahoma;
}
body{ background:#f2f2f2; }

.container{
    max-width:1100px;
    margin:40px auto;
    padding:0 20px;
}

h1{ margin-bottom:10px; }
p{ color:#666; margin-bottom:25px; }

.card{
    background:white;
    padding:25px;
    border-radius:8px;
    border:1px solid #ddd;
    margin-bottom:25px;
}

.badge{
    background:#4CAF50;
    color:white;
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
}

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

.back-btn{
    background:#333;
    color:white;
    border:none;
    padding:10px 18px;
    border-radius:6px;
    cursor:pointer;
}
.back-btn:hover{ background:#000; }
</style>
</head>
<body>

<div class="container">

<h1>🧑‍🎓 Passenger List</h1>
<p>Students assigned to your shuttle routes</p>

<?php if ($routes): ?>
<?php foreach ($routes as $schedule_id => $data): ?>
<div class="card">
    <h3><?= htmlspecialchars($data['info']['Route_Name']); ?></h3>
    <p>
        🕒 <?= date('M d, H:i', strtotime($data['info']['Departure_time'])); ?> |
        👥 <?= count($data['passengers']); ?> Students
        <span class="badge">Schedule #<?= $schedule_id; ?></span>
    </p>

    <table>
        <tr>
            <th>No</th>
            <th>Student Number</th>
            <th>Seat</th>
        </tr>
        <?php $i=1; foreach ($data['passengers'] as $p): ?>
        <tr>
            <td><?= $i++; ?></td>
            <td><?= $p['Student_Number']; ?></td>
            <td><?= $p['Seat_number']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endforeach; ?>
<?php else: ?>
<p>No passengers found for your routes.</p>
<?php endif; ?>

<button class="back-btn" onclick="location.href='driver_dashboard.php'">⬅ Back to Dashboard</button>

</div>

</body>
</html>
