<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Transport Coordinator
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get coordinator info
$sql = "SELECT * FROM user WHERE User_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();

// Handle form submissions
$message = '';
$message_type = '';

// Tab management
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'routes';

// Handle form submissions for routes
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_route'])) {
        // Add new route
        $route_name = trim($_POST['route_name']);
        $start_location = trim($_POST['start_location']);
        $end_location = trim($_POST['end_location']);
        $estimated_duration = intval($_POST['estimated_duration']);
        $status = $_POST['status'];
        $stops = isset($_POST['stops']) ? $_POST['stops'] : [];
        
        if (!empty($route_name) && !empty($start_location) && !empty($end_location)) {
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Calculate total stops (start + intermediate + end)
                $total_stops = count($stops) + 2; // +2 for start and end
                
                // Insert route
                $sql = "INSERT INTO route (Route_Name, Start_Location, End_Location, Total_Stops, Estimated_Duration_Minutes, Status) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssiis", $route_name, $start_location, $end_location, $total_stops, $estimated_duration, $status);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error adding route: " . $conn->error);
                }
                
                $route_id = $conn->insert_id;
                
                // Insert stops
                if (!empty($stops) || !empty($start_location) || !empty($end_location)) {
                    $stop_order = 1;
                    $sql_stop = "INSERT INTO route_stops (Route_ID, Stop_Order, Stop_Name, Estimated_Time_From_Start) 
                                 VALUES (?, ?, ?, ?)";
                    $stmt_stop = $conn->prepare($sql_stop);
                    
                    // Insert start location (always first stop)
                    $estimated_time = 0;
                    $stmt_stop->bind_param("iisi", $route_id, $stop_order, $start_location, $estimated_time);
                    $stmt_stop->execute();
                    $stop_order++;
                    
                    // Insert intermediate stops
                    foreach ($stops as $index => $stop_name) {
                        if (!empty(trim($stop_name))) {
                            // Calculate estimated time based on position
                            $estimated_time = intval(($estimated_duration / ($total_stops - 1)) * $stop_order);
                            $stmt_stop->bind_param("iisi", $route_id, $stop_order, $stop_name, $estimated_time);
                            $stmt_stop->execute();
                            $stop_order++;
                        }
                    }
                    
                    // Insert end location (always last stop)
                    $estimated_time = $estimated_duration;
                    $stmt_stop->bind_param("iisi", $route_id, $stop_order, $end_location, $estimated_time);
                    $stmt_stop->execute();
                }
                
                $conn->commit();
                $message = "Route added successfully with " . $total_stops . " stops!";
                $message_type = "success";
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields";
            $message_type = "error";
        }
    }
    elseif (isset($_POST['edit_route'])) {
        // Edit existing route
        $route_id = intval($_POST['route_id']);
        $route_name = trim($_POST['route_name']);
        $start_location = trim($_POST['start_location']);
        $end_location = trim($_POST['end_location']);
        $estimated_duration = intval($_POST['estimated_duration']);
        $status = $_POST['status'];
        
        $sql = "UPDATE route SET 
                Route_Name = ?, 
                Start_Location = ?, 
                End_Location = ?, 
                Estimated_Duration_Minutes = ?, 
                Status = ? 
                WHERE Route_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssisi", $route_name, $start_location, $end_location, $estimated_duration, $status, $route_id);
        
        if ($stmt->execute()) {
            $message = "Route updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating route: " . $conn->error;
            $message_type = "error";
        }
    }
    elseif (isset($_POST['delete_route'])) {
        // Delete route
        $route_id = intval($_POST['route_id']);
        
        // Check if route has active schedules
        $check_sql = "SELECT COUNT(*) as count FROM shuttle_schedule 
                     WHERE Route_ID = ? AND Status IN ('Scheduled', 'In Progress')";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $route_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $schedule_count = $check_result->fetch_assoc()['count'];
        
        if ($schedule_count > 0) {
            $message = "Cannot delete route with active schedules!";
            $message_type = "error";
        } else {
            // Delete the route (cascade will delete stops)
            $sql = "DELETE FROM route WHERE Route_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $route_id);
            
            if ($stmt->execute()) {
                $message = "Route deleted successfully!";
                $message_type = "success";
            } else {
                $message = "Error deleting route: " . $conn->error;
                $message_type = "error";
            }
        }
    }
    elseif (isset($_POST['manage_stops'])) {
        // Manage stops for a route
        $route_id = intval($_POST['route_id']);
        $stops = $_POST['stops'] ?? [];
        
        $conn->begin_transaction();
        
        try {
            // Delete existing stops
            $delete_sql = "DELETE FROM route_stops WHERE Route_ID = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $route_id);
            $delete_stmt->execute();
            
            // Insert new stops
            $stop_order = 1;
            $sql_stop = "INSERT INTO route_stops (Route_ID, Stop_Order, Stop_Name, Estimated_Time_From_Start) 
                         VALUES (?, ?, ?, ?)";
            $stmt_stop = $conn->prepare($sql_stop);
            
            // Process stops array
            foreach ($stops as $stop) {
                if (!empty(trim($stop['name']))) {
                    $estimated_time = intval($stop['estimated_time']);
                    $stmt_stop->bind_param("iisi", $route_id, $stop_order, $stop['name'], $estimated_time);
                    $stmt_stop->execute();
                    $stop_order++;
                }
            }
            
            // Update total stops count in route table
            $total_stops = $stop_order - 1;
            $update_sql = "UPDATE route SET Total_Stops = ? WHERE Route_ID = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $total_stops, $route_id);
            $update_stmt->execute();
            
            $conn->commit();
            $message = "Route stops updated successfully!";
            $message_type = "success";
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error updating stops: " . $e->getMessage();
            $message_type = "error";
        }
    }
    // Handle schedule form submissions
    elseif (isset($_POST['add_schedule'])) {
        $route_id = intval($_POST['route_id']);
        $vehicle_id = intval($_POST['vehicle_id']);
        $driver_id = intval($_POST['driver_id']);
        $departure_time = $_POST['departure_time'];
        $status = $_POST['schedule_status'];
        
        // Get route duration
        $route_query = $conn->query("SELECT Estimated_Duration_Minutes FROM route WHERE Route_ID = $route_id");
        $route_data = $route_query->fetch_assoc();
        $duration = $route_data['Estimated_Duration_Minutes'];
        
        // Calculate expected arrival
        $departure_datetime = new DateTime($departure_time);
        $departure_datetime->modify("+{$duration} minutes");
        $expected_arrival = $departure_datetime->format('Y-m-d H:i:s');
        
        // Get vehicle capacity
        $vehicle_query = $conn->query("SELECT Capacity FROM vehicle WHERE Vehicle_ID = $vehicle_id");
        $vehicle_data = $vehicle_query->fetch_assoc();
        $capacity = $vehicle_data['Capacity'];
        
        $sql = "INSERT INTO shuttle_schedule (Route_ID, Vehicle_ID, Driver_ID, Departure_time, Expected_Arrival, Status, Available_Seats) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiisssi", $route_id, $vehicle_id, $driver_id, $departure_time, $expected_arrival, $status, $capacity);
        
        if ($stmt->execute()) {
            $message = "Schedule added successfully!";
            $message_type = "success";
            $active_tab = 'schedules';
        } else {
            $message = "Error adding schedule: " . $conn->error;
            $message_type = "error";
        }
    }
    elseif (isset($_POST['edit_schedule'])) {
        $schedule_id = intval($_POST['schedule_id']);
        $route_id = intval($_POST['edit_route_id']);
        $vehicle_id = intval($_POST['edit_vehicle_id']);
        $driver_id = intval($_POST['edit_driver_id']);
        $departure_time = $_POST['edit_departure_time'];
        $status = $_POST['edit_schedule_status'];
        
        // Get route duration
        $route_query = $conn->query("SELECT Estimated_Duration_Minutes FROM route WHERE Route_ID = $route_id");
        $route_data = $route_query->fetch_assoc();
        $duration = $route_data['Estimated_Duration_Minutes'];
        
        // Calculate expected arrival
        $departure_datetime = new DateTime($departure_time);
        $departure_datetime->modify("+{$duration} minutes");
        $expected_arrival = $departure_datetime->format('Y-m-d H:i:s');
        
        $sql = "UPDATE shuttle_schedule SET 
                Route_ID = ?, 
                Vehicle_ID = ?, 
                Driver_ID = ?, 
                Departure_time = ?, 
                Expected_Arrival = ?, 
                Status = ? 
                WHERE Schedule_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiisssi", $route_id, $vehicle_id, $driver_id, $departure_time, $expected_arrival, $status, $schedule_id);
        
        if ($stmt->execute()) {
            $message = "Schedule updated successfully!";
            $message_type = "success";
            $active_tab = 'schedules';
        } else {
            $message = "Error updating schedule: " . $conn->error;
            $message_type = "error";
        }
    }
    elseif (isset($_POST['delete_schedule'])) {
        $schedule_id = intval($_POST['schedule_id']);
        
        // Check if schedule has reservations
        $check_sql = "SELECT COUNT(*) as count FROM seat_reservation WHERE Schedule_ID = ? AND Status = 'Reserved'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $schedule_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $reservation_count = $check_result->fetch_assoc()['count'];
        
        if ($reservation_count > 0) {
            $message = "Cannot delete schedule with active reservations!";
            $message_type = "error";
        } else {
            $sql = "DELETE FROM shuttle_schedule WHERE Schedule_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $schedule_id);
            
            if ($stmt->execute()) {
                $message = "Schedule deleted successfully!";
                $message_type = "success";
                $active_tab = 'schedules';
            } else {
                $message = "Error deleting schedule: " . $conn->error;
                $message_type = "error";
            }
        }
    }
    elseif (isset($_POST['update_schedule_status'])) {
        $schedule_id = intval($_POST['schedule_id']);
        $new_status = $_POST['new_status'];
        
        $sql = "UPDATE shuttle_schedule SET Status = ? WHERE Schedule_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $schedule_id);
        
        if ($stmt->execute()) {
            $message = "Schedule status updated successfully!";
            $message_type = "success";
            $active_tab = 'schedules';
        } else {
            $message = "Error updating schedule status: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Get all routes with stop counts
$sql = "SELECT r.*,
        (SELECT COUNT(*) FROM shuttle_schedule ss WHERE ss.Route_ID = r.Route_ID AND ss.Status IN ('Scheduled', 'In Progress')) as active_schedules,
        (SELECT COUNT(*) FROM route_stops rs WHERE rs.Route_ID = r.Route_ID) as stop_count
        FROM route r 
        ORDER BY r.Status, r.Route_Name";
$routes_result = $conn->query($sql);
$routes = $routes_result->fetch_all(MYSQLI_ASSOC);

// Get route statistics
$route_stats = $conn->query("
    SELECT 
        COUNT(*) as total_routes,
        SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) as active_routes,
        SUM(CASE WHEN Status = 'Inactive' THEN 1 ELSE 0 END) as inactive_routes,
        AVG(Estimated_Duration_Minutes) as avg_duration,
        SUM(Total_Stops) as total_stops,
        (SELECT COUNT(*) FROM route_stops) as total_stop_records
    FROM route
")->fetch_assoc();

// Get schedule statistics
$schedule_stats = $conn->query("
    SELECT 
        COUNT(*) as total_schedules,
        SUM(CASE WHEN Status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN Status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN Status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN Status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM shuttle_schedule
    WHERE DATE(Departure_time) >= CURDATE() - INTERVAL 7 DAY
")->fetch_assoc();

// Get upcoming schedules (next 7 days)
$upcoming_schedules = $conn->query("
    SELECT ss.*, r.Route_Name, v.Plate_number, v.Capacity, u.Full_Name as driver_name,
           (SELECT COUNT(*) FROM seat_reservation sr WHERE sr.Schedule_ID = ss.Schedule_ID AND sr.Status = 'Reserved') as reserved_seats
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    JOIN user u ON ss.Driver_ID = u.User_ID
    WHERE ss.Departure_time >= CURDATE()
    ORDER BY ss.Departure_time
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

// Get available vehicles
$vehicles = $conn->query("SELECT * FROM vehicle WHERE Status = 'Active'")->fetch_all(MYSQLI_ASSOC);

// Get available drivers
$drivers = $conn->query("
    SELECT u.*, d.License_Number 
    FROM user u 
    JOIN user_roles ur ON u.User_ID = ur.User_ID 
    JOIN roles r ON ur.Role_ID = r.Role_ID
    LEFT JOIN driver_profile d ON u.User_ID = d.User_ID
    WHERE r.Role_name = 'Driver'
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Routes & Schedules - Coordinator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .navbar {
            background: #9C27B0;
            color: white;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 70px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
        }
        
        .nav-links {
            display: flex;
            gap: 20px;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .nav-link.active {
            background: rgba(255,255,255,0.2);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .logout-btn {
            background: white;
            color: #9C27B0;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .logout-btn:hover {
            background: #F3E5F5;
        }
        
        .container {
            padding: 30px;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .page-title {
            color: #333;
            margin-bottom: 10px;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 16px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .tab {
            flex: 1;
            text-align: center;
            padding: 20px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }
        
        .tab:hover {
            background: #f8f9fa;
            color: #333;
        }
        
        .tab.active {
            color: #9C27B0;
            border-bottom: 3px solid #9C27B0;
            background: #f8f9fa;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #9C27B0;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .section-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #9C27B0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        .required:after {
            content: " *";
            color: #F44336;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #9C27B0;
            box-shadow: 0 0 0 2px rgba(156, 39, 176, 0.1);
        }
        
        .btn {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #7B1FA2;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        .btn-danger {
            background: #F44336;
        }
        
        .btn-danger:hover {
            background: #D32F2F;
        }
        
        .btn-success {
            background: #4CAF50;
        }
        
        .btn-success:hover {
            background: #388E3C;
        }
        
        .btn-warning {
            background: #FF9800;
        }
        
        .btn-warning:hover {
            background: #F57C00;
        }
        
        .btn-info {
            background: #2196F3;
        }
        
        .btn-info:hover {
            background: #1976D2;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }
        
        .routes-table, .schedules-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .routes-table th, .schedules-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }
        
        .routes-table td, .schedules-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .routes-table tr:hover, .schedules-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #4CAF50;
            color: white;
        }
        
        .status-inactive {
            background: #9E9E9E;
            color: white;
        }
        
        .status-scheduled {
            background: #2196F3;
            color: white;
        }
        
        .status-in-progress {
            background: #4CAF50;
            color: white;
        }
        
        .status-completed {
            background: #9E9E9E;
            color: white;
        }
        
        .status-cancelled {
            background: #F44336;
            color: white;
        }
        
        .status-delayed {
            background: #FF9800;
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .message-success {
            background: #4CAF50;
            color: white;
        }
        
        .message-error {
            background: #F44336;
            color: white;
        }
        
        .route-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .stops-container {
            margin-top: 15px;
        }
        
        .stop-item {
            display: grid;
            grid-template-columns: 40px 1fr 80px;
            gap: 10px;
            align-items: center;
            padding: 10px;
            margin-bottom: 8px;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 5px;
        }
        
        .stop-order {
            background: #9C27B0;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .stop-type-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .stop-type-start {
            background: #4CAF50;
            color: white;
        }
        
        .stop-type-end {
            background: #FF9800;
            color: white;
        }
        
        .stop-type-intermediate {
            background: #2196F3;
            color: white;
        }
        
        .stop-time {
            font-size: 12px;
            color: #666;
            text-align: right;
        }
        
        .stop-form-group {
            margin-bottom: 10px;
        }
        
        .stop-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            width: 800px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-title {
            margin-bottom: 20px;
            color: #333;
        }
        
        .modal-footer {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 20px;
        }
        
        .stop-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .add-stop-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .remove-stop-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            height: fit-content;
        }
        
        .stop-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .seats-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .seat-progress {
            flex-grow: 1;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .seat-progress-bar {
            height: 100%;
            background: #4CAF50;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .navbar {
                flex-direction: column;
                height: auto;
                padding: 15px;
                gap: 15px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stop-row {
                grid-template-columns: 1fr;
            }
            
            .stop-item {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">🚌 Campus Shuttle</div>
        <div class="nav-links">
            <a href="coordinator_dashboard.php" class="nav-link">Dashboard</a>
            <a href="manage_routes.php" class="nav-link active">Manage Routes</a>
            <a href="create_schedule.php" class="nav-link">Schedules</a>
            <a href="assign_driver.php" class="nav-link">Assign Driver</a>
            <a href="reports.php" class="nav-link">Reports</a>
        </div>
        <div class="user-info">
            <div class="user-badge">
                <?php echo $_SESSION['username']; ?> (Coordinator)
            </div>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">🚏 Manage Routes & Schedules</h1>
            <p class="page-subtitle">Create, edit, and manage campus shuttle routes and schedules</p>
        </div>
        
        <!-- Message Display -->
        <?php if($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab <?php echo $active_tab == 'routes' ? 'active' : ''; ?>" onclick="switchTab('routes')">
                🚏 Routes Management
            </div>
            <div class="tab <?php echo $active_tab == 'schedules' ? 'active' : ''; ?>" onclick="switchTab('schedules')">
                📅 Schedule Management
            </div>
        </div>
        
        <!-- Routes Tab Content -->
        <div id="routes-tab" class="tab-content <?php echo $active_tab == 'routes' ? 'active' : ''; ?>">
            <!-- Route Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Routes</div>
                    <div class="stat-number"><?php echo $route_stats['total_routes']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Active Routes</div>
                    <div class="stat-number"><?php echo $route_stats['active_routes']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Total Stops</div>
                    <div class="stat-number"><?php echo $route_stats['total_stop_records']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Avg Duration</div>
                    <div class="stat-number"><?php echo round($route_stats['avg_duration']); ?> min</div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column: Add/Edit Route Form -->
                <div class="section-card">
                    <h2 class="section-title">Add New Route</h2>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label class="form-label required">Route Name</label>
                            <input type="text" name="route_name" class="form-control" 
                                   placeholder="e.g., Route A - Main Gate to Library" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Start Location</label>
                            <input type="text" name="start_location" class="form-control" 
                                   placeholder="e.g., Main Gate" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Intermediate Stops</label>
                            <div id="stops-container">
                                <!-- Stops will be added here dynamically -->
                            </div>
                            <button type="button" class="add-stop-btn" onclick="addStopField()">
                                + Add Intermediate Stop
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">End Location</label>
                            <input type="text" name="end_location" class="form-control" 
                                   placeholder="e.g., Library" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Estimated Duration (minutes)</label>
                            <input type="number" name="estimated_duration" class="form-control" 
                                   min="5" max="180" value="15" required>
                            <small style="color: #666;">Total time from start to end location</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="add_route" class="btn btn-success">
                                🚀 Add New Route
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Right Column: Route List -->
                <div class="section-card">
                    <h2 class="section-title">All Routes (<?php echo count($routes); ?>)</h2>
                    
                    <?php if(count($routes) > 0): ?>
                        <table class="routes-table">
                            <thead>
                                <tr>
                                    <th>Route Name</th>
                                    <th>Route Path</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($routes as $route): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $route['Route_Name']; ?></strong>
                                            <?php if($route['active_schedules'] > 0): ?>
                                                <br><small style="color: #666;"><?php echo $route['active_schedules']; ?> active schedule(s)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="stop-info">
                                                <span>📍 <?php echo $route['Start_Location']; ?></span>
                                                <span>→</span>
                                                <span>📍 <?php echo $route['End_Location']; ?></span>
                                            </div>
                                            <small style="color: #666;"><?php echo $route['stop_count']; ?> stops total</small>
                                        </td>
                                        <td><?php echo $route['Estimated_Duration_Minutes']; ?> min</td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($route['Status']); ?>">
                                                <?php echo $route['Status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-warning" 
                                                        onclick="manageStops(<?php echo $route['Route_ID']; ?>)">
                                                    🚏 Manage Stops
                                                </button>
                                                <button class="action-btn btn-secondary" 
                                                        onclick="editRoute(<?php echo $route['Route_ID']; ?>)">
                                                    ✏️ Edit
                                                </button>
                                                <button class="action-btn btn-danger" 
                                                        onclick="deleteRoute(<?php echo $route['Route_ID']; ?>, '<?php echo addslashes($route['Route_Name']); ?>')">
                                                    🗑️ Delete
                                                </button>
                                                <button class="action-btn btn" 
                                                        onclick="viewRouteDetails(<?php echo $route['Route_ID']; ?>)">
                                                    👁️ Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No routes found. Create your first route!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Schedules Tab Content -->
        <div id="schedules-tab" class="tab-content <?php echo $active_tab == 'schedules' ? 'active' : ''; ?>">
            <!-- Schedule Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Schedules</div>
                    <div class="stat-number"><?php echo $schedule_stats['total_schedules']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Scheduled</div>
                    <div class="stat-number"><?php echo $schedule_stats['scheduled']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">In Progress</div>
                    <div class="stat-number"><?php echo $schedule_stats['in_progress']; ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-number"><?php echo $schedule_stats['completed']; ?></div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column: Add/Edit Schedule Form -->
                <div class="section-card">
                    <h2 class="section-title">Add New Schedule</h2>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Route</label>
                                <select name="route_id" class="form-control" required>
                                    <option value="">Select Route</option>
                                    <?php 
                                    $active_routes = $conn->query("SELECT * FROM route WHERE Status = 'Active'");
                                    while($route = $active_routes->fetch_assoc()): ?>
                                        <option value="<?php echo $route['Route_ID']; ?>">
                                            <?php echo $route['Route_Name']; ?> (<?php echo $route['Estimated_Duration_Minutes']; ?> min)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Vehicle</label>
                                <select name="vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach($vehicles as $vehicle): ?>
                                        <option value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                            <?php echo $vehicle['Plate_number']; ?> (<?php echo $vehicle['Capacity']; ?> seats)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Driver</label>
                                <select name="driver_id" class="form-control" required>
                                    <option value="">Select Driver</option>
                                    <?php foreach($drivers as $driver): ?>
                                        <option value="<?php echo $driver['User_ID']; ?>">
                                            <?php echo $driver['Full_Name']; ?> (<?php echo $driver['License_Number']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Departure Time</label>
                                <input type="datetime-local" name="departure_time" class="form-control" 
                                       value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="schedule_status" class="form-control">
                                <option value="Scheduled">Scheduled</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="add_schedule" class="btn btn-success">
                                🚌 Add Schedule
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Right Column: Schedule List -->
                <div class="section-card">
                    <h2 class="section-title">Upcoming Schedules (<?php echo count($upcoming_schedules); ?>)</h2>
                    
                    <?php if(count($upcoming_schedules) > 0): ?>
                        <table class="schedules-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Route</th>
                                    <th>Vehicle</th>
                                    <th>Seats</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($upcoming_schedules as $schedule): ?>
                                    <?php
                                    $available_seats = $schedule['Capacity'] - $schedule['reserved_seats'];
                                    $seat_percentage = ($schedule['reserved_seats'] / $schedule['Capacity']) * 100;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('M d', strtotime($schedule['Departure_time'])); ?></strong><br>
                                            <?php echo date('H:i', strtotime($schedule['Departure_time'])); ?>
                                        </td>
                                        <td>
                                            <?php echo $schedule['Route_Name']; ?><br>
                                            <small>Driver: <?php echo $schedule['driver_name']; ?></small>
                                        </td>
                                        <td><?php echo $schedule['Plate_number']; ?></td>
                                        <td>
                                            <div class="seats-info">
                                                <span><?php echo $available_seats; ?>/<?php echo $schedule['Capacity']; ?></span>
                                                <div class="seat-progress">
                                                    <div class="seat-progress-bar" style="width: <?php echo $seat_percentage; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = '';
                                            switch($schedule['Status']) {
                                                case 'Scheduled': $status_class = 'status-scheduled'; break;
                                                case 'In Progress': $status_class = 'status-in-progress'; break;
                                                case 'Completed': $status_class = 'status-completed'; break;
                                                case 'Cancelled': $status_class = 'status-cancelled'; break;
                                                case 'Delayed': $status_class = 'status-delayed'; break;
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $schedule['Status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-info btn-sm" 
                                                        onclick="editSchedule(<?php echo $schedule['Schedule_ID']; ?>)">
                                                    ✏️ Edit
                                                </button>
                                                <button class="action-btn btn-warning btn-sm" 
                                                        onclick="updateScheduleStatus(<?php echo $schedule['Schedule_ID']; ?>)">
                                                    🔄 Status
                                                </button>
                                                <button class="action-btn btn-danger btn-sm" 
                                                        onclick="deleteSchedule(<?php echo $schedule['Schedule_ID']; ?>, '<?php echo date('M d H:i', strtotime($schedule['Departure_time'])); ?>')">
                                                    🗑️ Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No upcoming schedules found. Create your first schedule!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Manage Stops Modal -->
    <div id="manageStopsModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title" id="modalRouteName">Manage Stops</h2>
            <form id="manageStopsForm" method="POST" action="">
                <input type="hidden" name="route_id" id="manage_route_id">
                
                <div class="form-group">
                    <label class="form-label">Route Stops (in order)</label>
                    <div id="stops-list">
                        <!-- Stops will be populated here -->
                    </div>
                    <button type="button" class="add-stop-btn" onclick="addStopToModal()">
                        + Add Stop
                    </button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeManageStopsModal()">Cancel</button>
                    <button type="submit" name="manage_stops" class="btn">Save Stops</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Route Details Modal -->
    <div id="viewDetailsModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Route Details</h2>
            <div id="routeDetailsContent">
                <!-- Details will be populated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeViewDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Route Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Route</h2>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="route_id" id="edit_route_id">
                
                <div class="form-group">
                    <label class="form-label required">Route Name</label>
                    <input type="text" name="route_name" id="edit_route_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Start Location</label>
                    <input type="text" name="start_location" id="edit_start_location" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">End Location</label>
                    <input type="text" name="end_location" id="edit_end_location" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Estimated Duration (minutes)</label>
                    <input type="number" name="estimated_duration" id="edit_estimated_duration" class="form-control" 
                           min="5" max="180">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" name="edit_route" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Schedule Modal -->
    <div id="editScheduleModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Schedule</h2>
            <form id="editScheduleForm" method="POST" action="">
                <input type="hidden" name="schedule_id" id="edit_schedule_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Route</label>
                        <select name="edit_route_id" id="edit_schedule_route_id" class="form-control" required>
                            <option value="">Select Route</option>
                            <?php 
                            $active_routes = $conn->query("SELECT * FROM route WHERE Status = 'Active'");
                            while($route = $active_routes->fetch_assoc()): ?>
                                <option value="<?php echo $route['Route_ID']; ?>">
                                    <?php echo $route['Route_Name']; ?> (<?php echo $route['Estimated_Duration_Minutes']; ?> min)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Vehicle</label>
                        <select name="edit_vehicle_id" id="edit_schedule_vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                    <?php echo $vehicle['Plate_number']; ?> (<?php echo $vehicle['Capacity']; ?> seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Driver</label>
                        <select name="edit_driver_id" id="edit_schedule_driver_id" class="form-control" required>
                            <option value="">Select Driver</option>
                            <?php foreach($drivers as $driver): ?>
                                <option value="<?php echo $driver['User_ID']; ?>">
                                    <?php echo $driver['Full_Name']; ?> (<?php echo $driver['License_Number']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Departure Time</label>
                        <input type="datetime-local" name="edit_departure_time" id="edit_schedule_departure_time" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="edit_schedule_status" id="edit_schedule_status" class="form-control">
                        <option value="Scheduled">Scheduled</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Delayed">Delayed</option>
                        <option value="Completed">Completed</option>
                        <option value="In Progress">In Progress</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeEditScheduleModal()">Cancel</button>
                    <button type="submit" name="edit_schedule" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Update Schedule Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Update Schedule Status</h2>
            <form id="updateStatusForm" method="POST" action="">
                <input type="hidden" name="schedule_id" id="status_schedule_id">
                
                <div class="form-group">
                    <label class="form-label required">New Status</label>
                    <select name="new_status" id="new_status" class="form-control" required>
                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Delayed">Delayed</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeUpdateStatusModal()">Cancel</button>
                    <button type="submit" name="update_schedule_status" class="btn">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modals -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Delete Route</h2>
            <p id="deleteMessage">Are you sure you want to delete this route?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="route_id" id="delete_route_id">
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_route" class="btn-danger">Delete Route</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="deleteScheduleModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Delete Schedule</h2>
            <p id="deleteScheduleMessage">Are you sure you want to delete this schedule?</p>
            <form id="deleteScheduleForm" method="POST" action="">
                <input type="hidden" name="schedule_id" id="delete_schedule_id">
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeDeleteScheduleModal()">Cancel</button>
                    <button type="submit" name="delete_schedule" class="btn-danger">Delete Schedule</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let stopCounter = 0;
        
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        function switchTab(tabName) {
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // Update active tab
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelector(`.tab[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(`${tabName}-tab`).classList.add('active');
        }
        
        function addStopField() {
            stopCounter++;
            const container = document.getElementById('stops-container');
            const stopDiv = document.createElement('div');
            stopDiv.className = 'stop-input-group';
            stopDiv.innerHTML = `
                <input type="text" name="stops[]" class="form-control" placeholder="Intermediate Stop ${stopCounter}">
                <button type="button" class="remove-stop-btn" onclick="this.parentElement.remove();">×</button>
            `;
            container.appendChild(stopDiv);
        }
        
        function manageStops(routeId) {
            fetch('get_route_stops.php?route_id=' + routeId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const route = data.route;
                        const stops = data.stops;
                        
                        document.getElementById('manage_route_id').value = routeId;
                        document.getElementById('modalRouteName').textContent = 'Manage Stops: ' + route.Route_Name;
                        
                        const stopsList = document.getElementById('stops-list');
                        stopsList.innerHTML = '';
                        
                        // Add start location (first stop)
                        if (route.Start_Location) {
                            const startDiv = document.createElement('div');
                            startDiv.className = 'stop-row';
                            startDiv.innerHTML = `
                                <input type="text" name="stops[0][name]" class="form-control" 
                                       value="${route.Start_Location}" placeholder="Start location" readonly>
                                <input type="number" name="stops[0][estimated_time]" class="form-control" 
                                       value="0" placeholder="Minutes" min="0" readonly>
                                <small style="grid-column: span 2; color: #4CAF50;">Start Location</small>
                            `;
                            stopsList.appendChild(startDiv);
                        }
                        
                        // Add intermediate and end stops from route_stops table
                        stops.forEach((stop, index) => {
                            // Skip start location as it's already added
                            if (stop.Stop_Name !== route.Start_Location) {
                                const stopDiv = document.createElement('div');
                                stopDiv.className = 'stop-row';
                                stopDiv.innerHTML = `
                                    <input type="text" name="stops[${index + 1}][name]" class="form-control" 
                                           value="${stop.Stop_Name}" placeholder="Stop name" required>
                                    <input type="number" name="stops[${index + 1}][estimated_time]" class="form-control" 
                                           value="${stop.Estimated_Time_From_Start}" placeholder="Minutes" min="0" required>
                                    <button type="button" class="remove-stop-btn" onclick="removeStopRow(this)">×</button>
                                `;
                                stopsList.appendChild(stopDiv);
                            }
                        });
                        
                        // Ensure end location exists
                        if (route.End_Location && !stops.some(stop => stop.Stop_Name === route.End_Location)) {
                            const endIndex = stops.length;
                            const endDiv = document.createElement('div');
                            endDiv.className = 'stop-row';
                            endDiv.innerHTML = `
                                <input type="text" name="stops[${endIndex}][name]" class="form-control" 
                                       value="${route.End_Location}" placeholder="End location" required>
                                <input type="number" name="stops[${endIndex}][estimated_time]" class="form-control" 
                                       value="${route.Estimated_Duration_Minutes}" placeholder="Minutes" min="0" required>
                                <small style="grid-column: span 2; color: #FF9800;">End Location</small>
                            `;
                            stopsList.appendChild(endDiv);
                        }
                        
                        document.getElementById('manageStopsModal').style.display = 'flex';
                    } else {
                        alert('Error loading stops: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading stops');
                });
        }
        
        function addStopToModal() {
            const stopsList = document.getElementById('stops-list');
            const index = stopsList.children.length;
            const stopDiv = document.createElement('div');
            stopDiv.className = 'stop-row';
            stopDiv.innerHTML = `
                <input type="text" name="stops[${index}][name]" class="form-control" placeholder="Stop name" required>
                <input type="number" name="stops[${index}][estimated_time]" class="form-control" 
                       value="0" placeholder="Minutes" min="0" required>
                <button type="button" class="remove-stop-btn" onclick="removeStopRow(this)">×</button>
            `;
            stopsList.appendChild(stopDiv);
        }
        
        function removeStopRow(button) {
            button.parentElement.remove();
        }
        
        function viewRouteDetails(routeId) {
            fetch('get_route_details.php?route_id=' + routeId + '&include_stops=true')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const route = data.route;
                        const stops = data.stops || [];
                        
                        let stopsHtml = '<div class="stops-container">';
                        
                        // Sort stops by Estimated_Time_From_Start
                        stops.sort((a, b) => a.Estimated_Time_From_Start - b.Estimated_Time_From_Start);
                        
                        stops.forEach((stop, index) => {
                            // Determine stop type based on position
                            let stopType = 'Intermediate';
                            let stopTypeClass = 'stop-type-intermediate';
                            
                            if (index === 0) {
                                stopType = 'Start';
                                stopTypeClass = 'stop-type-start';
                            } else if (index === stops.length - 1) {
                                stopType = 'End';
                                stopTypeClass = 'stop-type-end';
                            }
                            
                            stopsHtml += `
                                <div class="stop-item">
                                    <div class="stop-order">${index + 1}</div>
                                    <div>${stop.Stop_Name}</div>
                                    <span class="stop-type-badge ${stopTypeClass}">${stopType}</span>
                                    <div class="stop-time">${stop.Estimated_Time_From_Start} min</div>
                                </div>
                            `;
                        });
                        stopsHtml += '</div>';
                        
                        document.getElementById('routeDetailsContent').innerHTML = `
                            <div style="margin-bottom: 20px;">
                                <h3>${route.Route_Name}</h3>
                                <p><strong>Status:</strong> <span class="status-badge status-${route.Status.toLowerCase()}">${route.Status}</span></p>
                                <p><strong>Total Duration:</strong> ${route.Estimated_Duration_Minutes} minutes</p>
                                <p><strong>Start Location:</strong> ${route.Start_Location}</p>
                                <p><strong>End Location:</strong> ${route.End_Location}</p>
                                <p><strong>Total Stops:</strong> ${route.Total_Stops}</p>
                            </div>
                            <h4>Route Path:</h4>
                            ${stopsHtml}
                        `;
                        
                        document.getElementById('viewDetailsModal').style.display = 'flex';
                    } else {
                        alert('Error loading route details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading route details');
                });
        }
        
        function editRoute(routeId) {
            fetch('get_route_details.php?route_id=' + routeId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_route_id').value = data.route.Route_ID;
                        document.getElementById('edit_route_name').value = data.route.Route_Name;
                        document.getElementById('edit_start_location').value = data.route.Start_Location;
                        document.getElementById('edit_end_location').value = data.route.End_Location;
                        document.getElementById('edit_estimated_duration').value = data.route.Estimated_Duration_Minutes;
                        document.getElementById('edit_status').value = data.route.Status;
                        
                        document.getElementById('editModal').style.display = 'flex';
                    } else {
                        alert('Error loading route details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading route details');
                });
        }
        
        function editSchedule(scheduleId) {
            fetch('get_schedule_details.php?schedule_id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const schedule = data.schedule;
                        document.getElementById('edit_schedule_id').value = schedule.Schedule_ID;
                        document.getElementById('edit_schedule_route_id').value = schedule.Route_ID;
                        document.getElementById('edit_schedule_vehicle_id').value = schedule.Vehicle_ID;
                        document.getElementById('edit_schedule_driver_id').value = schedule.Driver_ID;
                        document.getElementById('edit_schedule_departure_time').value = schedule.Departure_time.replace(' ', 'T').substring(0, 16);
                        document.getElementById('edit_schedule_status').value = schedule.Status;
                        
                        document.getElementById('editScheduleModal').style.display = 'flex';
                    } else {
                        alert('Error loading schedule details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading schedule details');
                });
        }
        
        function updateScheduleStatus(scheduleId) {
            document.getElementById('status_schedule_id').value = scheduleId;
            document.getElementById('updateStatusModal').style.display = 'flex';
        }
        
        function deleteRoute(routeId, routeName) {
            document.getElementById('delete_route_id').value = routeId;
            document.getElementById('deleteMessage').innerHTML = 
                `Are you sure you want to delete route <strong>"${routeName}"</strong>?<br><br>
                <small style="color: #666;">This action cannot be undone.</small>`;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        
        function deleteSchedule(scheduleId, scheduleTime) {
            document.getElementById('delete_schedule_id').value = scheduleId;
            document.getElementById('deleteScheduleMessage').innerHTML = 
                `Are you sure you want to delete schedule <strong>"${scheduleTime}"</strong>?<br><br>
                <small style="color: #666;">This action cannot be undone.</small>`;
            document.getElementById('deleteScheduleModal').style.display = 'flex';
        }
        
        function closeManageStopsModal() {
            document.getElementById('manageStopsModal').style.display = 'none';
        }
        
        function closeViewDetailsModal() {
            document.getElementById('viewDetailsModal').style.display = 'none';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function closeEditScheduleModal() {
            document.getElementById('editScheduleModal').style.display = 'none';
        }
        
        function closeUpdateStatusModal() {
            document.getElementById('updateStatusModal').style.display = 'none';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function closeDeleteScheduleModal() {
            document.getElementById('deleteScheduleModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                closeManageStopsModal();
                closeViewDetailsModal();
                closeEditModal();
                closeEditScheduleModal();
                closeUpdateStatusModal();
                closeDeleteModal();
                closeDeleteScheduleModal();
            }
        };
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeManageStopsModal();
                closeViewDetailsModal();
                closeEditModal();
                closeEditScheduleModal();
                closeUpdateStatusModal();
                closeDeleteModal();
                closeDeleteScheduleModal();
            }
        });
        
        // Initialize with one intermediate stop field
        addStopField();
    </script>
</body>
</html>