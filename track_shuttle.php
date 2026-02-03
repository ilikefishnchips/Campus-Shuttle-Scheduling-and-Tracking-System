<?php
session_start();
require_once 'includes/config.php';

$stmt = $conn->prepare("
    SELECT 
        ss.Schedule_ID,
        r.Route_Name,
        ss.Departure_time,
        ss.Expected_Arrival
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE 
        ss.Status = 'In Progress'
        OR NOW() BETWEEN ss.Departure_time AND ss.Expected_Arrival
    ORDER BY ss.Departure_time ASC
");


$stmt->execute();
$live_shuttles = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<title>Live Shuttle Tracking</title>
<style>
body{ font-family:'Segoe UI'; background:#f5f5f5; }
.container{ max-width:700px; margin:40px auto; }
.card{
    background:white;
    padding:20px;
    border-radius:10px;
    margin-bottom:15px;
    border:1px solid #ddd;
}
.btn{
    display:inline-block;
    margin-top:10px;
    background:#2196F3;
    color:white;
    padding:8px 14px;
    border-radius:6px;
    text-decoration:none;
}
</style>
</head>
<body>

<div class="container">
<h2>🚌 Live Shuttles Right Now</h2>

<?php if ($live_shuttles->num_rows > 0): ?>
    <?php while ($s = $live_shuttles->fetch_assoc()): ?>
        <div class="card">
            <strong><?= htmlspecialchars($s['Route_Name']); ?></strong><br>
            🕒 <?= date('H:i', strtotime($s['Departure_time'])); ?>
            → <?= date('H:i', strtotime($s['Expected_Arrival'])); ?><br>

            <a class="btn" href="track_route.php?schedule_id=<?= $s['Schedule_ID']; ?>">
                Track This Shuttle
            </a>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <p>No shuttles are currently running.</p>
<?php endif; ?>

</div>
</body>
</html>
