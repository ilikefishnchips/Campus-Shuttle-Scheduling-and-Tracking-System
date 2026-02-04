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

// Get unread notifications count for current coordinator
$unread_count_sql = "SELECT COUNT(*) as count FROM notifications WHERE User_ID = ? AND Status = 'Unread'";
$stmt = $conn->prepare($unread_count_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_result = $stmt->get_result();
$unread_count_data = $unread_result->fetch_assoc();
$unread_count = $unread_count_data['count'];

// Get recent notifications for current coordinator (last 5)
$recent_notifications_sql = "
    SELECT n.*, r.Route_Name, ss.Departure_time
    FROM notifications n
    LEFT JOIN route r ON n.Related_Route_ID = r.Route_ID
    LEFT JOIN shuttle_schedule ss ON n.Related_Schedule_ID = ss.Schedule_ID
    WHERE n.User_ID = ? 
    AND n.Status != 'Deleted'
    ORDER BY n.Created_At DESC
    LIMIT 5
";
$stmt = $conn->prepare($recent_notifications_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications_result = $stmt->get_result();
$recent_notifications = $notifications_result->fetch_all(MYSQLI_ASSOC);

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
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            max-width: 1200px;
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
        
        .navbar-center {
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .nav-links {
            display: flex;
            gap: 20px;
            list-style: none;
        }
        
        .nav-link {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .nav-link:hover {
            background: #f0f0f0;
            color: #9C27B0;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .notification-container {
            position: relative;
        }
        
        .notification-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            position: relative;
            transition: all 0.3s;
        }
        
        .notification-btn:hover {
            background: #f0f0f0;
        }
        
        .notification-icon {
            font-size: 20px;
            color: #555;
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #F44336;
            color: white;
            font-size: 10px;
            font-weight: bold;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            display: none;
            z-index: 1001;
            margin-top: 10px;
        }
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-header h3 {
            color: #333;
            font-size: 16px;
        }
        
        .mark-all-read {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .mark-all-read:hover {
            background: #7B1FA2;
        }
        
        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #f0f7ff;
        }
        
        .notification-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-type {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            background: #e0e0e0;
            color: #666;
        }
        
        .notification-message {
            color: #666;
            font-size: 13px;
            line-height: 1.4;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .notification-meta {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #999;
        }
        
        .notification-footer {
            padding: 15px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }
        
        .priority-badge-notification {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .priority-urgent {
            background: #F44336;
            color: white;
        }
        
        .priority-high {
            background: #FF9800;
            color: white;
        }
        
        .priority-normal {
            background: #2196F3;
            color: white;
        }
        
        .priority-low {
            background: #4CAF50;
            color: white;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 15px;
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
        
        .logout-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: #d32f2f;
        }
        
        .dashboard-container {
            padding: 30px;
            max-width: 1200px;
            margin: 100px auto 30px;
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
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            border-top: 4px solid #9C27B0;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
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
            margin-bottom: 5px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .section-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .section-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
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
            transition: color 0.3s;
        }
        
        .view-all:hover {
            color: #7B1FA2;
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
            background: #f8f9fa;
        }
        
        .incident-item:hover, .schedule-item:hover {
            border-color: #9C27B0;
            background: white;
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
            padding: 4px 12px;
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
            line-height: 1.5;
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
            margin-top: 10px;
        }
        
        .vehicle-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
            font-size: 14px;
        }
        
        .vehicle-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            font-size: 14px;
        }
        
        .vehicle-table tr:hover {
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
            margin-top: 20px;
        }
        
        .action-btn {
            background: #9C27B0;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .action-btn:hover {
            background: #7B1FA2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(155, 39, 176, 0.3);
        }
        
        .action-btn.secondary {
            background: white;
            color: #9C27B0;
            border: 2px solid #9C27B0;
        }
        
        .action-btn.secondary:hover {
            background: #9C27B0;
            color: white;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }
        
        .system-health {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-top: 30px;
        }
        
        .system-health h3 {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #9C27B0;
        }
        
        .progress-item {
            margin-bottom: 20px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .progress-bar {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 5px;
        }
        
        .progress-rate-92 { width: 92%; background: #4CAF50; }
        .progress-rate-85 { width: 85%; background: #2196F3; }
        .progress-rate-88 { width: 88%; background: #9C27B0; }
        
        .quick-links {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-top: 30px;
        }
        
        .quick-links h3 {
            margin-bottom: 20px;
            color: #333;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #9C27B0;
        }
        
        .links-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .link-item {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .link-item:hover {
            background: #9C27B0;
            color: white;
            border-color: #9C27B0;
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-links {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 20px;
                margin: 80px auto 20px;
            }
            
            .navbar {
                padding: 10px;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
            
            .admin-profile {
                gap: 10px;
            }
            
            .user-badge {
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .logout-btn {
                padding: 6px 15px;
                font-size: 14px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -80px;
            }
            
            .admin-profile {
                flex-direction: column;
                align-items: flex-end;
                gap: 5px;
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
            </div>  
            
            <div class="navbar-center">
                <ul class="nav-links">
                    <li><a href="manageRoutePage.php" class="nav-link">Routes</a></li>
                    <li><a href="assignDriver.php" class="nav-link">Vehicles</a></li>
                    <li><a href="reports.php" class="nav-link">Incidents</a></li>
                </ul>
            </div>
            
            <div class="navbar-right">
                <!-- Notification Bell -->
                <div class="notification-container">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fas fa-bell notification-icon"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <?php if($unread_count > 0): ?>
                                <button class="mark-all-read" onclick="markAllAsRead()">Mark All Read</button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-list">
                            <?php if(count($recent_notifications) > 0): ?>
                                <?php foreach($recent_notifications as $notification): ?>
                                    <div class="notification-item <?php echo $notification['Status'] == 'Unread' ? 'unread' : ''; ?>" 
                                         onclick="markAsRead(<?php echo $notification['Notification_ID']; ?>)">
                                        <div class="notification-title">
                                            <span><?php echo $notification['Title']; ?></span>
                                            <span class="priority-badge-notification priority-<?php echo strtolower($notification['Priority']); ?>">
                                                <?php echo $notification['Priority']; ?>
                                            </span>
                                        </div>
                                        <div class="notification-message">
                                            <?php echo substr($notification['Message'], 0, 100); ?>
                                            <?php if(strlen($notification['Message']) > 100): ?>...<?php endif; ?>
                                        </div>
                                        <div class="notification-meta">
                                            <span>
                                                <span class="notification-type"><?php echo str_replace('_', ' ', $notification['Type']); ?></span>
                                                <?php if($notification['Route_Name']): ?>
                                                    • Route: <?php echo $notification['Route_Name']; ?>
                                                <?php endif; ?>
                                            </span>
                                            <span><?php echo date('M d, H:i', strtotime($notification['Created_At'])); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-notifications">
                                    <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 10px; color: #ddd;"></i>
                                    <p>No notifications yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Admin Profile -->
                <div class="admin-profile">
                    <img src="../assets/mmuShuttleLogo2.png" alt="Coordinator" class="profile-pic">
                    <div class="user-badge">
                        <?php echo $_SESSION['username']; ?> 
                    </div>
                    <div class="profile-menu">
                        <button class="logout-btn" onclick="logout()">Logout</button>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <span class="role-badge">COORDINATOR PANEL</span>
            <h1>Welcome, <?php echo $coordinator['Full_Name']; ?>!</h1>
            <p>Monitor campus shuttle operations, manage schedules, and handle incidents.</p>
            <p style="color: #666; font-size: 14px; margin-top: 10px;">
                Last Update: <?php echo date('Y-m-d H:i:s'); ?>
            </p>
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
                        Active Incidents
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
                        Upcoming Schedules
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
                    <h3 class="section-title">Quick Actions</h3>
                    <div class="quick-actions">
                        <button class="action-btn secondary" onclick="window.location.href='manageRoutePage.php'">
                            <i class="fas fa-route"></i> Manage Routes
                        </button>
                        <button class="action-btn secondary" onclick="window.location.href='assignDriver.php'">
                            <i class="fas fa-user-tie"></i> Assign Driver
                        </button>
                        <button class="action-btn secondary" onclick="window.location.href='reports.php'">
                            <i class="fas fa-chart-bar"></i> Incident Report
                        </button>
                    </div>
                </div>
                
                <!-- System Health -->
                <div class="system-health">
                    <h3>System Health</h3>
                    <div class="progress-item">
                        <div class="progress-label">
                            <span>Shuttle On-Time Rate</span>
                            <span style="color: #4CAF50; font-weight: 600;">92%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-rate-92"></div>
                        </div>
                    </div>
                    
                    <div class="progress-item">
                        <div class="progress-label">
                            <span>Vehicle Availability</span>
                            <span style="color: #2196F3; font-weight: 600;">85%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-rate-85"></div>
                        </div>
                    </div>
                    
                    <div class="progress-item">
                        <div class="progress-label">
                            <span>Passenger Satisfaction</span>
                            <span style="color: #9C27B0; font-weight: 600;">88%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-rate-88"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Notification dropdown functionality
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        // Toggle notification dropdown
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.style.display = 'none';
            }
        });
        
        // Mark single notification as read
        function markAsRead(notificationId) {
            // Create a form to submit the request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'mark_notification_read.php';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'notification_id';
            input.value = notificationId;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
        
        // Mark all notifications as read
        function markAllAsRead() {
            if(confirm('Mark all notifications as read?')) {
                // Create a form to submit the request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'mark_all_notifications_read.php';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_id';
                input.value = <?php echo $user_id; ?>;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Logout function
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        // Auto-update time
        document.addEventListener('DOMContentLoaded', function() {
            function updateTime() {
                const timeElement = document.querySelector('.welcome-section p:nth-child(4)');
                if(timeElement) {
                    const now = new Date();
                    timeElement.textContent = 'Last Update: ' + now.toLocaleString();
                }
            }
            
            // Update every minute
            setInterval(updateTime, 60000);
            
            // Add hover effects
            const statCards = document.querySelectorAll('.stat-card');
            const actionCards = document.querySelectorAll('.section-card');
            
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 8px 20px rgba(0,0,0,0.1)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.05)';
                });
            });
            
            actionCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Auto-refresh dashboard every 5 minutes
            setInterval(function() {
                location.reload();
            }, 300000);
        });
    </script>
</body>
</html>