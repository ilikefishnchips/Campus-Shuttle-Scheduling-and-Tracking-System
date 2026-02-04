<?php
session_start();
require_once '../includes/config.php';

/* ===============================
   ACCESS CONTROL
================================ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Driver') {
    header('Location: ../driver_login.php');
    exit();
}

$driver_id = (int) $_SESSION['user_id'];

/* ===============================
   GET DRIVER ASSIGNED SCHEDULES
   (Scheduled + In Progress only)
================================ */
$stmt = $conn->prepare("
    SELECT
        ss.Schedule_ID,
        ss.Departure_time,
        ss.Expected_Arrival,
        ss.Status AS schedule_status,
        r.Route_Name,
        v.Vehicle_ID
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    WHERE ss.Driver_ID = ?
      AND ss.Status IN ('Scheduled', 'In Progress')
    ORDER BY ss.Departure_time DESC
");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$schedules = $stmt->get_result();

/* ===============================
   HANDLE INCIDENT SUBMISSION
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $schedule_id   = (int) $_POST['schedule_id'];
    $vehicle_id    = (int) $_POST['vehicle_id'];
    $incident_type = trim($_POST['incident_type']);
    $description   = trim($_POST['description']);
    $priority      = trim($_POST['priority']);

    if (!$schedule_id || !$vehicle_id || !$incident_type || !$description) {
        die("Invalid incident submission.");
    }

    // 🔒 Extra safety: ensure schedule belongs to this driver
    $stmt = $conn->prepare("
        SELECT 1
        FROM shuttle_schedule
        WHERE Schedule_ID = ?
          AND Driver_ID = ?
    ");
    $stmt->bind_param("ii", $schedule_id, $driver_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows === 0) {
        die("Unauthorized incident report.");
    }

    // ✅ Insert incident
    $stmt = $conn->prepare("
        INSERT INTO incident_reports
            (Reporter_ID, Schedule_ID, Vehicle_ID, Incident_Type, Description, Priority, Reported_At)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        "iiisss",
        $driver_id,
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
    font-family:'Segoe UI', sans-serif;
    background:#f4f4f4;
    margin:0;
}
.container {
    max-width:650px;
    margin:40px auto;
    padding:0 20px;
}
.card {
    background:white;
    padding:30px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,0.05);
}
h2 {
    margin-bottom:20px;
}
label {
    font-weight:600;
    margin-top:15px;
    display:block;
}
select, textarea, button {
    width:100%;
    padding:12px;
    margin-top:8px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:14px;
}
button {
    background:#F44336;
    color:white;
    border:none;
    font-size:16px;
    margin-top:20px;
    cursor:pointer;
}
button:hover {
    background:#D32F2F;
}
.success {
    background:#E8F5E9;
    color:#2E7D32;
    padding:15px;
    border-radius:8px;
    margin-bottom:20px;
}
.no-data {
    text-align:center;
    color:#777;
    font-style:italic;
    padding:30px;
}
.trip-status {
    font-size:12px;
    margin-left:6px;
    padding:2px 8px;
    border-radius:12px;
}
.status-scheduled { background:#4CAF50; color:white; }
.status-progress { background:#FF9800; color:white; }
</style>
</head>
<body>

<?php include 'driver_navbar.php'; ?>

<div class="container">

<h2>🚨 Report Shuttle Incident</h2>

<?php if (isset($_GET['success'])): ?>
    <div class="success">✅ Incident reported successfully.</div>
<?php endif; ?>

<?php if ($schedules->num_rows > 0): ?>
<div class="card">
<form method="POST">

    <label>Assigned Shuttle Route</label>
    <select name="schedule_id" required onchange="setVehicle(this)">
        <option value="">-- Select Assigned Shuttle --</option>
        <?php while ($s = $schedules->fetch_assoc()): ?>
            <option
                value="<?= $s['Schedule_ID']; ?>"
                data-vehicle="<?= $s['Vehicle_ID']; ?>">
                <?= htmlspecialchars($s['Route_Name']); ?>
                (<?= date('M d, H:i', strtotime($s['Departure_time'])); ?>)
                <?= $s['schedule_status'] === 'In Progress'
                    ? '[In Progress]'
                    : '[Scheduled]' ?>
            </option>
        <?php endwhile; ?>
    </select>

    <input type="hidden" name="vehicle_id" id="vehicle_id">

    <label>Incident Type</label>
    <select name="incident_type" required>
        <option value="Breakdown">Breakdown</option>
        <option value="Accident">Accident</option>
        <option value="Delay">Delay</option>
        <option value="Traffic">Traffic</option>
        <option value="Other">Other</option>
    </select>

    <label>Description</label>
    <textarea name="description" rows="5" required
        placeholder="Describe what happened on this route..."></textarea>

    <label>Priority</label>
    <select name="priority">
        <option value="Low">Low</option>
        <option value="Medium" selected>Medium</option>
        <option value="High">High</option>
        <option value="Critical">Critical</option>
    </select>

    <button type="submit">Submit Incident Report</button>

</form>
</div>
<?php else: ?>
    <div class="no-data">
        No assigned shuttle routes available for incident reporting.
    </div>
<?php endif; ?>

</div>

<script>
function setVehicle(select) {
    const vehicleId =
        select.options[select.selectedIndex].dataset.vehicle || '';
    document.getElementById('vehicle_id').value = vehicleId;
}
</script>

</body>
</html>
