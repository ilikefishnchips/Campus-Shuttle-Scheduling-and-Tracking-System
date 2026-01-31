<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Driver
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Driver') {
    header('Location: ../driver_login.php');
    exit();
}

// Get driver info
$user_id = $_SESSION['user_id'];
$sql = "SELECT u.*, dp.* FROM user u 
        JOIN driver_profile dp ON u.User_ID = dp.User_ID
        WHERE u.User_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$driver = $result->fetch_assoc();

// Get today's schedules
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$sql_schedules = "SELECT ss.*, r.Route_Name, v.Plate_number, v.Model, v.Capacity
                  FROM shuttle_schedule ss
                  JOIN route r ON ss.Route_ID = r.Route_ID
                  JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
                  WHERE ss.Driver_ID = ? 
                  AND ss.Departure_time BETWEEN ? AND ?
                  ORDER BY ss.Departure_time ASC";
$stmt_schedules = $conn->prepare($sql_schedules);
$stmt_schedules->bind_param("iss", $user_id, $today_start, $today_end);
$stmt_schedules->execute();
$today_schedules = $stmt_schedules->get_result();

// Get current active schedule
$sql_active = "SELECT ss.*, r.Route_Name, r.Start_Location, r.End_Location
               FROM shuttle_schedule ss
               JOIN route r ON ss.Route_ID = r.Route_ID
               WHERE ss.Driver_ID = ? AND ss.Status = 'In Progress'
               LIMIT 1";
