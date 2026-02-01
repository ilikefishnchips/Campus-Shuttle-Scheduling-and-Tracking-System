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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_schedule'])) {
        // Add new schedule
        $route_id = intval($_POST['route_id']);
        $vehicle_id = intval($_POST['vehicle_id']);
        $driver_id = intval($_POST['driver_id']);
        $departure_time = $_POST['departure_time'];
        $available_seats = intval($_POST['available_seats']);
        $status = $_POST['status'];
        
        // Calculate expected arrival based on route duration
        $route_info = $conn->query("SELECT Estimated_Duration_Minutes FROM route WHERE Route_ID = $route_id")->fetch_assoc();
        $duration_minutes = $route_info['Estimated_Duration_Minutes'] ?? 15;
        
        $expected_arrival = date('Y-m-d H:i:s', strtotime("$departure_time + $duration_minutes minutes"));
        
        // Validate inputs
        if ($route_id && $vehicle_id && $driver_id && $departure_time && $available_seats > 0) {
            // Check if vehicle is available at that time
            $check_vehicle_sql = "SELECT COUNT(*) as count FROM shuttle_schedule 
                                 WHERE Vehicle_ID = ? 
                                 AND DATE(Departure_time) = DATE(?)
                                 AND HOUR(Departure_time) = HOUR(?)
                                 AND Status IN ('Scheduled', 'In Progress')
                                 AND Schedule_ID != ?";
            $check_vehicle_stmt = $conn->prepare($check_vehicle_sql);
            $check_vehicle_stmt->bind_param("issi", $vehicle_id, $departure_time, $departure_time, 0);
            $check_vehicle_stmt->execute();
            $vehicle_result = $check_vehicle_stmt->get_result();
            $vehicle_count = $vehicle_result->fetch_assoc()['count'];
            
            // Check if driver is available at that time
            $check_driver_sql = "SELECT COUNT(*) as count FROM shuttle_schedule 
                                WHERE Driver_ID = ? 
                                AND DATE(Departure_time) = DATE(?)
                                AND HOUR(Departure_time) = HOUR(?)
                                AND Status IN ('Scheduled', 'In Progress')
                                AND Schedule_ID != ?";
            $check_driver_stmt = $conn->prepare($check_driver_sql);
            $check_driver_stmt->bind_param("issi", $driver_id, $departure_time, $departure_time, 0);
            $check_driver_stmt->execute();
            $driver_result = $check_driver_stmt->get_result();
            $driver_count = $driver_result->fetch_assoc()['count'];
            
            // Check vehicle capacity
            $vehicle_capacity = $conn->query("SELECT Capacity FROM vehicle WHERE Vehicle_ID = $vehicle_id")->fetch_assoc()['Capacity'] ?? 30;
            
            if ($available_seats > $vehicle_capacity) {
                $message = "Available seats cannot exceed vehicle capacity ($vehicle_capacity)!";
                $message_type = "error";
            } elseif ($vehicle_count > 0) {
                $message = "Vehicle is already scheduled at this time!";
                $message_type = "error";
            } elseif ($driver_count > 0) {
                $message = "Driver is already assigned to another schedule at this time!";
                $message_type = "error";
            } else {
                // Insert the schedule
                $sql = "INSERT INTO shuttle_schedule 
                        (Route_ID, Vehicle_ID, Driver_ID, Departure_time, Expected_Arrival, Available_Seats, Status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiissis", $route_id, $vehicle_id, $driver_id, $departure_time, $expected_arrival, $available_seats, $status);
                
                if ($stmt->execute()) {
                    $message = "Schedule added successfully!";
                    $message_type = "success";
                    
                    // Clear form by redirecting
                    header("Location: create_schedule.php?success=1");
                    exit();
                } else {
                    $message = "Error adding schedule: " . $conn->error;
                    $message_type = "error";
                }
            }
        } else {
            $message = "Please fill in all required fields correctly";
            $message_type = "error";
        }
    }
    elseif (isset($_POST['edit_schedule'])) {
        // Edit existing schedule
        $schedule_id = intval($_POST['schedule_id']);
        $route_id = intval($_POST['route_id']);
        $vehicle_id = intval($_POST['vehicle_id']);
        $driver_id = intval($_POST['driver_id']);
        $departure_time = $_POST['departure_time'];
        $available_seats = intval($_POST['available_seats']);
        $status = $_POST['status'];
        
        // Calculate expected arrival
        $route_info = $conn->query("SELECT Estimated_Duration_Minutes FROM route WHERE Route_ID = $route_id")->fetch_assoc();
        $duration_minutes = $route_info['Estimated_Duration_Minutes'] ?? 15;
        $expected_arrival = date('Y-m-d H:i:s', strtotime("$departure_time + $duration_minutes minutes"));
        
        // Check vehicle capacity
        $vehicle_capacity = $conn->query("SELECT Capacity FROM vehicle WHERE Vehicle_ID = $vehicle_id")->fetch_assoc()['Capacity'] ?? 30;
        
        if ($available_seats > $vehicle_capacity) {
            $message = "Available seats cannot exceed vehicle capacity ($vehicle_capacity)!";
            $message_type = "error";
        } else {
            $sql = "UPDATE shuttle_schedule SET 
                    Route_ID = ?, 
                    Vehicle_ID = ?, 
                    Driver_ID = ?, 
                    Departure_time = ?, 
                    Expected_Arrival = ?,
                    Available_Seats = ?, 
                    Status = ?
                    WHERE Schedule_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiissisi", $route_id, $vehicle_id, $driver_id, $departure_time, $expected_arrival, $available_seats, $status, $schedule_id);
            
            if ($stmt->execute()) {
                $message = "Schedule updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating schedule: " . $conn->error;
                $message_type = "error";
            }
        }
    }
    elseif (isset($_POST['cancel_schedule'])) {
        // Cancel schedule
        $schedule_id = intval($_POST['schedule_id']);
        
        // Check if schedule has active bookings
        $check_bookings_sql = "SELECT COUNT(*) as count FROM seat_reservation 
                              WHERE Schedule_ID = ? AND Status IN ('Confirmed', 'Pending')";
        $check_bookings_stmt = $conn->prepare($check_bookings_sql);
        $check_bookings_stmt->bind_param("i", $schedule_id);
        $check_bookings_stmt->execute();
        $bookings_result = $check_bookings_stmt->get_result();
        $bookings_count = $bookings_result->fetch_assoc()['count'];
        
        if ($bookings_count > 0) {
            $message = "Cannot cancel schedule with active bookings!";
            $message_type = "error";
        } else {
            $sql = "UPDATE shuttle_schedule SET Status = 'Cancelled' WHERE Schedule_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $schedule_id);
            
            if ($stmt->execute()) {
                $message = "Schedule cancelled successfully!";
                $message_type = "success";
            } else {
                $message = "Error cancelling schedule: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Schedule added successfully!";
    $message_type = "success";
}

// Get data for dropdowns
$routes = $conn->query("SELECT * FROM route WHERE Status = 'Active' ORDER BY Route_Name")->fetch_all(MYSQLI_ASSOC);
$vehicles = $conn->query("SELECT * FROM vehicle WHERE Status = 'Active' ORDER BY Plate_number")->fetch_all(MYSQLI_ASSOC);
$drivers = $conn->query("
    SELECT u.*, d.License_Number 
    FROM user u 
    JOIN driver_profile d ON u.User_ID = d.User_ID 
    WHERE u.Role = 'Driver' AND u.Status = 'Active'
    ORDER BY u.Full_Name
")->fetch_all(MYSQLI_ASSOC);

// Get upcoming schedules (next 7 days)
$upcoming_schedules = $conn->query("
    SELECT ss.*, 
           r.Route_Name, r.Start_Location, r.End_Location, r.Estimated_Duration_Minutes,
           v.Plate_number, v.Capacity,
           u.Full_Name as driver_name,
           (SELECT COUNT(*) FROM seat_reservation sr WHERE sr.Schedule_ID = ss.Schedule_ID AND sr.Status = 'Confirmed') as booked_seats
    FROM shuttle_schedule ss
    LEFT JOIN route r ON ss.Route_ID = r.Route_ID
    LEFT JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    LEFT JOIN user u ON ss.Driver_ID = u.User_ID
    WHERE ss.Departure_time >= CURDATE()
    AND ss.Departure_time <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY ss.Departure_time ASC
")->fetch_all(MYSQLI_ASSOC);

// Get schedule statistics
$schedule_stats = $conn->query("
    SELECT 
        COUNT(*) as total_schedules,
        SUM(CASE WHEN Status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN Status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN Status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN Status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN Status = 'Delayed' THEN 1 ELSE 0 END) as delayed,
        MIN(Departure_time) as next_schedule,
        MAX(Departure_time) as last_schedule
    FROM shuttle_schedule
    WHERE Departure_time >= CURDATE()
")->fetch_assoc();

// Get today's schedules
$today_schedules = $conn->query("
    SELECT COUNT(*) as count 
    FROM shuttle_schedule 
    WHERE DATE(Departure_time) = CURDATE() 
    AND Status IN ('Scheduled', 'In Progress')
")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Schedule - Coordinator</title>
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
            max-width: 1400px;
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
        
        .section-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
        
        .btn-success {
            background: #4CAF50;
        }
        
        .btn-success:hover {
            background: #388E3C;
        }
        
        .btn-danger {
            background: #F44336;
        }
        
        .btn-danger:hover {
            background: #D32F2F;
        }
        
        .btn-warning {
            background: #FF9800;
        }
        
        .btn-warning:hover {
            background: #F57C00;
        }
        
        .schedules-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .schedules-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }
        
        .schedules-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .schedules-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-scheduled {
            background: #2196F3;
            color: white;
        }
        
        .status-in-progress {
            background: #FF9800;
            color: white;
        }
        
        .status-completed {
            background: #4CAF50;
            color: white;
        }
        
        .status-cancelled {
            background: #9E9E9E;
            color: white;
        }
        
        .status-delayed {
            background: #F44336;
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
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
        
        .capacity-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .capacity-bar {
            flex-grow: 1;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: #4CAF50;
            border-radius: 4px;
        }
        
        .capacity-high {
            background: #F44336;
        }
        
        .capacity-medium {
            background: #FF9800;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 20px;
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
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            width: 500px;
            max-width: 90%;
            max-height: 80vh;
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
        
        .info-box {
            background: #E3F2FD;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        
        .info-box p {
            margin: 5px 0;
            color: #1565C0;
        }
        
        .route-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            border: 1px solid #e9ecef;
        }
        
        .time-display {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
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
            
            .quick-actions {
                grid-template-columns: 1fr;
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
            <a href="manage_routes.php" class="nav-link">Manage Routes</a>
            <a href="create_schedule.php" class="nav-link active">Create Schedule</a>
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
            <h1 class="page-title">📅 Create Shuttle Schedule</h1>
            <p class="page-subtitle">Plan and manage shuttle departure times and assignments</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Today's Schedules</div>
                <div class="stat-number"><?php echo $today_schedules; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Upcoming</div>
                <div class="stat-number"><?php echo $schedule_stats['scheduled']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">In Progress</div>
                <div class="stat-number"><?php echo $schedule_stats['in_progress']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Delayed</div>
                <div class="stat-number"><?php echo $schedule_stats['delayed']; ?></div>
            </div>
        </div>
        
        <!-- Message Display -->
        <?php if($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Info Box -->
        <div class="info-box">
            <p><strong>💡 Information:</strong> Schedules are automatically calculated based on route duration.</p>
            <p>Expected arrival time is calculated as: Departure Time + Route Duration</p>
        </div>
        
        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column: Create Schedule Form -->
            <div class="section-card">
                <h2 class="section-title">Create New Schedule</h2>
                <form method="POST" action="" id="scheduleForm">
                    <div class="form-group">
                        <label class="form-label required">Select Route</label>
                        <select name="route_id" class="form-control" required onchange="updateRouteInfo(this.value)">
                            <option value="">-- Select a Route --</option>
                            <?php foreach($routes as $route): ?>
                                <option value="<?php echo $route['Route_ID']; ?>" 
                                        data-duration="<?php echo $route['Estimated_Duration_Minutes']; ?>">
                                    <?php echo $route['Route_Name']; ?> 
                                    (<?php echo $route['Start_Location']; ?> → <?php echo $route['End_Location']; ?>)
                                    - <?php echo $route['Estimated_Duration_Minutes']; ?> min
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Select Vehicle</label>
                            <select name="vehicle_id" class="form-control" required onchange="updateVehicleInfo(this.value)">
                                <option value="">-- Select a Vehicle --</option>
                                <?php foreach($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['Vehicle_ID']; ?>" data-capacity="<?php echo $vehicle['Capacity']; ?>">
                                        <?php echo $vehicle['Plate_number']; ?> (Capacity: <?php echo $vehicle['Capacity']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Select Driver</label>
                            <select name="driver_id" class="form-control" required onchange="updateDriverInfo(this.value)">
                                <option value="">-- Select a Driver --</option>
                                <?php foreach($drivers as $driver): ?>
                                    <option value="<?php echo $driver['User_ID']; ?>">
                                        <?php echo $driver['Full_Name']; ?> (License: <?php echo $driver['License_Number']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Departure Date & Time</label>
                        <input type="datetime-local" name="departure_time" class="form-control" 
                               id="departure_time" 
                               min="<?php echo date('Y-m-d\TH:i'); ?>" 
                               required 
                               onchange="updateArrivalTime()">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Available Seats</label>
                        <input type="number" name="available_seats" class="form-control" 
                               id="available_seats" min="1" max="100" value="30" required>
                        <small style="color: #666;">Seats available for booking</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Initial Status</label>
                        <select name="status" class="form-control" required>
                            <option value="Scheduled">Scheduled</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Delayed">Delayed</option>
                            <option value="Cancelled">Cancelled</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    
                    <!-- Calculated Arrival Time -->
                    <div id="arrivalInfo" style="display: none;" class="route-summary">
                        <h4 style="margin-bottom: 10px;">📅 Calculated Schedule</h4>
                        <div class="time-display">
                            <strong>Departure:</strong> <span id="displayDeparture">--</span>
                        </div>
                        <div class="time-display">
                            <strong>Expected Arrival:</strong> <span id="displayArrival">--</span>
                        </div>
                        <div class="time-display">
                            <strong>Duration:</strong> <span id="displayDuration">--</span> minutes
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <button type="submit" name="add_schedule" class="btn btn-success">
                            📅 Create Schedule
                        </button>
                        <button type="button" class="btn-secondary" onclick="checkAvailability()">
                            🔍 Check Availability
                        </button>
                        <button type="reset" class="btn-secondary" onclick="resetForm()">
                            🔄 Reset Form
                        </button>
                    </div>
                </form>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                
                <h2 class="section-title">Quick Actions</h2>
                <div class="quick-actions">
                    <button class="btn-secondary" onclick="openBulkSchedule()">
                        📥 Bulk Schedule
                    </button>
                    <button class="btn-secondary" onclick="generateTimetable()">
                        📊 Generate Timetable
                    </button>
                    <button class="btn-secondary" onclick="checkConflicts()">
                        ⚠️ Check Conflicts
                    </button>
                    <button class="btn-secondary" onclick="window.location.href='calendar_view.php'">
                        🗓️ Calendar View
                    </button>
                </div>
            </div>
            
            <!-- Right Column: Upcoming Schedules -->
            <div class="section-card">
                <h2 class="section-title">Upcoming Schedules (Next 7 Days)</h2>
                
                <?php if(count($upcoming_schedules) > 0): ?>
                    <table class="schedules-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Route</th>
                                <th>Vehicle/Driver</th>
                                <th>Status/Seats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($upcoming_schedules as $schedule): 
                                $departure_date = date('M d', strtotime($schedule['Departure_time']));
                                $departure_time = date('H:i', strtotime($schedule['Departure_time']));
                                $arrival_time = $schedule['Expected_Arrival'] ? date('H:i', strtotime($schedule['Expected_Arrival'])) : '--:--';
                                
                                // Calculate seat availability
                                $capacity = $schedule['Capacity'] ?? 30;
                                $booked_seats = $schedule['booked_seats'] ?? 0;
                                $available_seats = $schedule['Available_Seats'] ?? $capacity;
                                $capacity_percentage = ($booked_seats / $capacity) * 100;
                                $capacity_class = $capacity_percentage > 80 ? 'capacity-high' : 
                                                ($capacity_percentage > 50 ? 'capacity-medium' : '');
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $departure_time; ?></strong>
                                        <br><small><?php echo $departure_date; ?></small>
                                        <?php if($arrival_time != '--:--'): ?>
                                            <br><small>→ Arrival: <?php echo $arrival_time; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($schedule['Route_Name']): ?>
                                            <strong><?php echo $schedule['Route_Name']; ?></strong>
                                            <br><small><?php echo $schedule['Start_Location']; ?> → <?php echo $schedule['End_Location']; ?></small>
                                        <?php else: ?>
                                            <small style="color: #999;">Route not found</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($schedule['Plate_number']): ?>
                                            <small>🚙 <?php echo $schedule['Plate_number']; ?></small>
                                        <?php else: ?>
                                            <small style="color: #999;">No vehicle</small>
                                        <?php endif; ?>
                                        <br>
                                        <?php if($schedule['driver_name']): ?>
                                            <small>👨‍✈️ <?php echo $schedule['driver_name']; ?></small>
                                        <?php else: ?>
                                            <small style="color: #999;">No driver</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $schedule['Status'])); ?>">
                                            <?php echo $schedule['Status']; ?>
                                        </span>
                                        <div class="capacity-indicator">
                                            <small><?php echo $booked_seats; ?>/<?php echo $available_seats; ?> seats</small>
                                            <?php if($available_seats > 0): ?>
                                                <div class="capacity-bar">
                                                    <div class="capacity-fill <?php echo $capacity_class; ?>" 
                                                         style="width: <?php echo min($capacity_percentage, 100); ?>%"></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn btn-secondary" 
                                                    onclick="editSchedule(<?php echo $schedule['Schedule_ID']; ?>)">
                                                ✏️ Edit
                                            </button>
                                            <?php if($schedule['Status'] == 'Scheduled'): ?>
                                                <button class="action-btn btn-danger" 
                                                        onclick="cancelSchedule(<?php echo $schedule['Schedule_ID']; ?>, '<?php echo addslashes($departure_time . ' ' . $schedule['Route_Name']); ?>')">
                                                    🗑️ Cancel
                                                </button>
                                            <?php endif; ?>
                                            <button class="action-btn btn" 
                                                    onclick="viewSchedule(<?php echo $schedule['Schedule_ID']; ?>)">
                                                👁️ View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No upcoming schedules. Create your first schedule!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit Schedule Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Schedule</h2>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="schedule_id" id="edit_schedule_id">
                
                <div class="form-group">
                    <label class="form-label required">Route</label>
                    <select name="route_id" id="edit_route_id" class="form-control" required>
                        <?php foreach($routes as $route): ?>
                            <option value="<?php echo $route['Route_ID']; ?>">
                                <?php echo $route['Route_Name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Vehicle</label>
                    <select name="vehicle_id" id="edit_vehicle_id" class="form-control" required>
                        <?php foreach($vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                <?php echo $vehicle['Plate_number']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Driver</label>
                    <select name="driver_id" id="edit_driver_id" class="form-control" required>
                        <?php foreach($drivers as $driver): ?>
                            <option value="<?php echo $driver['User_ID']; ?>">
                                <?php echo $driver['Full_Name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Departure Time</label>
                    <input type="datetime-local" name="departure_time" id="edit_departure_time" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Available Seats</label>
                    <input type="number" name="available_seats" id="edit_available_seats" class="form-control" required min="1" max="100">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Status</label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Delayed">Delayed</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="edit_schedule" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cancel Schedule Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Cancel Schedule</h2>
            <p id="cancelMessage">Are you sure you want to cancel this schedule?</p>
            <form id="cancelForm" method="POST" action="">
                <input type="hidden" name="schedule_id" id="cancel_schedule_id">
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeCancelModal()">No, Keep Schedule</button>
                    <button type="submit" name="cancel_schedule" class="btn-danger">Yes, Cancel Schedule</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        function updateRouteInfo(routeId) {
            if (!routeId) {
                document.getElementById('arrivalInfo').style.display = 'none';
                return;
            }
            
            const routeSelect = document.querySelector('select[name="route_id"]');
            const selectedOption = routeSelect.options[routeSelect.selectedIndex];
            const duration = selectedOption.getAttribute('data-duration') || 15;
            
            document.getElementById('displayDuration').textContent = duration;
            updateArrivalTime();
        }
        
        function updateVehicleInfo(vehicleId) {
            if (!vehicleId) return;
            
            const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
            const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
            const capacity = selectedOption.getAttribute('data-capacity') || 30;
            
            document.getElementById('available_seats').max = capacity;
            document.getElementById('available_seats').value = Math.min(capacity, document.getElementById('available_seats').value);
        }
        
        function updateArrivalTime() {
            const departureInput = document.getElementById('departure_time');
            const departureValue = departureInput.value;
            const routeSelect = document.querySelector('select[name="route_id"]');
            
            if (!departureValue || !routeSelect.value) {
                document.getElementById('arrivalInfo').style.display = 'none';
                return;
            }
            
            const selectedOption = routeSelect.options[routeSelect.selectedIndex];
            const duration = parseInt(selectedOption.getAttribute('data-duration')) || 15;
            
            const departureDate = new Date(departureValue);
            const arrivalDate = new Date(departureDate.getTime() + duration * 60000);
            
            // Format dates for display
            const options = { 
                weekday: 'short', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            
            document.getElementById('displayDeparture').textContent = 
                departureDate.toLocaleDateString('en-US', options);
            document.getElementById('displayArrival').textContent = 
                arrivalDate.toLocaleDateString('en-US', options);
            
            document.getElementById('arrivalInfo').style.display = 'block';
        }
        
        function checkAvailability() {
            const routeId = document.querySelector('select[name="route_id"]').value;
            const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
            const driverId = document.querySelector('select[name="driver_id"]').value;
            const departureTime = document.getElementById('departure_time').value;
            
            if (!routeId || !vehicleId || !driverId || !departureTime) {
                alert('Please fill all fields to check availability');
                return;
            }
            
            // In a real application, this would be an AJAX call
            alert('Checking availability...\n\nThis feature would check for:\n1. Vehicle availability at selected time\n2. Driver availability at selected time\n3. Route capacity\n\nImplementation requires backend API.');
        }
        
        function resetForm() {
            if(confirm('Are you sure you want to reset the form?')) {
                document.getElementById('scheduleForm').reset();
                document.getElementById('arrivalInfo').style.display = 'none';
            }
        }
        
        function editSchedule(scheduleId) {
            fetch('get_schedule_details.php?schedule_id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_schedule_id').value = data.schedule.Schedule_ID;
                        document.getElementById('edit_route_id').value = data.schedule.Route_ID;
                        document.getElementById('edit_vehicle_id').value = data.schedule.Vehicle_ID;
                        document.getElementById('edit_driver_id').value = data.schedule.Driver_ID;
                        
                        // Format datetime for input field
                        const departureTime = new Date(data.schedule.Departure_time);
                        const formattedTime = departureTime.toISOString().slice(0, 16);
                        document.getElementById('edit_departure_time').value = formattedTime;
                        
                        document.getElementById('edit_available_seats').value = data.schedule.Available_Seats;
                        document.getElementById('edit_status').value = data.schedule.Status;
                        
                        document.getElementById('editModal').style.display = 'flex';
                    } else {
                        alert('Error loading schedule details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading schedule details');
                });
        }
        
        function cancelSchedule(scheduleId, scheduleName) {
            document.getElementById('cancel_schedule_id').value = scheduleId;
            document.getElementById('cancelMessage').innerHTML = 
                `Are you sure you want to cancel schedule:<br><strong>"${scheduleName}"</strong>?<br><br>
                <small style="color: #666;">This will notify all passengers with bookings.</small>`;
            document.getElementById('cancelModal').style.display = 'flex';
        }
        
        function viewSchedule(scheduleId) {
            window.location.href = 'view_schedule.php?id=' + scheduleId;
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }
        
        function openBulkSchedule() {
            window.location.href = 'bulk_schedule.php';
        }
        
        function generateTimetable() {
            window.location.href = 'timetable.php';
        }
        
        function checkConflicts() {
            alert('Conflict checking would scan all schedules for overlapping assignments.');
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                closeModal();
                closeCancelModal();
            }
        };
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeCancelModal();
            }
        });
        
        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            // Set min time to current time rounded to next 15 minutes
            const now = new Date();
            const roundedMinutes = Math.ceil(now.getMinutes() / 15) * 15;
            now.setMinutes(roundedMinutes);
            now.setSeconds(0);
            
            const minDateTime = now.toISOString().slice(0, 16);
            document.getElementById('departure_time').min = minDateTime;
            
            // If form has values, calculate arrival
            const routeSelect = document.querySelector('select[name="route_id"]');
            if (routeSelect.value) {
                updateRouteInfo(routeSelect.value);
            }
        });
    </script>
</body>
</html>