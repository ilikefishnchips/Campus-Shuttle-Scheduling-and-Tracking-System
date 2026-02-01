<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get route ID from request
$route_id = isset($_GET['route_id']) ? intval($_GET['route_id']) : 0;

if ($route_id <= 0) {
    echo json_encode(['error' => 'Invalid route ID']);
    exit();
}

// Fetch schedules for this route
$schedules_sql = "
    SELECT 
        ss.*,
        r.Route_Name,
        r.Start_Location,
        r.End_Location,
        v.Plate_number,
        v.Model,
        u.Full_Name as Driver_Name
    FROM shuttle_schedule ss
    LEFT JOIN route r ON ss.Route_ID = r.Route_ID
    LEFT JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    LEFT JOIN user u ON ss.Driver_ID = u.User_ID
    WHERE ss.Route_ID = ?
    ORDER BY ss.Departure_time DESC
";
$schedules_stmt = $conn->prepare($schedules_sql);
$schedules_stmt->bind_param("i", $route_id);
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);

// Return data as JSON
echo json_encode([
    'schedules' => $schedules
]);
?>