$stmt_active = $conn->prepare($sql_active);
$stmt_active->bind_param("i", $user_id);
$stmt_active->execute();
$active_schedule = $stmt_active->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Driver Dashboard</title>
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
            background: #FF9800;
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
            color: #FF9800;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .logout-btn:hover {
            background: #FFF3E0;
        }
        
        .dashboard-container {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .welcome-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .welcome-section h1 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .role-badge {
            display: inline-block;
            background: #FF9800;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .driver-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        
        .active-schedule {
            background: #4CAF50;
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(76,175,80,0.3);
        }
        
        .schedule-title {
            font-size: 24px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .schedule-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .detail-item {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
        }
        
        .schedule-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-btn {
            background: white;
            color: #4CAF50;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-btn:hover {
            background: #f1f1f1;
        }
        
        .action-btn.warning {
            background: #FF9800;
            color: white;
        }
        
        .action-btn.warning:hover {
            background: #F57C00;
        }
        
        .schedule-list {
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
            border-bottom: 2px solid #FF9800;
        }
        
        .schedule-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            align-items: center;
        }
        
        .schedule-item:last-child {
            border-bottom: none;
        }
        
        .schedule-item.header {
            font-weight: 600;
            color: #666;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        
        .status-scheduled {
            background: #FF9800;
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
        
        .start-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .start-btn:hover {
            background: #388E3C;
        }
        
        .start-btn:disabled {
            background: #9E9E9E;
            cursor: not-allowed;
        }
        
        .vehicle-info {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-top: 30px;
        }
        
        .vehicle-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }
            
            .schedule-item {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .schedule-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">🚌 Campus Shuttle - Driver Portal</div>
        <div class="user-info">
            <div class="user-badge">
                <?php echo $_SESSION['username']; ?> (Driver)
            </div>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <span class="role-badge">DRIVER PORTAL</span>
            <h1>Welcome, <?php echo $driver['Full_Name']; ?>!</h1>
            <p>Manage your shuttle schedules and update trip status.</p>
            
            <div class="driver-info">
                <div class="info-item">
                    <span class="info-label">License Number</span>
                    <span class="info-value"><?php echo $driver['License_Number']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">License Expiry</span>
                    <span class="info-value"><?php echo date('M d, Y', strtotime($driver['License_Expiry'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo $driver['Phone']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Assigned Vehicle</span>
                    <span class="info-value"><?php echo $driver['Assigned_Vehicle']; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Active Schedule -->
        <?php if($active_schedule): ?>
        <div class="active-schedule">
            <div class="schedule-title">
                🚍 ACTIVE SHUTTLE TRIP
            </div>
            <p>You are currently operating a shuttle. Please monitor the trip and update status as needed.</p>
            
            <div class="schedule-details">
                <div class="detail-item">
                    <div class="info-label">Route</div>
                    <div class="info-value"><?php echo $active_schedule['Route_Name']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">From</div>
                    <div class="info-value"><?php echo $active_schedule['Start_Location']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">To</div>
                    <div class="info-value"><?php echo $active_schedule['End_Location']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">Departure</div>
                    <div class="info-value"><?php echo date('H:i', strtotime($active_schedule['Departure_time'])); ?></div>
                </div>
            </div>
            
            <div class="schedule-actions">
                <button class="action-btn" onclick="updateStatus('On Time')">
                    ✅ Mark On Time
                </button>
                <button class="action-btn warning" onclick="updateStatus('Delayed')">
                    ⚠️ Report Delay
                </button>
                <button class="action-btn" onclick="updateStatus('Completed')">
                    🏁 End Trip
                </button>
                <button class="action-btn" onclick="reportIncident()">
                    🚨 Report Issue
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Today's Schedule -->
        <div class="schedule-list">
            <h3 class="section-title">📅 Today's Schedule</h3>
            
            <div class="schedule-item header">
                <div>Route</div>
                <div>Vehicle</div>
                <div>Time</div>
                <div>Status</div>
                <div>Action</div>
            </div>
            
            <?php if($today_schedules->num_rows > 0): ?>
                <?php while($schedule = $today_schedules->fetch_assoc()): ?>
                    <div class="schedule-item">
                        <div>
                            <strong><?php echo $schedule['Route_Name']; ?></strong><br>
                            <small>Capacity: <?php echo $schedule['Capacity']; ?> seats</small>
                        </div>
                        <div><?php echo $schedule['Plate_number']; ?><br><small><?php echo $schedule['Model']; ?></small></div>
                        <div><?php echo date('H:i', strtotime($schedule['Departure_time'])); ?></div>
                        <div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $schedule['Status'])); ?>">
                                <?php echo $schedule['Status']; ?>
                            </span>
                        </div>
                        <div>
                            <?php if($schedule['Status'] == 'Scheduled'): ?>
                                <button class="start-btn" onclick="startTrip(<?php echo $schedule['Schedule_ID']; ?>)">
                                    Start Trip
                                </button>
                            <?php elseif($schedule['Status'] == 'In Progress'): ?>
                                <button class="start-btn" disabled>
                                    In Progress
                                </button>
                            <?php else: ?>
                                <span style="color: #666; font-size: 12px;">Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #999;">
                    No schedules for today. Enjoy your day off!
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Vehicle Information -->
        <div class="vehicle-info">
            <h3 class="section-title">🚙 Vehicle Information</h3>
            
            <?php 
            // Get assigned vehicle details
            $vehicle_sql = "SELECT * FROM vehicle WHERE Plate_number = ?";
            $vehicle_stmt = $conn->prepare($vehicle_sql);
            $vehicle_stmt->bind_param("s", $driver['Assigned_Vehicle']);
            $vehicle_stmt->execute();
            $vehicle = $vehicle_stmt->get_result()->fetch_assoc();
            ?>
            
            <div class="vehicle-details">
                <div class="detail-item">
                    <div class="info-label">Plate Number</div>
                    <div class="info-value"><?php echo $vehicle['Plate_number']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">Model</div>
                    <div class="info-value"><?php echo $vehicle['Model']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">Capacity</div>
                    <div class="info-value"><?php echo $vehicle['Capacity']; ?> seats</div>
                </div>
                <div class="detail-item">
                    <div class="info-label">Status</div>
                    <div class="info-value"><?php echo $vehicle['Status']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="info-label">Last Maintenance</div>
                    <div class="info-value"><?php echo $vehicle['Last_Maintenance'] ? date('M d, Y', strtotime($vehicle['Last_Maintenance'])) : 'N/A'; ?></div>
                </div>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: #FFF3E0; border-radius: 5px;">
                <strong>⚠️ Maintenance Reminder:</strong> Next maintenance due in 30 days.
            </div>
        </div>
    </div>
    
    <script>
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        function startTrip(scheduleId) {
            if(confirm('Start this shuttle trip?')) {
                // In real app, make AJAX call to update status
                alert('Trip started! Updating status...');
                // Refresh page
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        function updateStatus(status) {
            if(confirm(`Update status to "${status}"?`)) {
                alert(`Status updated to ${status}`);
                // In real app, make AJAX call
            }
        }
        
        function reportIncident() {
            const incident = prompt('Describe the incident:');
            if(incident) {
                alert('Incident reported! Coordinator has been notified.');
            }
        }
    </script>
</body>
</html>