<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Transport Coordinator
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

// Handle form submission for assigning single schedule
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $driver_id = $_POST['driver_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $route_id = $_POST['route_id'];
    $schedule_date = $_POST['schedule_date'];
    $departure_times = $_POST['departure_times'];
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Get route duration
        $route_info = $conn->query("
            SELECT Estimated_Duration_Minutes 
            FROM route 
            WHERE Route_ID = $route_id
        ")->fetch_assoc();
        
        $duration_minutes = $route_info['Estimated_Duration_Minutes'] ?? 15;
        $schedules_created = 0;
        
        // Create schedule for each departure time
        foreach($departure_times as $departure_time) {
            $departure_datetime = $schedule_date . ' ' . $departure_time . ':00';
            
            $expected_arrival = date('Y-m-d H:i:s', 
                strtotime($departure_datetime . " +{$duration_minutes} minutes"));
            
            // Check vehicle capacity
            $vehicle_info = $conn->query("
                SELECT Capacity 
                FROM vehicle 
                WHERE Vehicle_ID = $vehicle_id
            ")->fetch_assoc();
            
            $available_seats = $vehicle_info['Capacity'] ?? 30;
            
            // Insert schedule
            $insert_sql = "INSERT INTO shuttle_schedule 
                          (Vehicle_ID, Route_ID, Driver_ID, Departure_time, 
                           Expected_Arrival, Status, Available_Seats) 
                          VALUES (?, ?, ?, ?, ?, 'Scheduled', ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("iiissi", 
                $vehicle_id, $route_id, $driver_id, $departure_datetime, 
                $expected_arrival, $available_seats);
            
            if($stmt->execute()) {
                $schedules_created++;
            }
        }
        
        $conn->commit();
        $success_message = "Successfully created $schedules_created schedule(s) for the driver!";
    } catch(Exception $e) {
        $conn->rollback();
        $error_message = "Error creating schedules: " . $e->getMessage();
    }
}

// Get all drivers with profile information
$drivers = $conn->query("
    SELECT u.User_ID, u.Full_Name, u.Email, u.Username,
           dp.License_Number, dp.License_Expiry, dp.Phone, dp.Assigned_Vehicle,
           r.Role_name as Role_Name
    FROM user u
    LEFT JOIN driver_profile dp ON u.User_ID = dp.User_ID
    LEFT JOIN user_roles ur ON u.User_ID = ur.User_ID
    LEFT JOIN roles r ON ur.Role_ID = r.Role_ID
    WHERE r.Role_name = 'Driver'
    ORDER BY u.Full_Name
")->fetch_all(MYSQLI_ASSOC);

// Get all vehicles
$vehicles = $conn->query("
    SELECT Vehicle_ID, Plate_number, Model, Capacity, Status
    FROM vehicle 
    WHERE Status = 'Active'
    ORDER BY Plate_number
")->fetch_all(MYSQLI_ASSOC);

// Get all routes with correct column names
$routes = $conn->query("
    SELECT r.Route_ID, r.Route_Name, r.Start_Location, r.End_Location, 
           r.Estimated_Duration_Minutes, r.Total_Stops, r.Status,
           GROUP_CONCAT(rt.Departure_Time ORDER BY rt.Departure_Time SEPARATOR ', ') as route_times
    FROM route r
    LEFT JOIN route_time rt ON r.Route_ID = rt.Route_ID
    WHERE r.Status = 'Active'
    GROUP BY r.Route_ID
    ORDER BY r.Route_Name
")->fetch_all(MYSQLI_ASSOC);

// Get current driver schedules
$current_schedules = [];
if(isset($_GET['driver_id'])) {
    $driver_id = $_GET['driver_id'];
    $current_schedules = $conn->query("
        SELECT ss.*, r.Route_Name, v.Plate_number, v.Model,
               DATE(ss.Departure_time) as schedule_date,
               TIME(ss.Departure_time) as schedule_time
        FROM shuttle_schedule ss
        JOIN route r ON ss.Route_ID = r.Route_ID
        JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
        WHERE ss.Driver_ID = $driver_id
        AND ss.Departure_time >= CURDATE()
        AND ss.Status = 'Scheduled'
        ORDER BY ss.Departure_time
        LIMIT 20
    ")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Driver Schedule - Coordinator Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f5f5 0%, #e8eaf6 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            padding: 0 20px;
            height: 70px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        
        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .navbar-logo {
            display: flex;
            align-items: center;
        }
        
        .logo-icon {
            height: 40px;
            width: auto;
        }
        
        .nav-title {
            font-size: 18px;
            font-weight: 600;
            color: #9C27B0;
            margin-left: 15px;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #f0f0f0;
        }
        
        .user-badge {
            background: #4CAF50;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .back-btn {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .back-btn:hover {
            background: #7B1FA2;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
        }
        
        .main-container {
            padding: 30px;
            max-width: 1400px;
            margin: 100px auto 30px;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto 30px;
            line-height: 1.6;
        }
        
        .dashboard-grid {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            margin-bottom: 40px;
        }
        
        .form-section {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .form-title {
            font-size: 28px;
            color: #333;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #9C27B0;
            text-align: center;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .form-group {
            margin-bottom: 30px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f9f9f9;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #9C27B0;
            background: white;
            box-shadow: 0 0 0 4px rgba(156, 39, 176, 0.1);
        }
        
        .select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .select-card {
            padding: 25px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: left;
            position: relative;
            background: white;
        }
        
        .select-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-color: #9C27B0;
        }
        
        .select-card.selected {
            border-color: #9C27B0;
            background: linear-gradient(135deg, rgba(156, 39, 176, 0.08) 0%, rgba(156, 39, 176, 0.15) 100%);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(156, 39, 176, 0.2);
        }
        
        .card-title {
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 10px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-details {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }
        
        .time-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .time-input {
            position: relative;
        }
        
        .time-input input {
            padding-right: 40px;
        }
        
        .remove-time {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: #f44336;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .remove-time:hover {
            background: #d32f2f;
            transform: translateY(-50%) scale(1.1);
        }
        
        .add-time-btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .add-time-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #9C27B0 0%, #7B1FA2 100%);
            color: white;
            border: none;
            padding: 18px 60px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-block;
            text-align: center;
            letter-spacing: 0.5px;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(156, 39, 176, 0.4);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-weight: 500;
            text-align: center;
            font-size: 16px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 2px solid #b1dfbb;
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.2);
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 2px solid #f1b0b7;
            box-shadow: 0 5px 15px rgba(244, 67, 54, 0.2);
        }
        
        .date-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            background: #f9f9f9;
            transition: all 0.3s;
        }
        
        .date-input:focus {
            outline: none;
            border-color: #9C27B0;
            background: white;
            box-shadow: 0 0 0 4px rgba(156, 39, 176, 0.1);
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-4 {
            margin-top: 40px;
        }
        
        .mb-3 {
            margin-bottom: 15px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 2px solid #90caf9;
            border-radius: 12px;
            padding: 25px;
            margin-top: 30px;
        }
        
        .summary-title {
            font-weight: 700;
            color: #1976d2;
            margin-bottom: 20px;
            font-size: 18px;
            text-align: center;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(25, 118, 210, 0.2);
        }
        
        .summary-item:last-child {
            border-bottom: none;
            font-weight: 700;
            color: #333;
            font-size: 16px;
            padding-top: 8px;
        }
        
        .driver-status {
            margin-top: 12px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            display: inline-block;
            font-weight: 600;
        }
        
        .status-license-valid {
            background: #4CAF50;
            color: white;
        }
        
        .status-license-expired {
            background: #F44336;
            color: white;
        }
        
        .status-license-unknown {
            background: #9E9E9E;
            color: white;
        }
        
        .role-badge {
            background: #2196F3;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-left: 10px;
            font-weight: 600;
        }
        
        .route-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #4CAF50;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .predefined-times {
            font-size: 13px;
            color: #666;
            margin-top: 12px;
            padding: 8px;
            background: #f0f0f0;
            border-radius: 6px;
            line-height: 1.4;
        }
        
        .copy-times-btn {
            background: linear-gradient(135deg, #2196F3 0%, #0d8bf2 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            margin-top: 12px;
            display: inline-block;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .copy-times-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        
        .required-field::after {
            content: " *";
            color: #f44336;
        }
        
        .form-instruction {
            background: #f8f9fa;
            border-left: 4px solid #9C27B0;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 8px;
            font-size: 14px;
            color: #666;
        }
        
        /* 响应式设计 */
        @media (max-width: 1200px) {
            .form-section {
                max-width: 900px;
            }
            
            .select-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .form-section {
                padding: 40px;
                max-width: 800px;
            }
            
            .select-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .form-section {
                padding: 30px;
                margin: 0 15px;
            }
            
            .form-title {
                font-size: 24px;
                margin-bottom: 30px;
            }
            
            .select-grid {
                grid-template-columns: 1fr;
            }
            
            .time-inputs {
                grid-template-columns: 1fr;
            }
            
            .btn-primary {
                padding: 16px 40px;
                font-size: 16px;
            }
            
            .main-container {
                padding: 20px;
                margin-top: 80px;
            }
            
            .page-title {
                font-size: 28px;
            }
            
            .navbar {
                padding: 0 15px;
            }
        }
        
        @media (max-width: 480px) {
            .form-section {
                padding: 25px 20px;
            }
            
            .page-title {
                font-size: 24px;
            }
            
            .form-title {
                font-size: 22px;
            }
            
            .admin-profile {
                gap: 10px;
            }
            
            .back-btn {
                padding: 6px 15px;
                font-size: 14px;
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
                <span class="nav-title">Assign Driver Schedule</span>
            </div>            
            <div class="admin-profile">
                <img src="../assets/mmuShuttleLogo2.png" alt="Coordinator" class="profile-pic">
                <div class="user-badge">
                    <?php echo $_SESSION['username']; ?> 
                </div>
                <a href="controlPanel.php" class="back-btn">Back to Dashboard</a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="main-container">
        <div class="page-header">
            <h1 class="page-title">Assign Driver Schedule</h1>
            <p class="page-subtitle">Plan and assign shuttle schedules to drivers for specific dates. Select a driver, vehicle, route, and set departure times.</p>
        </div>
        
        <?php if(isset($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error_message)): ?>
            <div class="alert alert-error">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="dashboard-grid">
            <!-- Schedule Assignment Form -->
            <div class="form-section">
                <h2 class="form-title">Create Driver Schedule</h2>
                
                <div class="form-instruction">
                    <strong>Instructions:</strong> Please complete all required fields marked with *. Select one option from each section.
                </div>
                
                <form id="scheduleForm" method="POST" action="">
                    <!-- Driver Selection -->
                    <div class="form-group">
                        <label class="form-label required-field">Select Driver</label>
                        <div class="select-grid" id="driverSelect">
                            <?php if(empty($drivers)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: #999; background: #f8f9fa; border-radius: 10px;">
                                    <strong style="display: block; margin-bottom: 15px; font-size: 18px; color: #666;">No drivers found in the system.</strong>
                                    <div style="text-align: left; max-width: 500px; margin: 0 auto;">
                                        <small>Please ensure you have:
                                            <ol style="margin-top: 15px; padding-left: 20px;">
                                                <li>Users with 'Driver' role in user_roles table</li>
                                                <li>Driver profiles in driver_profile table</li>
                                                <li>'Driver' role exists in roles table</li>
                                            </ol>
                                        </small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach($drivers as $driver): 
                                    // Check license status
                                    $license_status = 'unknown';
                                    $license_status_text = 'No License Info';
                                    if($driver['License_Expiry']) {
                                        $expiry_date = new DateTime($driver['License_Expiry']);
                                        $today = new DateTime();
                                        if($expiry_date < $today) {
                                            $license_status = 'expired';
                                            $license_status_text = 'License Expired';
                                        } else {
                                            $license_status = 'valid';
                                            $license_status_text = 'License Valid';
                                        }
                                    }
                                ?>
                                    <div class="select-card" 
                                         data-driver-id="<?php echo $driver['User_ID']; ?>"
                                         onclick="selectDriver(this, <?php echo $driver['User_ID']; ?>)">
                                        <div class="card-title">
                                            <?php echo $driver['Full_Name']; ?>
                                            <?php if($driver['Role_Name']): ?>
                                                <span class="role-badge"><?php echo $driver['Role_Name']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-details">
                                            <?php if($driver['License_Number']): ?>
                                                <strong>License:</strong> <?php echo $driver['License_Number']; ?><br>
                                            <?php endif; ?>
                                            <?php if($driver['Phone']): ?>
                                                <strong>Phone:</strong> <?php echo $driver['Phone']; ?><br>
                                            <?php endif; ?>
                                            <?php if($driver['Assigned_Vehicle']): ?>
                                                <strong>Assigned Vehicle:</strong> <?php echo $driver['Assigned_Vehicle']; ?><br>
                                            <?php endif; ?>
                                            <?php if($driver['Email']): ?>
                                                <strong>Email:</strong> <?php echo $driver['Email']; ?><br>
                                            <?php endif; ?>
                                            <span class="driver-status status-license-<?php echo $license_status; ?>">
                                                <?php echo $license_status_text; ?>
                                                <?php if($driver['License_Expiry']): ?>
                                                    (Exp: <?php echo date('Y-m-d', strtotime($driver['License_Expiry'])); ?>)
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="driver_id" id="selectedDriver" required>
                    </div>
                    
                    <!-- Vehicle Selection -->
                    <div class="form-group">
                        <label class="form-label required-field">Select Vehicle</label>
                        <div class="select-grid" id="vehicleSelect">
                            <?php if(empty($vehicles)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: #999; background: #f8f9fa; border-radius: 10px;">
                                    No active vehicles found. Please add vehicles first.
                                </div>
                            <?php else: ?>
                                <?php foreach($vehicles as $vehicle): ?>
                                    <div class="select-card" 
                                         data-vehicle-id="<?php echo $vehicle['Vehicle_ID']; ?>"
                                         onclick="selectVehicle(this, <?php echo $vehicle['Vehicle_ID']; ?>)">
                                        <div class="card-title"><?php echo $vehicle['Plate_number']; ?></div>
                                        <div class="card-details">
                                            <strong>Model:</strong> <?php echo $vehicle['Model']; ?><br>
                                            <strong>Capacity:</strong> <?php echo $vehicle['Capacity']; ?> seats<br>
                                            <strong>Status:</strong> <span style="color: #4CAF50; font-weight: 600;"><?php echo $vehicle['Status']; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="vehicle_id" id="selectedVehicle" required>
                    </div>
                    
                    <!-- Route Selection -->
                    <div class="form-group">
                        <label class="form-label required-field">Select Route</label>
                        <div class="select-grid" id="routeSelect">
                            <?php if(empty($routes)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: #999; background: #f8f9fa; border-radius: 10px;">
                                    No active routes found. Please add routes first.
                                </div>
                            <?php else: ?>
                                <?php foreach($routes as $route): ?>
                                    <div class="select-card" 
                                         data-route-id="<?php echo $route['Route_ID']; ?>"
                                         data-route-duration="<?php echo $route['Estimated_Duration_Minutes']; ?>"
                                         data-route-times="<?php echo htmlspecialchars($route['route_times'] ?? ''); ?>"
                                         onclick="selectRoute(this, <?php echo $route['Route_ID']; ?>, <?php echo $route['Estimated_Duration_Minutes']; ?>, '<?php echo htmlspecialchars($route['route_times'] ?? ''); ?>')">
                                        <span class="route-badge">ID: <?php echo $route['Route_ID']; ?></span>
                                        <div class="card-title"><?php echo $route['Route_Name']; ?></div>
                                        <div class="card-details">
                                            <strong>From:</strong> <?php echo $route['Start_Location']; ?><br>
                                            <strong>To:</strong> <?php echo $route['End_Location']; ?><br>
                                            <strong>Duration:</strong> <?php echo $route['Estimated_Duration_Minutes']; ?> minutes<br>
                                            <strong>Stops:</strong> <?php echo $route['Total_Stops']; ?> stops<br>
                                            <?php if($route['route_times']): ?>
                                                <div class="predefined-times">
                                                    <strong>Predefined Times:</strong><br>
                                                    <?php 
                                                    $times = explode(', ', $route['route_times']);
                                                    foreach(array_slice($times, 0, 3) as $time) {
                                                        echo date('H:i', strtotime($time)) . ' ';
                                                    }
                                                    if(count($times) > 3) echo '... (' . count($times) . ' total)';
                                                    ?>
                                                </div>
                                                <button type="button" class="copy-times-btn" 
                                                        onclick="copyPredefinedTimes('<?php echo htmlspecialchars($route['route_times']); ?>')">
                                                    Copy Predefined Times
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="route_id" id="selectedRoute" required>
                    </div>
                    
                    <!-- Schedule Date -->
                    <div class="form-group">
                        <label class="form-label required-field">Schedule Date</label>
                        <input type="date" name="schedule_date" id="scheduleDate" 
                               class="date-input" required 
                               min="<?php echo date('Y-m-d'); ?>"
                               value="<?php echo date('Y-m-d'); ?>"
                               onchange="calculateSummary()">
                        <small style="display: block; margin-top: 8px; color: #666;">Select the date for the schedule</small>
                    </div>
                    
                    <!-- Departure Times -->
                    <div class="form-group">
                        <label class="form-label required-field">Departure Times (24-hour format: HH:MM)</label>
                        <div id="timeInputsContainer" class="time-inputs">
                            <div class="time-input">
                                <input type="time" name="departure_times[]" 
                                       class="form-control" required value="08:00"
                                       onchange="calculateSummary()">
                            </div>
                        </div>
                        <button type="button" class="add-time-btn" onclick="addTimeInput()">
                            <span style="font-size: 18px;">+</span> Add Another Departure Time
                        </button>
                        <small style="display: block; margin-top: 8px; color: #666;">Add multiple times for multiple trips on the same day</small>
                    </div>
                    
                    <!-- Summary -->
                    <div id="summarySection" class="summary-card" style="display: none;">
                        <div class="summary-title">Schedule Summary</div>
                        <div id="summaryContent"></div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="text-center" style="margin-top: 40px;">
                        <button type="submit" class="btn-primary">
                            <span style="margin-right: 10px;">✓</span> Create Schedule
                        </button>
                        <p style="margin-top: 15px; color: #666; font-size: 14px;">
                            Review all selections before submitting
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let selectedDriver = null;
        let selectedVehicle = null;
        let selectedRoute = null;
        let selectedRouteDuration = 15;
        
        function selectDriver(element, driverId) {
            document.querySelectorAll('#driverSelect .select-card').forEach(card => {
                card.classList.remove('selected');
            });
            element.classList.add('selected');
            selectedDriver = driverId;
            document.getElementById('selectedDriver').value = driverId;
            
            // Load driver schedules if needed
            if(typeof loadDriverSchedules === 'function') {
                loadDriverSchedules(driverId);
            }
            
            calculateSummary();
        }
        
        function selectVehicle(element, vehicleId) {
            document.querySelectorAll('#vehicleSelect .select-card').forEach(card => {
                card.classList.remove('selected');
            });
            element.classList.add('selected');
            selectedVehicle = vehicleId;
            document.getElementById('selectedVehicle').value = vehicleId;
            calculateSummary();
        }
        
        function selectRoute(element, routeId, duration, routeTimes) {
            document.querySelectorAll('#routeSelect .select-card').forEach(card => {
                card.classList.remove('selected');
            });
            element.classList.add('selected');
            selectedRoute = routeId;
            selectedRouteDuration = duration || 15;
            document.getElementById('selectedRoute').value = routeId;
            calculateSummary();
            
            // Show notification about predefined times
            if(routeTimes && routeTimes.trim() !== '') {
                const times = routeTimes.split(', ').length;
                console.log(`Route has ${times} predefined departure times available. Click "Copy Predefined Times" to use them.`);
            }
        }
        
        function copyPredefinedTimes(routeTimes) {
            if(!routeTimes || routeTimes.trim() === '') {
                alert('No predefined times available for this route.');
                return;
            }
            
            const times = routeTimes.split(', ');
            const container = document.getElementById('timeInputsContainer');
            
            // Clear existing times
            container.innerHTML = '';
            
            // Add each time
            times.forEach((time, index) => {
                const timeStr = time.substring(0, 5); // Get HH:MM format
                const newInput = document.createElement('div');
                newInput.className = 'time-input';
                newInput.innerHTML = `
                    <input type="time" name="departure_times[]" class="form-control" required value="${timeStr}" onchange="calculateSummary()">
                    ${index > 0 ? '<button type="button" class="remove-time" onclick="removeTimeInput(this)">×</button>' : ''}
                `;
                container.appendChild(newInput);
            });
            
            // Add event listeners to new inputs
            container.querySelectorAll('input[type="time"]').forEach(input => {
                input.addEventListener('change', calculateSummary);
            });
            
            alert(`✓ Copied ${times.length} predefined times for this route.`);
            calculateSummary();
        }
        
        function addTimeInput() {
            const container = document.getElementById('timeInputsContainer');
            const currentTimeInputs = container.querySelectorAll('input[type="time"]');
            
            // Find the latest time and add 1 hour
            let latestTime = '09:00';
            if(currentTimeInputs.length > 0) {
                const lastInput = currentTimeInputs[currentTimeInputs.length - 1];
                const lastValue = lastInput.value;
                if(lastValue) {
                    const [hours, minutes] = lastValue.split(':');
                    const date = new Date();
                    date.setHours(parseInt(hours) + 1, parseInt(minutes));
                    latestTime = date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
                }
            }
            
            const newInput = document.createElement('div');
            newInput.className = 'time-input';
            newInput.innerHTML = `
                <input type="time" name="departure_times[]" class="form-control" required value="${latestTime}" onchange="calculateSummary()">
                <button type="button" class="remove-time" onclick="removeTimeInput(this)">×</button>
            `;
            container.appendChild(newInput);
            
            // Add event listener to new input
            newInput.querySelector('input').addEventListener('change', calculateSummary);
            calculateSummary();
        }
        
        function removeTimeInput(button) {
            const container = document.getElementById('timeInputsContainer');
            if(container.children.length > 1) {
                button.parentElement.remove();
                calculateSummary();
            } else {
                alert('At least one departure time is required.');
            }
        }
        
        function calculateSummary() {
            const scheduleDate = document.getElementById('scheduleDate').value;
            const timeInputs = document.querySelectorAll('input[name="departure_times[]"]');
            const timesCount = timeInputs.length;
            
            // Get all time values
            const timeValues = Array.from(timeInputs).map(input => input.value).filter(value => value);
            
            if(!scheduleDate || !selectedDriver || !selectedVehicle || !selectedRoute) {
                document.getElementById('summarySection').style.display = 'none';
                return;
            }
            
            // Format date
            const date = new Date(scheduleDate);
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Calculate total hours and minutes
            const totalMinutes = timesCount * selectedRouteDuration;
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            
            // Update summary
            document.getElementById('summarySection').style.display = 'block';
            document.getElementById('summaryContent').innerHTML = `
                <div class="summary-item">
                    <span>Schedule Date:</span>
                    <span><strong>${formattedDate}</strong></span>
                </div>
                <div class="summary-item">
                    <span>Number of Trips:</span>
                    <span>${timesCount}</span>
                </div>
                <div class="summary-item">
                    <span>Departure Times:</span>
                    <span>${timeValues.map(time => time.substring(0, 5)).join(', ')}</span>
                </div>
                <div class="summary-item">
                    <span>Route Duration:</span>
                    <span>${selectedRouteDuration} minutes per trip</span>
                </div>
                <div class="summary-item">
                    <span><strong>Total Working Time:</strong></span>
                    <span><strong>${hours > 0 ? hours + 'h ' : ''}${minutes}m</strong></span>
                </div>
            `;
        }
        
        // Auto-select driver from URL if present
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const driverId = urlParams.get('driver_id');
            
            if(driverId) {
                const driverElement = document.querySelector(`[data-driver-id="${driverId}"]`);
                if(driverElement) {
                    selectDriver(driverElement, parseInt(driverId));
                }
            }
            
            // Add event listeners for summary calculation
            document.getElementById('scheduleDate').addEventListener('change', calculateSummary);
            
            // Initialize with one time input
            document.getElementById('timeInputsContainer').querySelector('input').addEventListener('change', calculateSummary);
            
            // Form validation before submit
            document.getElementById('scheduleForm').addEventListener('submit', function(e) {
                const driver = document.getElementById('selectedDriver').value;
                const vehicle = document.getElementById('selectedVehicle').value;
                const route = document.getElementById('selectedRoute').value;
                const date = document.getElementById('scheduleDate').value;
                const timeInputs = document.querySelectorAll('input[name="departure_times[]"]');
                
                let hasEmptyTime = false;
                timeInputs.forEach(input => {
                    if(!input.value) hasEmptyTime = true;
                });
                
                if(!driver || !vehicle || !route || !date || hasEmptyTime) {
                    e.preventDefault();
                    alert('Please complete all required fields before submitting.');
                    return false;
                }
                
                // Show confirmation
                const confirmSubmit = confirm('Are you sure you want to create this schedule?');
                if(!confirmSubmit) {
                    e.preventDefault();
                }
            });
            
            // Initial summary calculation
            setTimeout(calculateSummary, 100);
        });
    </script>
</body>
</html>