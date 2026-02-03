<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = '';
$routes = [];
$route_details = null;
$route_stops = [];
$route_times = [];
$edit_mode = false;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_route':
                // Create new route
                $route_name = trim($_POST['route_name']);
                $start_location = trim($_POST['start_location']);
                $end_location = trim($_POST['end_location']);
                $estimated_duration = intval($_POST['estimated_duration']);
                $status = $_POST['status'];
                $departure_times = isset($_POST['departure_time']) ? $_POST['departure_time'] : [];
                
                // Check if route name already exists
                $check_sql = "SELECT Route_ID FROM route WHERE Route_Name = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $route_name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Route name already exists!";
                    $message_type = "error";
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Insert into route table
                        $route_sql = "INSERT INTO route (Route_Name, Start_Location, End_Location, Estimated_Duration_Minutes, Status) 
                                     VALUES (?, ?, ?, ?, ?)";
                        $route_stmt = $conn->prepare($route_sql);
                        $route_stmt->bind_param("sssis", $route_name, $start_location, $end_location, $estimated_duration, $status);
                        $route_stmt->execute();
                        $route_id = $conn->insert_id;
                        
                        // Insert departure times if provided
                        if (!empty($departure_times) && is_array($departure_times)) {
                            $time_sql = "INSERT INTO route_time (Route_ID, Departure_Time) VALUES (?, ?)";
                            $time_stmt = $conn->prepare($time_sql);
                            
                            foreach ($departure_times as $time) {
                                $trimmed_time = trim($time);
                                if (!empty($trimmed_time)) {
                                    $time_stmt->bind_param("is", $route_id, $trimmed_time);
                                    $time_stmt->execute();
                                }
                            }
                        }
                        
                        // Insert route stops if provided
                        if (isset($_POST['stop_name']) && is_array($_POST['stop_name'])) {
                            $stop_names = $_POST['stop_name'];
                            $stop_orders = $_POST['stop_order'];
                            $estimated_times = $_POST['estimated_time'];
                            
                            $total_stops = count($stop_names);
                            
                            // Update total stops in route table
                            $update_stops_sql = "UPDATE route SET Total_Stops = ? WHERE Route_ID = ?";
                            $update_stops_stmt = $conn->prepare($update_stops_sql);
                            $update_stops_stmt->bind_param("ii", $total_stops, $route_id);
                            $update_stops_stmt->execute();
                            
                            // Insert each stop
                            $stop_sql = "INSERT INTO route_stops (Route_ID, Stop_Name, Stop_Order, Estimated_Time_From_Start) 
                                        VALUES (?, ?, ?, ?)";
                            $stop_stmt = $conn->prepare($stop_sql);
                            
                            for ($i = 0; $i < count($stop_names); $i++) {
                                $stop_name = trim($stop_names[$i]);
                                $stop_order_val = intval($stop_orders[$i]);
                                $estimated_time_val = intval($estimated_times[$i]);
                                
                                if (!empty($stop_name)) {
                                    $stop_stmt->bind_param("isii", $route_id, $stop_name, $stop_order_val, $estimated_time_val);
                                    $stop_stmt->execute();
                                }
                            }
                        }
                        
                        $conn->commit();
                        $message = "Route created successfully!";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error creating route: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
                
            case 'edit_route':
                // Prepare route data for editing
                $route_id = $_POST['route_id'];
                $edit_mode = true;
                
                // Get route details
                $get_sql = "SELECT * FROM route WHERE Route_ID = ?";
                $get_stmt = $conn->prepare($get_sql);
                $get_stmt->bind_param("i", $route_id);
                $get_stmt->execute();
                $route_details = $get_stmt->get_result()->fetch_assoc();
                
                // Get route stops
                $stops_sql = "SELECT * FROM route_stops WHERE Route_ID = ? ORDER BY Stop_Order";
                $stops_stmt = $conn->prepare($stops_sql);
                $stops_stmt->bind_param("i", $route_id);
                $stops_stmt->execute();
                $route_stops = $stops_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                // Get departure times
                $times_sql = "SELECT * FROM route_time WHERE Route_ID = ? ORDER BY Departure_Time";
                $times_stmt = $conn->prepare($times_sql);
                $times_stmt->bind_param("i", $route_id);
                $times_stmt->execute();
                $route_times = $times_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                break;
                
            case 'update_route':
                // Update existing route
                $route_id = $_POST['route_id'];
                $route_name = trim($_POST['route_name']);
                $start_location = trim($_POST['start_location']);
                $end_location = trim($_POST['end_location']);
                $estimated_duration = intval($_POST['estimated_duration']);
                $status = $_POST['status'];
                $departure_times = isset($_POST['departure_time']) ? $_POST['departure_time'] : [];
                
                // Check if route name already exists (excluding current route)
                $check_sql = "SELECT Route_ID FROM route WHERE Route_Name = ? AND Route_ID != ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("si", $route_name, $route_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Route name already exists!";
                    $message_type = "error";
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Update route table
                        $update_sql = "UPDATE route SET 
                                      Route_Name = ?, 
                                      Start_Location = ?, 
                                      End_Location = ?, 
                                      Estimated_Duration_Minutes = ?, 
                                      Status = ? 
                                      WHERE Route_ID = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("sssisi", $route_name, $start_location, $end_location, 
                                               $estimated_duration, $status, $route_id);
                        $update_stmt->execute();
                        
                        // Delete existing departure times
                        $delete_times_sql = "DELETE FROM route_time WHERE Route_ID = ?";
                        $delete_times_stmt = $conn->prepare($delete_times_sql);
                        $delete_times_stmt->bind_param("i", $route_id);
                        $delete_times_stmt->execute();
                        
                        // Insert new departure times
                        if (!empty($departure_times) && is_array($departure_times)) {
                            $time_sql = "INSERT INTO route_time (Route_ID, Departure_Time) VALUES (?, ?)";
                            $time_stmt = $conn->prepare($time_sql);
                            
                            foreach ($departure_times as $time) {
                                $trimmed_time = trim($time);
                                if (!empty($trimmed_time)) {
                                    $time_stmt->bind_param("is", $route_id, $trimmed_time);
                                    $time_stmt->execute();
                                }
                            }
                        }
                        
                        // Delete existing stops
                        $delete_stops_sql = "DELETE FROM route_stops WHERE Route_ID = ?";
                        $delete_stops_stmt = $conn->prepare($delete_stops_sql);
                        $delete_stops_stmt->bind_param("i", $route_id);
                        $delete_stops_stmt->execute();
                        
                        // Insert new stops if provided
                        if (isset($_POST['stop_name']) && is_array($_POST['stop_name'])) {
                            $stop_names = $_POST['stop_name'];
                            $stop_orders = $_POST['stop_order'];
                            $estimated_times = $_POST['estimated_time'];
                            
                            $total_stops = count($stop_names);
                            
                            // Update total stops in route table
                            $update_stops_sql = "UPDATE route SET Total_Stops = ? WHERE Route_ID = ?";
                            $update_stops_stmt = $conn->prepare($update_stops_sql);
                            $update_stops_stmt->bind_param("ii", $total_stops, $route_id);
                            $update_stops_stmt->execute();
                            
                            // Insert each stop
                            $stop_sql = "INSERT INTO route_stops (Route_ID, Stop_Name, Stop_Order, Estimated_Time_From_Start) 
                                        VALUES (?, ?, ?, ?)";
                            $stop_stmt = $conn->prepare($stop_sql);
                            
                            for ($i = 0; $i < count($stop_names); $i++) {
                                $stop_name = trim($stop_names[$i]);
                                $stop_order_val = intval($stop_orders[$i]);
                                $estimated_time_val = intval($estimated_times[$i]);
                                
                                if (!empty($stop_name)) {
                                    $stop_stmt->bind_param("isii", $route_id, $stop_name, $stop_order_val, $estimated_time_val);
                                    $stop_stmt->execute();
                                }
                            }
                        }
                        
                        $conn->commit();
                        $message = "Route updated successfully!";
                        $message_type = "success";
                        $edit_mode = false;
                        $route_details = null;
                        $route_stops = [];
                        $route_times = [];
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error updating route: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
                
            case 'delete_route':
                // Delete route
                $route_id = $_POST['route_id'];
                
                // Check if route has active schedules
                $check_schedules_sql = "SELECT COUNT(*) as schedule_count FROM shuttle_schedule 
                                       WHERE Route_ID = ? AND Status IN ('Scheduled', 'In Progress')";
                $check_schedules_stmt = $conn->prepare($check_schedules_sql);
                $check_schedules_stmt->bind_param("i", $route_id);
                $check_schedules_stmt->execute();
                $check_result = $check_schedules_stmt->get_result();
                $schedule_count = $check_result->fetch_assoc()['schedule_count'];
                
                if ($schedule_count > 0) {
                    $message = "Cannot delete route. There are active schedules assigned to this route.";
                    $message_type = "error";
                } else {
                    try {
                        // Start transaction
                        $conn->begin_transaction();
                        
                        // Delete route times first
                        $delete_times_sql = "DELETE FROM route_time WHERE Route_ID = ?";
                        $delete_times_stmt = $conn->prepare($delete_times_sql);
                        $delete_times_stmt->bind_param("i", $route_id);
                        $delete_times_stmt->execute();
                        
                        // Delete route stops first (due to foreign key constraint)
                        $delete_stops_sql = "DELETE FROM route_stops WHERE Route_ID = ?";
                        $delete_stops_stmt = $conn->prepare($delete_stops_sql);
                        $delete_stops_stmt->bind_param("i", $route_id);
                        $delete_stops_stmt->execute();
                        
                        // Delete route
                        $delete_sql = "DELETE FROM route WHERE Route_ID = ?";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("i", $route_id);
                        $delete_stmt->execute();
                        
                        $conn->commit();
                        $message = "Route deleted successfully!";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error deleting route: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
                
            case 'activate_route':
            case 'deactivate_route':
                // Toggle route status
                $route_id = $_POST['route_id'];
                $new_status = $_POST['action'] == 'activate_route' ? 'Active' : 'Inactive';
                
                $update_sql = "UPDATE route SET Status = ? WHERE Route_ID = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $new_status, $route_id);
                
                if ($update_stmt->execute()) {
                    $message = "Route " . strtolower($new_status) . "d successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating route status";
                    $message_type = "error";
                }
                break;
        }
    }
}

