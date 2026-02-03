<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

/* -----------------------------------------
   Get student's booked shuttles
----------------------------------------- */
$sql = "
SELECT 
    ss.Schedule_ID,
    v.Vehicle_ID,
    r.Route_Name,
    ss.Departure_time
FROM seat_reservation sr
JOIN shuttle_schedule ss ON sr.Schedule_ID = ss.Schedule_ID
JOIN route r ON ss.Route_ID = r.Route_ID
JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
WHERE sp.User_ID = ?
AND sr.Status = 'Reserved'
ORDER BY ss.Departure_time DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$bookings = $stmt->get_result();

/* -----------------------------------------
   Handle form submission
----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $schedule_id   = (int) $_POST['schedule_id'];
    $vehicle_id    = (int) $_POST['vehicle_id'];
    $incident_type = $_POST['incident_type'];
    $description   = $_POST['description'];
    $priority      = $_POST['priority'];

    $sql = "
    INSERT INTO incident_reports
    (Reporter_ID, Schedule_ID, Vehicle_ID, Incident_Type, Description, Priority)
    VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iiisss",
        $user_id,
        $schedule_id,
        $vehicle_id,
        $incident_type,
        $description,
        $priority
    );
    $stmt->execute();

    header("Location: report_incident.php?success=1");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Report Incident</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma;
            background:#f5f5f5;
            margin:0;
        }
        .navbar {
            background:#2196F3;
            color:white;
            height:70px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 20px;
        }
        .container {
            max-width:650px;
            margin:30px auto;
            padding:0 20px;
        }
        .card {
            background:white;
            padding:25px;
            border-radius:10px;
            box-shadow:0 5px 15px rgba(0,0,0,0.05);
        }
        select, textarea, button {
            width:100%;
            padding:12px;
            margin-top:10px;
            border-radius:6px;
            border:1px solid #ccc;
            font-size:14px;
        }
        button {
            background:#2196F3;
            color:white;
            border:none;
            margin-top:20px;
            font-size:16px;
            cursor:pointer;
        }
        .success {
            background:#E8F5E9;
            color:#2E7D32;
            padding:15px;
            border-radius:6px;
            margin-bottom:15px;
        }
    </style>
</head>
<body>

<div class="navbar">
    <div>🚨 Report Incident</div>
    <div>
        <a href="dashboard.php" style="color:white;margin-right:20px;">Dashboard</a>
        <a href="../logout.php" style="color:white;">Logout</a>
    </div>
</div>

<div class="container">

<?php if (isset($_GET['success'])): ?>
    <div class="success">✅ Incident reported successfully.</div>
<?php endif; ?>

<div class="card">
<form method="POST">

    <label>Related Shuttle Trip</label>
    <select name="schedule_id" required onchange="updateVehicle(this)">
        <option value="">-- Select Shuttle --</option>
        <?php while ($row = $bookings->fetch_assoc()): ?>
            <option 
                value="<?= $row['Schedule_ID']; ?>"
                data-vehicle="<?= $row['Vehicle_ID']; ?>">
                <?= htmlspecialchars($row['Route_Name']); ?>
                (<?= date('M d, H:i', strtotime($row['Departure_time'])); ?>)
            </option>
        <?php endwhile; ?>
    </select>

    <input type="hidden" name="vehicle_id" id="vehicle_id">

    <label>Incident Type</label>
    <select name="incident_type" required>
        <option value="Accident">Accident</option>
        <option value="Breakdown">Breakdown</option>
        <option value="Delay">Delay</option>
        <option value="Behavior">Behavior</option>
        <option value="Other">Other</option>
    </select>

    <label>Description</label>
    <textarea name="description" rows="5" required></textarea>

    <label>Priority</label>
    <select name="priority">
        <option value="Low">Low</option>
        <option value="Medium" selected>Medium</option>
        <option value="High">High</option>
        <option value="Critical">Critical</option>
    </select>

    <button type="submit">Submit Report</button>
</form>
</div>
</div>

<script>
function updateVehicle(select) {
    const vehicleId = select.options[select.selectedIndex].dataset.vehicle;
    document.getElementById('vehicle_id').value = vehicleId;
}
</script>

</body>
</html>
