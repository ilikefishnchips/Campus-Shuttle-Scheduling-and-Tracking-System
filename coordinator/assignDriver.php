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
            background: #f5f5f5;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .back-btn:hover {
            background: #7B1FA2;
        }
        
        .main-container {
            padding: 30px;
            max-width: 1400px;
            margin: 100px auto 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            color: #333;
        }
        
        .page-subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .form-title {
            font-size: 20px;
            color: #333;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #9C27B0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #9C27B0;
            box-shadow: 0 0 0 2px rgba(156, 39, 176, 0.1);
        }
        
        .select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .select-card {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            position: relative;
        }
        
        .select-card.selected {
            border-color: #9C27B0;
            background: rgba(156, 39, 176, 0.1);
        }
        
        .card-title {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 8px;
            color: #333;
        }
        
        .card-details {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }
        
        .time-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .time-input {
            position: relative;
        }
        
        .time-input input {
            padding-right: 30px;
        }
        
        .remove-time {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #f44336;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .add-time-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
        }
        
        .btn-primary {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: background 0.3s;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary:hover {
            background: #7B1FA2;
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: 500;
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
        
        .schedules-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .schedules-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .schedule-day {
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .day-header {
            background: #f5f5f5;
            padding: 15px;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .day-date {
            font-size: 14px;
            color: #666;
        }
        
        .day-schedules {
            padding: 15px;
        }
        
        .schedule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            background: #f9f9f9;
            margin-bottom: 8px;
            border-radius: 5px;
        }
        
        .schedule-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        
        .schedule-info h4 {
            font-size: 14px;
            margin-bottom: 5px;
            color: #333;
        }
        
        .schedule-details {
            font-size: 12px;
            color: #666;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .schedule-time {
            font-weight: 600;
            color: #9C27B0;
        }
        
        .schedule-route {
            color: #2196F3;
        }
        
        .schedule-vehicle {
            color: #4CAF50;
        }
        
        .remove-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .no-schedules {
            text-align: center;
            color: #999;
            padding: 40px;
            font-style: italic;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
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
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .summary-title {
            font-weight: 600;
            color: #1976d2;
            margin-bottom: 10px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .driver-status {
            margin-top: 8px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            display: inline-block;
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
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .route-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #4CAF50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
        }
        
        .predefined-times {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
            padding: 3px;
            background: #f0f0f0;
            border-radius: 3px;
        }
        
        .copy-times-btn {
            background: #2196F3;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            margin-top: 5px;
            display: inline-block;
        }
        
        .copy-times-btn:hover {
            background: #0d8bf2;
        }
        
        .date-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
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
        </div>
        
        <p class="page-subtitle">Plan and assign shuttle schedules to drivers for specific dates</p>
        
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
                
                <form id="scheduleForm" method="POST" action="">
                    <!-- Driver Selection -->
                    <div class="form-group">
                        <label class="form-label">Select Driver</label>
                        <div class="select-grid" id="driverSelect">
                            <?php if(empty($drivers)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #999;">
                                    <strong>No drivers found in the system.</strong><br>
                                    <small>Please ensure you have:
                                        <ol style="text-align: left; margin-top: 10px;">
                                            <li>Users with 'Driver' role in user_roles table</li>
                                            <li>Driver profiles in driver_profile table</li>
                                            <li>'Driver' role exists in roles table</li>
                                        </ol>
                                    </small>
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
                        <label class="form-label">Select Vehicle</label>
                        <div class="select-grid" id="vehicleSelect">
                            <?php foreach($vehicles as $vehicle): ?>
                                <div class="select-card" 
                                     data-vehicle-id="<?php echo $vehicle['Vehicle_ID']; ?>"
                                     onclick="selectVehicle(this, <?php echo $vehicle['Vehicle_ID']; ?>)">
                                    <div class="card-title"><?php echo $vehicle['Plate_number']; ?></div>
                                    <div class="card-details">
                                        Model: <?php echo $vehicle['Model']; ?><br>
                                        Capacity: <?php echo $vehicle['Capacity']; ?> seats<br>
                                        Status: <?php echo $vehicle['Status']; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="vehicle_id" id="selectedVehicle" required>
                    </div>
                    
                    <!-- Route Selection -->
                    <div class="form-group">
                        <label class="form-label">Select Route</label>
                        <div class="select-grid" id="routeSelect">
                            <?php if(empty($routes)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #999;">
                                    No routes found in the system.
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
                                                    if(count($times) > 3) echo '...';
                                                    ?>
                                                </div>
                                                <button type="button" class="copy-times-btn" 
                                                        onclick="copyPredefinedTimes('<?php echo htmlspecialchars($route['route_times']); ?>')">
                                                    Copy Times
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
                        <label class="form-label">Schedule Date</label>
                        <input type="date" name="schedule_date" id="scheduleDate" 
                               class="date-input" required 
                               min="<?php echo date('Y-m-d'); ?>"
                               value="<?php echo date('Y-m-d'); ?>"
                               onchange="calculateSummary()">
                    </div>
                    
                    <!-- Departure Times -->
                    <div class="form-group">
                        <label class="form-label">Departure Times (HH:MM format)</label>
                        <div id="timeInputsContainer" class="time-inputs">
                            <div class="time-input">
                                <input type="time" name="departure_times[]" 
                                       class="form-control" required value="08:00">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary -->
                    <div id="summarySection" class="summary-card" style="display: none;">
                        <div class="summary-title">Schedule Summary</div>
                        <div id="summaryContent"></div>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn-primary btn-block mt-4">Create Schedule</button>
                </form>
            </div>
            
            <!-- Current Schedules -->
            <div class="schedules-section">
                <h2 class="form-title">Current Driver Schedules</h2>
                
                <div id="schedulesContainer" class="schedules-list">
                    <?php if(isset($_GET['driver_id'])): ?>
                        <?php if(!empty($current_schedules)): 
                            // Group schedules by date
                            $grouped_schedules = [];
                            foreach($current_schedules as $schedule) {
                                $date = $schedule['schedule_date'];
                                $grouped_schedules[$date][] = $schedule;
                            }
                        ?>
                            <?php foreach($grouped_schedules as $date => $schedules): ?>
                                <div class="schedule-day">
                                    <div class="day-header">
                                        <div><?php echo date('l, F j, Y', strtotime($date)); ?></div>
                                        <div class="day-date"><?php echo count($schedules); ?> trips</div>
                                    </div>
                                    <div class="day-schedules">
                                        <?php foreach($schedules as $schedule): ?>
                                            <div class="schedule-item">
                                                <div class="schedule-info">
                                                    <h4><?php echo $schedule['Route_Name']; ?></h4>
                                                    <div class="schedule-details">
                                                        <span class="schedule-time">
                                                            <?php echo date('H:i', strtotime($schedule['schedule_time'])); ?>
                                                        </span>
                                                        <span class="schedule-vehicle">
                                                            <?php echo $schedule['Plate_number']; ?>
                                                        </span>
                                                        <span class="schedule-route">
                                                            Seats: <?php echo $schedule['Available_Seats']; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <button class="remove-btn" 
                                                        onclick="removeSchedule(<?php echo $schedule['Schedule_ID']; ?>)">
                                                    Cancel
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-schedules">
                                No upcoming schedules found for this driver.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="loading">
                            Select a driver to view current schedules
                        </div>
                    <?php endif; ?>
                </div>
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
            
            loadDriverSchedules(driverId);
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
        }
        
        function copyPredefinedTimes(routeTimes) {
            if(!routeTimes) return;
            
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
                    <input type="time" name="departure_times[]" class="form-control" required value="${timeStr}">
                    ${index > 0 ? '<button type="button" class="remove-time" onclick="removeTimeInput(this)">×</button>' : ''}
                `;
                container.appendChild(newInput);
            });
            
            alert(`Copied ${times.length} predefined times for this route.`);
            calculateSummary();
        }
        
        function addTimeInput() {
            const container = document.getElementById('timeInputsContainer');
            const newInput = document.createElement('div');
            newInput.className = 'time-input';
            newInput.innerHTML = `
                <input type="time" name="departure_times[]" class="form-control" required value="09:00">
                <button type="button" class="remove-time" onclick="removeTimeInput(this)">×</button>
            `;
            container.appendChild(newInput);
            calculateSummary();
        }
        
        function removeTimeInput(button) {
            const container = document.getElementById('timeInputsContainer');
            if(container.children.length > 1) {
                button.parentElement.remove();
                calculateSummary();
            }
        }
        
        function loadDriverSchedules(driverId) {
            const container = document.getElementById('schedulesContainer');
            container.innerHTML = '<div class="loading">Loading schedules...</div>';
            
            fetch(`get_driver_schedules.php?driver_id=${driverId}`)
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                })
                .catch(error => {
                    container.innerHTML = '<div class="no-schedules">Error loading schedules</div>';
                });
        }
        
        function removeSchedule(scheduleId) {
            if(confirm('Are you sure you want to cancel this schedule?')) {
                fetch(`cancel_schedule.php?schedule_id=${scheduleId}`, {
                    method: 'DELETE'
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success && selectedDriver) {
                        loadDriverSchedules(selectedDriver);
                    } else {
                        alert('Error cancelling schedule');
                    }
                })
                .catch(error => {
                    alert('Error cancelling schedule');
                });
            }
        }
        
        function calculateSummary() {
            const scheduleDate = document.getElementById('scheduleDate').value;
            const timeInputs = document.querySelectorAll('input[name="departure_times[]"]');
            const timesCount = timeInputs.length;
            
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
            
            // Update summary
            document.getElementById('summarySection').style.display = 'block';
            document.getElementById('summaryContent').innerHTML = `
                <div class="summary-item">
                    <span>Schedule Date:</span>
                    <span>${formattedDate}</span>
                </div>
                <div class="summary-item">
                    <span>Number of Trips:</span>
                    <span>${timesCount}</span>
                </div>
                <div class="summary-item">
                    <span>Route Duration:</span>
                    <span>${selectedRouteDuration} minutes per trip</span>
                </div>
                <div class="summary-item">
                    <span><strong>Total Time:</strong></span>
                    <span><strong>${timesCount * selectedRouteDuration} minutes</strong></span>
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
            calculateSummary();
        });
    </script>
</body>
</html>