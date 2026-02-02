<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if(isset($_GET['route_id'])) {
    $route_id = intval($_GET['route_id']);
    
    // Get route details
    $sql = "SELECT * FROM route WHERE Route_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $route_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $route = $result->fetch_assoc();
        
        // Get route stops
        $stops_sql = "SELECT * FROM route_stops WHERE Route_ID = ? ORDER BY Stop_Order";
        $stops_stmt = $conn->prepare($stops_sql);
        $stops_stmt->bind_param("i", $route_id);
        $stops_stmt->execute();
        $stops_result = $stops_stmt->get_result();
        $stops = $stops_result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'route' => $route,
            'stops' => $stops
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Route not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No route ID provided']);
}
?>