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

// Fetch route details
$route_sql = "SELECT * FROM route WHERE Route_ID = ?";
$route_stmt = $conn->prepare($route_sql);
$route_stmt->bind_param("i", $route_id);
$route_stmt->execute();
$route_result = $route_stmt->get_result();
$route = $route_result->fetch_assoc();

if (!$route) {
    echo json_encode(['error' => 'Route not found']);
    exit();
}

// Fetch stops for this route
$stops_sql = "SELECT * FROM route_stops WHERE Route_ID = ? ORDER BY Stop_Order";
$stops_stmt = $conn->prepare($stops_sql);
$stops_stmt->bind_param("i", $route_id);
$stops_stmt->execute();
$stops_result = $stops_stmt->get_result();
$stops = $stops_result->fetch_all(MYSQLI_ASSOC);

// Return data as JSON
echo json_encode([
    'route' => $route,
    'stops' => $stops
]);
?>