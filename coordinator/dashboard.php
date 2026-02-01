<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Transport Coordinator
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

// Get coordinator info
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM user WHERE User_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$coordinator = $result->fetch_assoc();

// Get system statistics
$active_shuttles = $conn->query("SELECT COUNT(*) as count FROM shuttle_schedule WHERE Status = 'In Progress'")->fetch_assoc()['count'];
$today_schedules = $conn->query("SELECT COUNT(*) as count FROM shuttle_schedule WHERE DATE(Departure_time) = CURDATE()")->fetch_assoc()['count'];
$active_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicle WHERE Status = 'Active'")->fetch_assoc()['count'];
$open_incidents = $conn->query("SELECT COUNT(*) as count FROM incident_reports WHERE Status != 'Resolved' AND Status != 'Closed'")->fetch_assoc()['count'];

// Get recent incidents
$recent_incidents = $conn->query("
    SELECT ir.*, u.Full_Name as reporter_name, r.Route_Name
    FROM incident_reports ir
    LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
    LEFT JOIN shuttle_schedule ss ON ir.Schedule_ID = ss.Schedule_ID
    LEFT JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE ir.Status != 'Resolved' AND ir.Status != 'Closed'
    ORDER BY ir.Report_time DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get upcoming schedules
$upcoming_schedules = $conn->query("
    SELECT ss.*, r.Route_Name, v.Plate_number, u.Full_Name as driver_name
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    JOIN user u ON ss.Driver_ID = u.User_ID
    WHERE ss.Departure_time > NOW()
    AND ss.Status = 'Scheduled'
    ORDER BY ss.Departure_time ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get vehicle status
$vehicle_status = $conn->query("
    SELECT Plate_number, Model, Capacity, Status, 
           CASE 
               WHEN Last_Maintenance IS NULL OR Last_Maintenance < DATE_SUB(CURDATE(), INTERVAL 90 DAY) 
               THEN 'Due'
               ELSE 'OK'
           END as maintenance_status
    FROM vehicle
    ORDER BY Status, Plate_number
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Coordinator Dashboard</title>
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
        
        .dashboard-container {
            padding: 30px;
            max-width: 1400px;
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
            background: #9C27B0;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
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
        
        .dashboard-grid {
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-all {
            font-size: 14px;
            color: #9C27B0;
            text-decoration: none;
            font-weight: 600;
        }
        
        .incident-list, .schedule-list {
            list-style: none;
        }
        
        .incident-item, .schedule-item {
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .incident-item:hover, .schedule-item:hover {
            border-color: #9C27B0;
            background: #f8f9fa;
        }
        
        .incident-header, .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .incident-title {
            font-weight: 600;
            color: #333;
        }
        
        .priority-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .priority-high {
            background: #F44336;
            color: white;
        }
        
        .priority-medium {
            background: #FF9800;
            color: white;
        }
        
        .priority-low {
            background: #4CAF50;
            color: white;
        }
        
        .incident-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .incident-meta {
            font-size: 12px;
            color: #999;
            display: flex;
            justify-content: space-between;
        }
        
        .vehicle-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .vehicle-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }
        
        .vehicle-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .vehicle-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #4CAF50;
            color: white;
        }
        
        .status-maintenance {
            background: #FF9800;
            color: white;
        }
        
        .status-inactive {
            background: #9E9E9E;
            color: white;
        }
        
        .maintenance-due {
            color: #F44336;
            font-weight: 600;
        }
        
        .maintenance-ok {
            color: #4CAF50;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }
        
        .action-btn {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            background: #7B1FA2;
            transform: translateY(-2px);
        }
        
        .action-btn.secondary {
            background: #f8f9fa;
            color: #333;
            border: 2px solid #9C27B0;
        }
        
        .action-btn.secondary:hover {
            background: #F3E5F5;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .navbar {
                flex-direction: column;
                height: auto;
                padding: 15px;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">🚌 Campus Shuttle - Coordinator Panel</div>
        <div class="user-info">
            <div class="user-badge">
                <?php echo $_SESSION['username']; ?> (Coordinator)
            </div>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <span class="role-badge">TRANSPORT COORDINATOR PANEL</span>
            <h1>Welcome, <?php echo $coordinator['Full_Name']; ?>!</h1>
            <p>Monitor campus shuttle operations, manage schedules, and handle incidents.</p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Active Shuttles</div>
                <div class="stat-number"><?php echo $active_shuttles; ?></div>
                <div class="stat-label">Currently running</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Today's Schedules</div>
                <div class="stat-number"><?php echo $today_schedules; ?></div>
                <div class="stat-label">Shuttle trips</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Active Vehicles</div>
                <div class="stat-number"><?php echo $active_vehicles; ?></div>
                <div class="stat-label">Available for service</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Open Incidents</div>
                <div class="stat-number"><?php echo $open_incidents; ?></div>
                <div class="stat-label">Requiring attention</div>
            </div>
        </div>
        
        <!-- Main Content Grid -->
        <div class="dashboard-grid">
            <!-- Left Column: Incidents & Schedules -->
            <div class="left-column">
                <!-- Active Incidents -->
                <div class="section-card">
                    <h3 class="section-title">
                        🚨 Active Incidents
                        <a href="#" class="view-all">View All →</a>
                    </h3>
                    <?php if(count($recent_incidents) > 0): ?>
                        <ul class="incident-list">
                            <?php foreach($recent_incidents as $incident): ?>
                                <li class="incident-item">
                                    <div class="incident-header">
                                        <div class="incident-title"><?php echo $incident['Incident_Type']; ?></div>
                                        <span class="priority-badge priority-<?php echo strtolower($incident['Priority']); ?>">
                                            <?php echo $incident['Priority']; ?>
                                        </span>
                                    </div>
                                    <div class="incident-details">
                                        <?php echo substr($incident['Description'], 0, 100); ?>...
                                        <?php if($incident['Route_Name']): ?>
                                            <br><strong>Route:</strong> <?php echo $incident['Route_Name']; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="incident-meta">
                                        <span>Reported by: <?php echo $incident['reporter_name']; ?></span>
                                        <span><?php echo date('M d, H:i', strtotime($incident['Report_time'])); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="no-data">No active incidents. Great job! 🎉</div>
                    <?php endif; ?>
                </div>
                
                <!-- Upcoming Schedules -->
                <div class="section-card">
                    <h3 class="section-title">
                        📅 Upcoming Schedules
                        <a href="#" class="view-all">View All →</a>
                    </h3>
                    <?php if(count($upcoming_schedules) > 0): ?>
                        <ul class="schedule-list">
                            <?php foreach($upcoming_schedules as $schedule): ?>
                                <li class="schedule-item">
                                    <div class="incident-header">
                                        <div class="incident-title"><?php echo $schedule['Route_Name']; ?></div>
                                        <span style="font-size: 12px; color: #666;">
                                            <?php echo date('H:i', strtotime($schedule['Departure_time'])); ?>
                                        </span>
                                    </div>
                                    <div class="incident-details">
                                        <strong>Driver:</strong> <?php echo $schedule['driver_name']; ?><br>
                                        <strong>Vehicle:</strong> <?php echo $schedule['Plate_number']; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="no-data">No upcoming schedules</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column: Vehicle Status -->
            <div class="right-column">
                <div class="section-card">
                    <h3 class="section-title">
                        🚙 Vehicle Fleet Status
                        <a href="#" class="view-all">Manage Vehicles →</a>
                    </h3>
                    
                    <table class="vehicle-table">
                        <thead>
                            <tr>
                                <th>Plate No.</th>
                                <th>Model</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Maintenance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($vehicle_status as $vehicle): ?>
                                <tr>
                                    <td><strong><?php echo $vehicle['Plate_number']; ?></strong></td>
                                    <td><?php echo $vehicle['Model']; ?></td>
                                    <td><?php echo $vehicle['Capacity']; ?> seats</td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($vehicle['Status']); ?>">
                                            <?php echo $vehicle['Status']; ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo $vehicle['maintenance_status'] == 'Due' ? 'maintenance-due' : 'maintenance-ok'; ?>">
                                        <?php echo $vehicle['maintenance_status']; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Quick Actions -->
                <div class="section-card">
                    <h3 class="section-title">⚡ Quick Actions</h3>
                    <div class="quick-actions">
                        <button class="action-btn" onclick="window.location.href='../coordinator/createSchedule.php'">
                            📅 Create Schedule
                        </button>
                        <button class="action-btn secondary" onclick="window.location.href='../coordinator/manageRoute.php'">
                            🗺️ Manage Routes
                        </button>
                        <button class="action-btn secondary" onclick="window.location.href='assign_driver.php'">
                            👨‍✈️ Assign Driver
                        </button>
                        <button class="action-btn secondary" onclick="window.location.href='reports.php'">
                            📊 Generate Report
                        </button>
                    </div>
                </div>
                
                <!-- System Health -->
                <div class="section-card">
                    <h3 class="section-title">📈 System Health</h3>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Shuttle On-Time Rate</span>
                                <span style="color: #4CAF50; font-weight: 600;">92%</span>
                            </div>
                            <div style="height: 10px; background: #e9ecef; border-radius: 5px; overflow: hidden;">
                                <div style="width: 92%; height: 100%; background: #4CAF50;"></div>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Vehicle Availability</span>
                                <span style="color: #4CAF50; font-weight: 600;">85%</span>
                            </div>
                            <div style="height: 10px; background: #e9ecef; border-radius: 5px; overflow: hidden;">
                                <div style="width: 85%; height: 100%; background: #4CAF50;"></div>
                            </div>
                        </div>
                        
                        <div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Passenger Satisfaction</span>
                                <span style="color: #4CAF50; font-weight: 600;">88%</span>
                            </div>
                            <div style="height: 10px; background: #e9ecef; border-radius: 5px; overflow: hidden;">
                                <div style="width: 88%; height: 100%; background: #4CAF50;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        // Auto-refresh dashboard every 60 seconds
        setInterval(function() {
            console.log('Auto-refreshing coordinator dashboard...');
            // In real app, you would fetch updates via AJAX
        }, 60000);
    </script>
</body>
</html>