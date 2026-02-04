<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Content-Type: application/json'); // 添加这行
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get route ID from request
$route_id = isset($_GET['route_id']) ? intval($_GET['route_id']) : 0;

if ($route_id <= 0) {
    header('Content-Type: application/json'); // 添加这行
    echo json_encode(['error' => 'Invalid route ID']);
    exit();
}

try {
    // Get route details
    $route_sql = "SELECT * FROM route WHERE Route_ID = ?";
    $route_stmt = $conn->prepare($route_sql);
    $route_stmt->bind_param("i", $route_id);
    $route_stmt->execute();
    $route_result = $route_stmt->get_result();
    $route = $route_result->fetch_assoc();
    
    if (!$route) {
        header('Content-Type: application/json'); // 添加这行
        echo json_encode(['error' => 'Route not found']);
        exit();
    }
    
    // Get route stops
    $stops_sql = "SELECT * FROM route_stops WHERE Route_ID = ? ORDER BY Stop_Order";
    $stops_stmt = $conn->prepare($stops_sql);
    $stops_stmt->bind_param("i", $route_id);
    $stops_stmt->execute();
    $stops_result = $stops_stmt->get_result();
    $stops = $stops_result->fetch_all(MYSQLI_ASSOC);
    
    // Get departure times
    $times_sql = "SELECT * FROM route_time WHERE Route_ID = ? ORDER BY Departure_Time";
    $times_stmt = $conn->prepare($times_sql);
    $times_stmt->bind_param("i", $route_id);
    $times_stmt->execute();
    $times_result = $times_stmt->get_result();
    $departure_times = $times_result->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json'); // 添加这行
    echo json_encode([
        'route' => $route,
        'stops' => $stops,
        'departure_times' => $departure_times
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json'); // 添加这行
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>