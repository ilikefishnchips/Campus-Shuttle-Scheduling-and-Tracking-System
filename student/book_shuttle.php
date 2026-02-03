<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* ------------------------------------
   Load all routes
------------------------------------ */
$routes = $conn->query("
    SELECT Route_ID, Route_Name
    FROM route
    WHERE Status = 'Active'
");

/* ------------------------------------
   Selected route (GET)
------------------------------------ */
$route_id = $_GET['route_id'] ?? null;
$stops = [];

if ($route_id) {
    $stmt = $conn->prepare("
        SELECT Stop_ID, Stop_Name, Stop_Order
        FROM route_stops
        WHERE Route_ID = ?
        ORDER BY Stop_Order
    ");
    $stmt->bind_param("i", $route_id);
    $stmt->execute();
    $stops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Book Shuttle</title>
<style>
body {
    font-family:'Segoe UI', sans-serif;
    background:#f4f4f4;
    margin:0;
}

/* ===== CONTENT ===== */
.container {
    max-width:600px;
    margin:40px auto;
    background:white;
    padding:30px;
    border-radius:10px;
}

label {
    display:block;
    margin-top:15px;
    font-weight:600;
}

select, input[type="date"], button {
    width:100%;
    padding:12px;
    margin-top:8px;
    border-radius:8px;
    border:1px solid #ccc;
}

.date-bar {
    background:#fafafa;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
    margin-top:15px;
}

button {
    background:#F44336;
    color:white;
    border:none;
    font-size:16px;
    cursor:pointer;
    margin-top:20px;
}

button:hover {
    background:#D32F2F;
}
</style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">
<h2>Shuttle Service</h2>

<!-- Route selection -->
<form method="GET">
    <label>Select Route</label>
    <select name="route_id" onchange="this.form.submit()" required>
        <option value="">-- Choose Route --</option>
        <?php while ($r = $routes->fetch_assoc()): ?>
            <option value="<?= $r['Route_ID']; ?>"
                <?= ($route_id == $r['Route_ID']) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($r['Route_Name']); ?>
            </option>
        <?php endwhile; ?>
    </select>
</form>

<?php if ($route_id): ?>
<!-- Booking details -->
<form method="POST" action="select_time.php">
    <input type="hidden" name="route_id" value="<?= $route_id ?>">

    <!-- DATE BAR (NEW, CLEAN) -->
    <div class="date-bar">
        <label>Travel Date</label>
        <input 
            type="date" 
            name="travel_date" 
            required 
            min="<?= date('Y-m-d'); ?>"
            value="<?= date('Y-m-d'); ?>"
        >
    </div>

    <label>Pick-up Point</label>
    <select name="pickup_stop_id" id="pickup_stop" required onchange="filterDropoff()">
        <option value="">-- Select Pick-up Stop --</option>
        <?php foreach ($stops as $s): ?>
            <option value="<?= $s['Stop_ID']; ?>" data-order="<?= $s['Stop_Order']; ?>">
                <?= htmlspecialchars($s['Stop_Name']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Drop-off Point</label>
    <select name="dropoff_stop_id" id="dropoff_stop" required>
        <option value="">-- Select Drop-off Stop --</option>
        <?php foreach ($stops as $s): ?>
            <option value="<?= $s['Stop_ID']; ?>" data-order="<?= $s['Stop_Order']; ?>">
                <?= htmlspecialchars($s['Stop_Name']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit">Next</button>
</form>
<?php endif; ?>

</div>

<script>
function filterDropoff() {
    const pickup = document.getElementById('pickup_stop');
    const dropoff = document.getElementById('dropoff_stop');

    const pickupOrder = pickup.options[pickup.selectedIndex]?.dataset.order;

    for (let option of dropoff.options) {
        if (!option.dataset.order) continue;
        option.disabled = parseInt(option.dataset.order) <= parseInt(pickupOrder);
    }
    dropoff.value = '';
}
</script>

</body>
</html>
