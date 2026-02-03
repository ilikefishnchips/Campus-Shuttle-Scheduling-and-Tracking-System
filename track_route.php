<?php
session_start();
require_once 'includes/config.php';

if (!isset($_GET['schedule_id'])) {
    die("Schedule not found.");
}

$schedule_id = (int)$_GET['schedule_id'];

/* -----------------------------------------
   Get ONLY live schedule + route
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        ss.Departure_time,
        ss.Expected_Arrival,
        ss.Status,
        r.Route_ID,
        r.Route_Name
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE ss.Schedule_ID = ?
    AND ss.Status = 'In Progress'
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();

if (!$route) {
    die("This shuttle is not currently running.");
}

/* -----------------------------------------
   Get route stops
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Stop_Name, Estimated_Time_From_Start, Latitude, Longitude
    FROM route_stops
    WHERE Route_ID = ?
    ORDER BY Stop_Order
");
$stmt->bind_param("i", $route['Route_ID']);
$stmt->execute();
$stops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (count($stops) === 0) {
    die("No stops defined for this route.");
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Live Shuttle Tracking</title>

<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>

<style>
body{
    margin:0;
    font-family:'Segoe UI', Tahoma;
}
.header{
    padding:15px 25px;
    background:#fff;
    border-bottom:1px solid #ddd;
}
#map{
    height:88vh;
    width:100%;
}
.status{
    margin-top:5px;
    font-size:14px;
    color:#555;
}
</style>
</head>
<body>

<div class="header">
    <h2>🚌 Live Shuttle Tracking</h2>
    <strong>Route:</strong> <?= htmlspecialchars($route['Route_Name']); ?><br>
    <div id="currentStop">Preparing tracking…</div>
    <div class="status" id="nextStop"></div>
    <div class="status" id="tripStatus"></div>
</div>

<div id="map"></div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<script>
const stops = <?= json_encode($stops); ?>;
const departureTime = new Date("<?= $route['Departure_time']; ?>").getTime();
const arrivalTime = new Date("<?= $route['Expected_Arrival']; ?>").getTime();

// Map
const map = L.map('map').setView(
    [stops[0].Latitude, stops[0].Longitude],
    15
);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

// Stop markers
stops.forEach(stop => {
    L.marker([stop.Latitude, stop.Longitude])
     .addTo(map)
     .bindPopup(stop.Stop_Name);
});

// Bus icon
const busIcon = L.icon({
    iconUrl: 'https://cdn-icons-png.flaticon.com/512/3448/3448339.png',
    iconSize: [36, 36],
    iconAnchor: [18, 18]
});

let busMarker = L.marker(
    [stops[0].Latitude, stops[0].Longitude],
    { icon: busIcon }
).addTo(map);

function updateBus(){
    const now = new Date().getTime();

    // Not started
    if (now < departureTime) {
        document.getElementById('currentStop').innerText =
            "⏳ Shuttle has not started yet";
        document.getElementById('tripStatus').innerText =
            "Scheduled departure pending.";
        document.getElementById('nextStop').innerText = "";
        return;
    }

    // Completed
    if (now > arrivalTime) {
        const last = stops[stops.length - 1];
        busMarker.setLatLng([last.Latitude, last.Longitude]);

        document.getElementById('currentStop').innerText =
            "🏁 Trip completed";
        document.getElementById('tripStatus').innerText =
            "This shuttle has arrived at its final stop.";
        document.getElementById('nextStop').innerText = "";
        return;
    }

    // In progress
    const minutesPassed = Math.floor((now - departureTime) / 60000);

    let currentStop = stops[0];
    let nextStop = null;

    for (let i = 0; i < stops.length; i++) {
        if (stops[i].Estimated_Time_From_Start <= minutesPassed) {
            currentStop = stops[i];
            nextStop = stops[i + 1] ?? null;
        }
    }

    busMarker.setLatLng([currentStop.Latitude, currentStop.Longitude]);

    document.getElementById('currentStop').innerText =
        "📍 Current Location: " + currentStop.Stop_Name;

    if (nextStop) {
        const minutesLeft =
            nextStop.Estimated_Time_From_Start - minutesPassed;

        document.getElementById('nextStop').innerText =
            "➡️ Next Stop: " + nextStop.Stop_Name +
            " (Arriving in " + minutesLeft + " min)";
    } else {
        document.getElementById('nextStop').innerText =
            "🛑 Final stop approaching";
    }

    document.getElementById('tripStatus').innerText =
        "🕒 Minutes since departure: " + minutesPassed;
}

updateBus();
setInterval(updateBus, 10000);
</script>

</body>
</html>