// Get all routes with stop counts and departure times
$routes_sql = "SELECT r.*, 
               COUNT(rs.Stop_ID) as Stop_Count,
               GROUP_CONCAT(rt.Departure_Time ORDER BY rt.Departure_Time) as Departure_Times,
               (SELECT COUNT(*) FROM shuttle_schedule s WHERE s.Route_ID = r.Route_ID AND s.Status IN ('Scheduled', 'In Progress')) as Active_Schedules
               FROM route r
               LEFT JOIN route_stops rs ON r.Route_ID = rs.Route_ID
               LEFT JOIN route_time rt ON r.Route_ID = rt.Route_ID
               GROUP BY r.Route_ID
               ORDER BY r.Status DESC, r.Route_Name";
$routes_result = $conn->query($routes_sql);
if ($routes_result) {
    $routes = $routes_result->fetch_all(MYSQLI_ASSOC);
}

// Get schedule counts for each route (for display purposes)
$schedule_counts = [];
$schedule_sql = "SELECT Route_ID, COUNT(*) as count FROM shuttle_schedule GROUP BY Route_ID";
$schedule_result = $conn->query($schedule_sql);
while ($row = $schedule_result->fetch_assoc()) {
    $schedule_counts[$row['Route_ID']] = $row['count'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Routes - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .manage-routes-container {
            padding: 30px;
            max-width: 1600px;
            margin: 80px auto 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .page-title {
            color: #333;
            font-size: 28px;
        }
        
        .back-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        select.form-control {
            height: 42px;
        }
        
        .btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background: #388E3C;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .routes-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .routes-table th,
        .routes-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .routes-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .routes-table tr:hover {
            background: #f8f9fa;
        }
        
        .route-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        
        .edit-btn {
            background: #ffc107;
            color: #212529;
        }
        
        .edit-btn:hover {
            background: #e0a800;
        }
        
        .delete-btn {
            background: #dc3545;
            color: white;
        }
        
        .delete-btn:hover {
            background: #c82333;
        }
        
        .delete-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .activate-btn {
            background: #28a745;
            color: white;
        }
        
        .activate-btn:hover {
            background: #218838;
        }
        
        .deactivate-btn {
            background: #6c757d;
            color: white;
        }
        
        .deactivate-btn:hover {
            background: #5a6268;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .stops-container {
            margin-top: 20px;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .stops-header {
            background: #f8f9fa;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }
        
        .stops-title {
            font-weight: 600;
            color: #495057;
            margin: 0;
        }
        
        .add-stop-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .add-stop-btn:hover {
            background: #5a6268;
        }
        
        .stops-list {
            padding: 20px;
        }
        
        .stop-item {
            display: grid;
            grid-template-columns: 1fr 100px 120px 40px;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .stop-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .stop-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .remove-stop {
            background: #dc3545;
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .remove-stop:hover {
            background: #c82333;
        }
        
        .route-info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            font-size: 14px;
        }
        
        .info-label {
            color: #666;
            margin-bottom: 5px;
            font-size: 12px;
        }
        
        .info-value {
            font-weight: 500;
            color: #333;
            font-size: 16px;
        }
        
        .schedule-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 3px;
            margin-bottom: 3px;
        }
        
        .time-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #d4edda;
            color: #155724;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 3px;
            margin-bottom: 3px;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }
        
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .instructions {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #0d47a1;
        }
        
        .route-path {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
        }
        
        .path-stop {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .path-start {
            background: #4CAF50;
            color: white;
        }
        
        .path-middle {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .path-end {
            background: #f44336;
            color: white;
        }
        
        .path-arrow {
            color: #666;
            font-size: 10px;
        }
        
        @media (max-width: 1200px) {
            .manage-routes-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .routes-table {
                font-size: 12px;
            }
            
            .routes-table th,
            .routes-table td {
                padding: 8px 5px;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stop-item {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .route-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-logo">
                <img src="../assets/mmuShuttleLogo2.png" alt="Logo" class="logo-icon">
                <span class="logo-text">Campus Shuttle Admin</span>
            </div>
            <div class="admin-profile">
                <img src="../assets/mmuShuttleLogo2.png" alt="Admin" class="profile-pic">
                <div class="user-badge">
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </div>
                <div class="profile-menu">
                    <button class="logout-btn" onclick="window.location.href='../logout.php'">Logout</button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="manage-routes-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><?php echo $edit_mode ? 'Edit Route' : 'Route Management'; ?></h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : ($message_type == 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Route Form Section -->
        <div class="section">
            <div class="form-header">
                <h2 class="section-title"><?php echo $edit_mode ? 'Edit Route' : 'Create New Route'; ?></h2>
                <?php if ($edit_mode): ?>
                    <button class="btn btn-secondary" onclick="cancelEdit()">
                        <i class="fas fa-times"></i> Cancel Edit
                    </button>
                <?php endif; ?>
            </div>
            
            <?php if (!$edit_mode): ?>
                <div class="instructions">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Instructions:</strong> Fill in the route details, add departure times, and stops along the route. 
                    Each route can have multiple departure times throughout the day.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="routeForm">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="action" value="update_route">
                    <input type="hidden" name="route_id" value="<?php echo $route_details['Route_ID']; ?>">
                <?php else: ?>
                    <input type="hidden" name="action" value="create_route">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="route_name">Route Name *</label>
                        <input type="text" id="route_name" name="route_name" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($route_details['Route_Name']) : ''; ?>" 
                               placeholder="e.g., Main Gate to Library" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="status">Status *</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Active" <?php echo $edit_mode && $route_details['Status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $edit_mode && $route_details['Status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="start_location">Start Location *</label>
                        <input type="text" id="start_location" name="start_location" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($route_details['Start_Location']) : ''; ?>" 
                               placeholder="e.g., Main Gate" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="end_location">End Location *</label>
                        <input type="text" id="end_location" name="end_location" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($route_details['End_Location']) : ''; ?>" 
                               placeholder="e.g., Library" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="estimated_duration">Estimated Duration (minutes) *</label>
                        <input type="number" id="estimated_duration" name="estimated_duration" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($route_details['Estimated_Duration_Minutes']) : '15'; ?>" 
                               min="1" max="300" required>
                    </div>
                </div>
                
                <!-- Departure Times Section -->
                <div class="stops-container">
                    <div class="stops-header">
                        <h3 class="stops-title">Departure Times</h3>
                        <button type="button" class="add-stop-btn" onclick="addDepartureTime()">
                            <i class="fas fa-plus"></i> Add Departure Time
                        </button>
                    </div>
                    
                    <div class="stops-list" id="departureTimesList">
                        <?php if ($edit_mode && !empty($route_times)): ?>
                            <?php foreach ($route_times as $time): ?>
                                <div class="stop-item">
                                    <div>
                                        <div class="stop-label">Departure Time</div>
                                        <input type="time" name="departure_time[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($time['Departure_Time']); ?>" 
                                               required>
                                    </div>
                                    <div>
                                        <button type="button" class="remove-stop" onclick="removeDepartureTime(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Default departure times -->
                            <div class="stop-item">
                                <div>
                                    <div class="stop-label">Departure Time</div>
                                    <input type="time" name="departure_time[]" class="form-control" 
                                           value="08:00" required>
                                </div>
                                <div>
                                    <button type="button" class="remove-stop" onclick="removeDepartureTime(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="stop-item">
                                <div>
                                    <div class="stop-label">Departure Time</div>
                                    <input type="time" name="departure_time[]" class="form-control" 
                                           value="10:00" required>
                                </div>
                                <div>
                                    <button type="button" class="remove-stop" onclick="removeDepartureTime(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Route Stops Section -->
                <div class="stops-container">
                    <div class="stops-header">
                        <h3 class="stops-title">Route Stops</h3>
                        <button type="button" class="add-stop-btn" onclick="addStop()">
                            <i class="fas fa-plus"></i> Add Stop
                        </button>
                    </div>
                    
                    <div class="stops-list" id="stopsList">
                        <?php if ($edit_mode && !empty($route_stops)): ?>
                            <?php foreach ($route_stops as $index => $stop): ?>
                                <div class="stop-item">
                                    <div>
                                        <div class="stop-label">Stop Name</div>
                                        <input type="text" name="stop_name[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($stop['Stop_Name']); ?>" 
                                               placeholder="e.g., Student Center" required>
                                    </div>
                                    <div>
                                        <div class="stop-label">Stop Order</div>
                                        <input type="number" name="stop_order[]" class="form-control" 
                                               value="<?php echo $stop['Stop_Order']; ?>" min="1" required>
                                    </div>
                                    <div>
                                        <div class="stop-label">Est. Time From Start (min)</div>
                                        <input type="number" name="estimated_time[]" class="form-control" 
                                               value="<?php echo $stop['Estimated_Time_From_Start']; ?>" min="0" required>
                                    </div>
                                    <div>
                                        <button type="button" class="remove-stop" onclick="removeStop(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Default first stop (Start Location) -->
                            <div class="stop-item">
                                <div>
                                    <div class="stop-label">Stop Name</div>
                                    <input type="text" name="stop_name[]" class="form-control" 
                                           value="Start Point" placeholder="e.g., Main Gate" required readonly>
                                    <small style="color: #666; font-size: 12px;">Automatically set as start location</small>
                                </div>
                                <div>
                                    <div class="stop-label">Stop Order</div>
                                    <input type="number" name="stop_order[]" class="form-control" value="1" min="1" required readonly>
                                </div>
                                <div>
                                    <div class="stop-label">Est. Time From Start (min)</div>
                                    <input type="number" name="estimated_time[]" class="form-control" value="0" min="0" required readonly>
                                </div>
                                <div>
                                    <button type="button" class="remove-stop" disabled>
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="btn-group" style="margin-top: 20px;">
                    <button type="submit" class="btn">
                        <i class="fas fa-<?php echo $edit_mode ? 'save' : 'plus-circle'; ?>"></i>
                        <?php echo $edit_mode ? 'Update Route' : 'Create Route'; ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Routes List Section -->
        <div class="section">
            <h2 class="section-title">Existing Routes</h2>
            
            <?php if (empty($routes)): ?>
                <div class="no-data">No routes found in the system. Create your first route above.</div>
            <?php else: ?>
                <div class="table-container">
                    <table class="routes-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">ID</th>
                                <th style="width: 150px;">Route Name</th>
                                <th style="width: 150px;">Start → End</th>
                                <th style="min-width: 300px;">Route Path</th>
                                <th style="width: 80px;">Duration</th>
                                <th style="width: 120px;">Times</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routes as $route): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($route['Route_ID']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($route['Route_Name']); ?></strong>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; color: #4CAF50;">
                                            <?php echo htmlspecialchars($route['Start_Location']); ?>
                                        </div>
                                        <div style="font-size: 10px; color: #666; text-align: center;">
                                            <i class="fas fa-arrow-down"></i>
                                        </div>
                                        <div style="font-weight: 500; color: #f44336;">
                                            <?php echo htmlspecialchars($route['End_Location']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        // 获取停站点路径
                                        $stop_sql = "SELECT Stop_Name FROM route_stops WHERE Route_ID = ? ORDER BY Stop_Order";
                                        $stop_stmt = $conn->prepare($stop_sql);
                                        $stop_stmt->bind_param("i", $route['Route_ID']);
                                        $stop_stmt->execute();
                                        $stop_result = $stop_stmt->get_result();
                                        $stops = $stop_result->fetch_all(MYSQLI_ASSOC);
                                        
                                        if (!empty($stops)) {
                                            echo '<div class="route-path">';
                                            foreach ($stops as $index => $stop) {
                                                $stop_name = htmlspecialchars($stop['Stop_Name']);
                                                
                                                // 确定样式
                                                if ($index === 0) {
                                                    echo '<span class="path-stop path-start" title="Start Point">';
                                                    echo '<i class="fas fa-play-circle" style="margin-right: 3px;"></i>';
                                                    echo $stop_name;
                                                    echo '</span>';
                                                } elseif ($index === count($stops) - 1) {
                                                    echo '<span class="path-stop path-end" title="End Point">';
                                                    echo '<i class="fas fa-flag-checkered" style="margin-right: 3px;"></i>';
                                                    echo $stop_name;
                                                    echo '</span>';
                                                } else {
                                                    echo '<span class="path-stop path-middle">';
                                                    echo ($index + 1) . '. ' . $stop_name;
                                                    echo '</span>';
                                                }
                                                
                                                // 如果不是最后一个，添加箭头
                                                if ($index < count($stops) - 1) {
                                                    echo '<i class="fas fa-arrow-right path-arrow"></i>';
                                                }
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span style="color: #6c757d; font-style: italic;">No stops defined</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold;"><?php echo $route['Estimated_Duration_Minutes']; ?></span> min
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($route['Departure_Times'])) {
                                            $times = explode(',', $route['Departure_Times']);
                                            $display_times = [];
                                            foreach ($times as $time) {
                                                $dt = new DateTime($time);
                                                $display_times[] = $dt->format('H:i');
                                            }
                                            $unique_times = array_unique($display_times);
                                            sort($unique_times);
                                            
                                            echo '<div style="display: flex; flex-wrap: wrap; gap: 2px;">';
                                            foreach ($unique_times as $time) {
                                                echo '<span class="time-badge">' . $time . '</span>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span style="color: #6c757d; font-style: italic;">No times</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($route['Status']); ?>">
                                            <?php echo htmlspecialchars($route['Status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="route-actions">
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="edit_route">
                                                <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                <button type="submit" class="action-btn edit-btn" title="Edit Route">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                            </form>
                                            
                                            <?php if ($route['Status'] == 'Active'): ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="deactivate_route">
                                                    <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                    <button type="submit" class="action-btn deactivate-btn" title="Deactivate Route">
                                                        <i class="fas fa-pause"></i> Off
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="activate_route">
                                                    <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                    <button type="submit" class="action-btn activate-btn" title="Activate Route">
                                                        <i class="fas fa-play"></i> On
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" action="" style="display: inline;" 
                                                onsubmit="return confirmDelete(<?php echo $route['Route_ID']; ?>, '<?php echo htmlspecialchars(addslashes($route['Route_Name'])); ?>', <?php echo $route['Active_Schedules']; ?>)">
                                                <input type="hidden" name="action" value="delete_route">
                                                <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                <button type="submit" class="action-btn delete-btn" <?php echo $route['Active_Schedules'] > 0 ? 'disabled' : ''; ?>
                                                        title="<?php echo $route['Active_Schedules'] > 0 ? 'Cannot delete: Has active schedules' : 'Delete Route'; ?>">
                                                    <i class="fas fa-trash"></i> Del
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Route Statistics -->
                <div class="route-info-card">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Total Routes</div>
                            <div class="info-value"><?php echo count($routes); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Active Routes</div>
                            <div class="info-value">
                                <?php 
                                $active_count = array_reduce($routes, function($carry, $route) {
                                    return $carry + ($route['Status'] == 'Active' ? 1 : 0);
                                }, 0);
                                echo $active_count;
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Stops</div>
                            <div class="info-value">
                                <?php 
                                $total_stops = array_reduce($routes, function($carry, $route) {
                                    return $carry + $route['Stop_Count'];
                                }, 0);
                                echo $total_stops;
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Departure Times</div>
                            <div class="info-value">
                                <?php 
                                $total_times = 0;
                                foreach ($routes as $route) {
                                    if (!empty($route['Departure_Times'])) {
                                        $times = explode(',', $route['Departure_Times']);
                                        $total_times += count($times);
                                    }
                                }
                                echo $total_times;
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Add new stop field
        let stopCounter = <?php echo $edit_mode && !empty($route_stops) ? count($route_stops) : 1; ?>;
        
        // Add new departure time field
        function addDepartureTime() {
            const timesList = document.getElementById('departureTimesList');
            const timeItem = document.createElement('div');
            timeItem.className = 'stop-item';
            timeItem.innerHTML = `
                <div>
                    <div class="stop-label">Departure Time</div>
                    <input type="time" name="departure_time[]" class="form-control" 
                           value="12:00" required>
                </div>
                <div>
                    <button type="button" class="remove-stop" onclick="removeDepartureTime(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            timesList.appendChild(timeItem);
        }
        
        // Remove departure time field
        function removeDepartureTime(button) {
            const timeItem = button.closest('.stop-item');
            if (timeItem) {
                // Only remove if there's more than one time
                const allTimes = document.querySelectorAll('#departureTimesList .stop-item');
                if (allTimes.length > 1) {
                    timeItem.remove();
                } else {
                    alert('At least one departure time is required.');
                }
            }
        }
        
        function addStop() {
            stopCounter++;
            const stopsList = document.getElementById('stopsList');
            const stopItem = document.createElement('div');
            stopItem.className = 'stop-item';
            stopItem.innerHTML = `
                <div>
                    <div class="stop-label">Stop Name</div>
                    <input type="text" name="stop_name[]" class="form-control" placeholder="e.g., Faculty of Computing" required>
                </div>
                <div>
                    <div class="stop-label">Stop Order</div>
                    <input type="number" name="stop_order[]" class="form-control" value="${stopCounter}" min="1" required>
                </div>
                <div>
                    <div class="stop-label">Est. Time From Start (min)</div>
                    <input type="number" name="estimated_time[]" class="form-control" value="5" min="0" required>
                </div>
                <div>
                    <button type="button" class="remove-stop" onclick="removeStop(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            stopsList.appendChild(stopItem);
        }
        
        // Remove stop field
        function removeStop(button) {
            const stopItem = button.closest('.stop-item');
            if (stopItem) {
                stopItem.remove();
                // Recalculate stop orders
                updateStopOrders();
            }
        }
        
        // Update stop order numbers
        function updateStopOrders() {
            const stopItems = document.querySelectorAll('.stop-item');
            stopItems.forEach((item, index) => {
                const orderInput = item.querySelector('input[name="stop_order[]"]');
                if (orderInput && !orderInput.readOnly) {
                    orderInput.value = index + 1;
                }
            });
            stopCounter = stopItems.length;
        }
        
        // Cancel edit mode
        function cancelEdit() {
            window.location.href = 'manageRoutePage.php';
        }
        
        // Confirm delete action
        function confirmDelete(routeId, routeName, activeSchedules) {
            if (activeSchedules > 0) {
                alert(`Cannot delete route "${routeName}".\n\nThere are ${activeSchedules} active schedule(s) assigned to this route.\nPlease reassign or cancel the schedules first.`);
                return false;
            }
            
            return confirm(`Are you sure you want to delete route "${routeName}" (ID: ${routeId})?\n\nThis action will also delete all associated stops, departure times, and cannot be undone.`);
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('routeForm');
            form.addEventListener('submit', function(e) {
                // Validate departure times
                const departureTimes = document.querySelectorAll('input[name="departure_time[]"]');
                let hasError = false;
                
                // Check for duplicate times
                const times = [];
                departureTimes.forEach(input => {
                    const time = input.value.trim();
                    if (times.includes(time)) {
                        alert('Duplicate departure time detected! Each departure time must be unique.');
                        input.focus();
                        hasError = true;
                    } else if (time) {
                        times.push(time);
                    }
                });
                
                // Check for empty times
                departureTimes.forEach(input => {
                    if (!input.value.trim()) {
                        alert('Please fill in all departure times.');
                        input.focus();
                        hasError = true;
                    }
                });
                
                // Sort times and validate order
                const sortedTimes = [...times].sort();
                if (JSON.stringify(times) !== JSON.stringify(sortedTimes)) {
                    alert('Departure times should be in chronological order. Please arrange them from earliest to latest.');
                    hasError = true;
                }
                
                // Validate stop inputs
                const stopNames = document.querySelectorAll('input[name="stop_name[]"]');
                
                // Check for duplicate stop orders
                const stopOrders = [];
                document.querySelectorAll('input[name="stop_order[]"]').forEach(input => {
                    const order = parseInt(input.value);
                    if (stopOrders.includes(order)) {
                        alert('Duplicate stop order detected! Each stop must have a unique order number.');
                        input.focus();
                        hasError = true;
                    } else {
                        stopOrders.push(order);
                    }
                });
                
                // Validate stop names
                stopNames.forEach((input, index) => {
                    if (index > 0 && !input.value.trim()) { // Skip first stop (start point)
                        alert('Please fill in all stop names.');
                        input.focus();
                        hasError = true;
                    }
                });
                
                if (hasError) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // Auto-hide messages after 5 seconds
            const alert = document.querySelector('.alert');
            if (alert) {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            }
            
            // Auto-populate start location as first stop
            const startLocationInput = document.getElementById('start_location');
            const firstStopName = document.querySelector('input[name="stop_name[]"]');
            
            if (startLocationInput && firstStopName && firstStopName.value === 'Start Point') {
                startLocationInput.addEventListener('input', function() {
                    firstStopName.value = this.value || 'Start Point';
                });
            }
            
            // Update stop order when removing stops
            document.querySelectorAll('.remove-stop').forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(updateStopOrders, 100);
                });
            });
        });
    </script>
</body>
</html>