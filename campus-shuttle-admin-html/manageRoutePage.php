<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = '';
$routes = [];
$route_details = null;
$route_stops = [];
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
                                $stop_order = intval($stop_orders[$i]);
                                $estimated_time = intval($estimated_times[$i]);
                                
                                if (!empty($stop_name)) {
                                    $stop_stmt->bind_param("isii", $route_id, $stop_name, $stop_order, $estimated_time);
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
                break;
                
            case 'update_route':
                // Update existing route
                $route_id = $_POST['route_id'];
                $route_name = trim($_POST['route_name']);
                $start_location = trim($_POST['start_location']);
                $end_location = trim($_POST['end_location']);
                $estimated_duration = intval($_POST['estimated_duration']);
                $status = $_POST['status'];
                
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
                                $stop_order = intval($stop_orders[$i]);
                                $estimated_time = intval($estimated_times[$i]);
                                
                                if (!empty($stop_name)) {
                                    $stop_stmt->bind_param("isii", $route_id, $stop_name, $stop_order, $estimated_time);
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

// Get all routes with stop counts
$routes_sql = "SELECT r.*, 
               COUNT(rs.Stop_ID) as Stop_Count,
               (SELECT COUNT(*) FROM shuttle_schedule s WHERE s.Route_ID = r.Route_ID AND s.Status IN ('Scheduled', 'In Progress')) as Active_Schedules
               FROM route r
               LEFT JOIN route_stops rs ON r.Route_ID = rs.Route_ID
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
            max-width: 1400px;
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
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .routes-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .routes-table th,
        .routes-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
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
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
        
        .view-btn {
            background: #17a2b8;
            color: white;
        }
        
        .view-btn:hover {
            background: #138496;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 12px;
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
        }
        
        .info-value {
            font-weight: 500;
            color: #333;
        }
        
        .schedule-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 10px;
            font-size: 12px;
            margin-right: 5px;
            margin-bottom: 5px;
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
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 25px;
            border-radius: 10px;
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-title {
            color: #333;
            font-size: 22px;
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: #aaa;
            cursor: pointer;
            line-height: 1;
        }
        
        .close-modal:hover {
            color: #333;
        }
        
        .stops-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .stops-table th,
        .stops-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .stops-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
        }
        
        .stops-table tr:hover {
            background: #f8f9fa;
        }
        
        .stop-order-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            text-align: center;
            background: #4CAF50;
            color: white;
            border-radius: 50%;
            font-weight: bold;
        }
        
        .route-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }
        
        .summary-item {
            font-size: 14px;
        }
        
        .summary-label {
            color: #666;
            font-size: 12px;
        }
        
        .summary-value {
            font-weight: 600;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .manage-routes-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stop-item {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .routes-table th,
            .routes-table td {
                padding: 10px 5px;
                font-size: 12px;
            }
            
            .route-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 20px;
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
                    <strong>Instructions:</strong> Fill in the route details and add stops along the route. 
                    The Estimated Time From Start should be in minutes from the starting point.
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
                                <th>ID</th>
                                <th>Route Name</th>
                                <th>Start → End</th>
                                <th>Stops</th>
                                <th>Duration</th>
                                <th>Schedules</th>
                                <th>Status</th>
                                <th>Actions</th>
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
                                        <?php echo htmlspecialchars($route['Start_Location']); ?> 
                                        → 
                                        <?php echo htmlspecialchars($route['End_Location']); ?>
                                    </td>
                                    <td><?php echo $route['Stop_Count'] ?: '0'; ?></td>
                                    <td><?php echo $route['Estimated_Duration_Minutes']; ?> min</td>
                                    <td>
                                        <?php 
                                        $sched_count = isset($schedule_counts[$route['Route_ID']]) ? $schedule_counts[$route['Route_ID']] : 0;
                                        if ($sched_count > 0) {
                                            echo '<span class="schedule-badge">' . $sched_count . ' schedule' . ($sched_count != 1 ? 's' : '') . '</span>';
                                        } else {
                                            echo '<span style="color: #6c757d; font-style: italic;">No schedules</span>';
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
                                                <button type="submit" class="action-btn edit-btn">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="action-btn view-btn" onclick="viewRouteStops(<?php echo $route['Route_ID']; ?>)">
                                                <i class="fas fa-eye"></i> View Stops
                                            </button>
                                            
                                            <?php if ($route['Status'] == 'Active'): ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="deactivate_route">
                                                    <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                    <button type="submit" class="action-btn deactivate-btn">
                                                        <i class="fas fa-pause"></i> Deactivate
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="activate_route">
                                                    <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                    <button type="submit" class="action-btn activate-btn">
                                                        <i class="fas fa-play"></i> Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" action="" style="display: inline;" 
                                                  onsubmit="return confirmDelete(<?php echo $route['Route_ID']; ?>, '<?php echo htmlspecialchars(addslashes($route['Route_Name'])); ?>', <?php echo $route['Active_Schedules']; ?>)">
                                                <input type="hidden" name="action" value="delete_route">
                                                <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                                <button type="submit" class="action-btn delete-btn" <?php echo $route['Active_Schedules'] > 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i> Delete
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
                <div class="route-info-card" style="margin-top: 20px;">
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
                            <div class="info-label">Average Duration</div>
                            <div class="info-value">
                                <?php 
                                $avg_duration = count($routes) > 0 ? 
                                    array_sum(array_column($routes, 'Estimated_Duration_Minutes')) / count($routes) : 0;
                                echo round($avg_duration, 1) . ' min';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal for Viewing Route Stops -->
    <div id="stopsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalRouteTitle">Route Stops</h3>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <div id="modalRouteSummary" class="route-summary"></div>
            
            <div id="stopsContent">
                <!-- Stops will be loaded here via AJAX -->
            </div>
        </div>
    </div>
    
    <script>
        // Add new stop field
        let stopCounter = <?php echo $edit_mode && !empty($route_stops) ? count($route_stops) : 1; ?>;
        
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
            window.location.href = 'manage_routes.php';
        }
        
        // Confirm delete action
        function confirmDelete(routeId, routeName, activeSchedules) {
            if (activeSchedules > 0) {
                alert(`Cannot delete route "${routeName}".\n\nThere are ${activeSchedules} active schedule(s) assigned to this route.\nPlease reassign or cancel the schedules first.`);
                return false;
            }
            
            return confirm(`Are you sure you want to delete route "${routeName}" (ID: ${routeId})?\n\nThis action will also delete all associated stops and cannot be undone.`);
        }
        
        // View route stops modal
        function viewRouteStops(routeId) {
            // Show loading state
            document.getElementById('stopsContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #4CAF50;"></i>
                    <p style="margin-top: 15px; color: #666;">Loading route stops...</p>
                </div>
            `;
            
            // Fetch route details and stops via AJAX
            fetch(`get_route_stops.php?route_id=${routeId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('stopsContent').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle fa-2x"></i>
                                <p style="margin-top: 15px;">${data.error}</p>
                            </div>
                        `;
                        return;
                    }
                    
                    // Update modal title
                    document.getElementById('modalRouteTitle').textContent = `Stops for: ${data.route.Route_Name}`;
                    
                    // Update route summary
                    document.getElementById('modalRouteSummary').innerHTML = `
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Route ID</div>
                                <div class="summary-value">${data.route.Route_ID}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Start Location</div>
                                <div class="summary-value">${data.route.Start_Location}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">End Location</div>
                                <div class="summary-value">${data.route.End_Location}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Stops</div>
                                <div class="summary-value">${data.stops.length}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Duration</div>
                                <div class="summary-value">${data.route.Estimated_Duration_Minutes} minutes</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Status</div>
                                <div class="summary-value">
                                    <span class="status-badge status-${data.route.Status.toLowerCase()}">
                                        ${data.route.Status}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Update stops table
                    if (data.stops.length > 0) {
                        let stopsHTML = `
                            <table class="stops-table">
                                <thead>
                                    <tr>
                                        <th>Stop Order</th>
                                        <th>Stop Name</th>
                                        <th>Estimated Time (minutes from start)</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                        `;
                        
                        data.stops.forEach((stop, index) => {
                            const progressPercentage = (stop.Estimated_Time_From_Start / data.route.Estimated_Duration_Minutes * 100).toFixed(1);
                            stopsHTML += `
                                <tr>
                                    <td>
                                        <span class="stop-order-badge">${stop.Stop_Order}</span>
                                    </td>
                                    <td>
                                        <strong>${stop.Stop_Name}</strong>
                                        ${stop.Stop_Order === 1 ? '<br><small style="color: #666;">(Start Point)</small>' : ''}
                                        ${stop.Stop_Order === data.stops.length ? '<br><small style="color: #666;">(End Point)</small>' : ''}
                                    </td>
                                    <td>${stop.Estimated_Time_From_Start} minutes</td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex-grow: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                                <div style="width: ${progressPercentage}%; height: 100%; background: #4CAF50; border-radius: 4px;"></div>
                                            </div>
                                            <span style="font-size: 12px; color: #666;">${progressPercentage}%</span>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        stopsHTML += `
                                </tbody>
                            </table>
                        `;
                        
                        document.getElementById('stopsContent').innerHTML = stopsHTML;
                    } else {
                        document.getElementById('stopsContent').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #6c757d;">
                                <i class="fas fa-info-circle fa-2x"></i>
                                <p style="margin-top: 15px;">No stops found for this route.</p>
                                <p style="font-size: 14px; margin-top: 10px;">Add stops using the Edit button.</p>
                            </div>
                        `;
                    }
                    
                    // Show modal
                    document.getElementById('stopsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('stopsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-circle fa-2x"></i>
                            <p style="margin-top: 15px;">Error loading route stops. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('stopsModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('stopsModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('routeForm');
            form.addEventListener('submit', function(e) {
                // Validate stop inputs
                const stopNames = document.querySelectorAll('input[name="stop_name[]"]');
                let hasError = false;
                
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