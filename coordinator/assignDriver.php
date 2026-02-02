<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Transport Coordinator
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

// Initialize variables
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['assign_driver'])) {
        $schedule_id = intval($_POST['schedule_id']);
        $driver_id = intval($_POST['driver_id']);
        
        // Check if driver is already assigned to another schedule at the same time
        $check_sql = "SELECT s1.* FROM shuttle_schedule s1 
                     WHERE s1.Driver_ID = ? 
                     AND s1.Schedule_ID != ?
                     AND EXISTS (
                         SELECT 1 FROM shuttle_schedule s2 
                         WHERE s2.Schedule_ID = ?
                         AND (
                             (s1.Departure_time BETWEEN s2.Departure_time AND DATE_ADD(s2.Departure_time, INTERVAL 4 HOUR))
                             OR (DATE_ADD(s1.Departure_time, INTERVAL 4 HOUR) BETWEEN s2.Departure_time AND DATE_ADD(s2.Departure_time, INTERVAL 4 HOUR))
                         )
                     )
                     AND s1.Status IN ('Scheduled', 'In Progress')";
        
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iii", $driver_id, $schedule_id, $schedule_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Driver is already assigned to another schedule at this time!";
        } else {
            // Update schedule with driver assignment
            $update_sql = "UPDATE shuttle_schedule SET Driver_ID = ? WHERE Schedule_ID = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $driver_id, $schedule_id);
            
            if ($update_stmt->execute()) {
                // Also update driver's assigned vehicle based on the schedule's vehicle
                $vehicle_sql = "SELECT v.Plate_number FROM shuttle_schedule ss
                               JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
                               WHERE ss.Schedule_ID = ?";
                $vehicle_stmt = $conn->prepare($vehicle_sql);
                $vehicle_stmt->bind_param("i", $schedule_id);
                $vehicle_stmt->execute();
                $vehicle_result = $vehicle_stmt->get_result();
                
                if ($vehicle_row = $vehicle_result->fetch_assoc()) {
                    $update_vehicle_sql = "UPDATE driver_profile SET Assigned_Vehicle = ? WHERE User_ID = ?";
                    $update_vehicle_stmt = $conn->prepare($update_vehicle_sql);
                    $update_vehicle_stmt->bind_param("si", $vehicle_row['Plate_number'], $driver_id);
                    $update_vehicle_stmt->execute();
                }
                
                $message = "✅ Driver assigned successfully!";
            } else {
                $error = "❌ Error assigning driver: " . $conn->error;
            }
        }
    } elseif (isset($_POST['remove_assignment'])) {
        $schedule_id = intval($_POST['schedule_id']);
        
        // Get driver ID before removing
        $get_driver_sql = "SELECT Driver_ID FROM shuttle_schedule WHERE Schedule_ID = ?";
        $get_driver_stmt = $conn->prepare($get_driver_sql);
        $get_driver_stmt->bind_param("i", $schedule_id);
        $get_driver_stmt->execute();
        $driver_result = $get_driver_stmt->get_result();
        $driver_data = $driver_result->fetch_assoc();
        $driver_id = $driver_data['Driver_ID'];
        
        $update_sql = "UPDATE shuttle_schedule SET Driver_ID = NULL WHERE Schedule_ID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $schedule_id);
        
        if ($update_stmt->execute()) {
            // Clear driver's assigned vehicle if they have no other schedules
            $check_other_schedules = "SELECT COUNT(*) as schedule_count FROM shuttle_schedule 
                                     WHERE Driver_ID = ? AND Status IN ('Scheduled', 'In Progress')";
            $check_stmt = $conn->prepare($check_other_schedules);
            $check_stmt->bind_param("i", $driver_id);
            $check_stmt->execute();
            $count_result = $check_stmt->get_result();
            $count_data = $count_result->fetch_assoc();
            
            if ($count_data['schedule_count'] == 0) {
                $clear_vehicle_sql = "UPDATE driver_profile SET Assigned_Vehicle = NULL WHERE User_ID = ?";
                $clear_vehicle_stmt = $conn->prepare($clear_vehicle_sql);
                $clear_vehicle_stmt->bind_param("i", $driver_id);
                $clear_vehicle_stmt->execute();
            }
            
            $message = "✅ Driver assignment removed successfully!";
        } else {
            $error = "❌ Error removing assignment: " . $conn->error;
        }
    }
}

// Get all available drivers (hardcoded as driver1 and driver1 is User_ID 5)
$drivers = $conn->query("
    SELECT u.User_ID, u.Full_Name, u.Email, 
           dp.License_Number, dp.Assigned_Vehicle,
           (SELECT COUNT(*) FROM shuttle_schedule WHERE Driver_ID = u.User_ID AND Status IN ('Scheduled', 'In Progress')) as schedule_count
    FROM user u
    LEFT JOIN driver_profile dp ON u.User_ID = dp.User_ID
    WHERE u.Username LIKE '%driver%' OR dp.License_Number IS NOT NULL
    ORDER BY u.Full_Name
")->fetch_all(MYSQLI_ASSOC);

// Get schedules needing driver assignment
$schedules = $conn->query("
    SELECT ss.*, r.Route_Name, r.Start_Location, r.End_Location,
           v.Plate_number, v.Model, v.Capacity, 
           u.Full_Name as assigned_driver,
           (SELECT COUNT(*) FROM seat_reservation sr WHERE sr.Schedule_ID = ss.Schedule_ID AND sr.Status = 'Reserved') as total_bookings,
           ss.Available_Seats
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    LEFT JOIN user u ON ss.Driver_ID = u.User_ID
    WHERE ss.Departure_time > NOW()
    AND ss.Status IN ('Scheduled', 'In Progress')
    ORDER BY ss.Departure_time ASC
")->fetch_all(MYSQLI_ASSOC);

// Get driver schedule conflicts for the next 24 hours
$conflicts = $conn->query("
    SELECT 
        d.User_ID,
        d.Full_Name,
        COUNT(DISTINCT ss.Schedule_ID) as conflict_count,
        GROUP_CONCAT(DISTINCT CONCAT(r.Route_Name, ' (', TIME(ss.Departure_time), ')') ORDER BY ss.Departure_time ASC SEPARATOR ', ') as conflicting_schedules
    FROM user d
    JOIN shuttle_schedule ss ON d.User_ID = ss.Driver_ID
    JOIN route r ON ss.Route_ID = r.Route_ID
    WHERE ss.Departure_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
    AND ss.Status IN ('Scheduled', 'In Progress')
    GROUP BY d.User_ID
    HAVING conflict_count > 1
")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$assigned_schedules = $conn->query("
    SELECT COUNT(*) as count 
    FROM shuttle_schedule 
    WHERE Driver_ID IS NOT NULL 
    AND Departure_time > NOW()
    AND Status IN ('Scheduled', 'In Progress')
")->fetch_assoc()['count'];

$unassigned_schedules = $conn->query("
    SELECT COUNT(*) as count 
    FROM shuttle_schedule 
    WHERE Driver_ID IS NULL 
    AND Departure_time > NOW()
    AND Status IN ('Scheduled', 'In Progress')
")->fetch_assoc()['count'];

// Get current date for display
$current_date = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Driver - Coordinator Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: white;
            color: #333;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 70px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #764ba2;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-badge {
            background: #667eea;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .nav-links {
            display: flex;
            gap: 10px;
        }
        
        .nav-btn {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .nav-btn:hover {
            background: #667eea;
            color: white;
        }
        
        .logout-btn {
            background: #ff6b6b;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: #ff5252;
        }
        
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-title h1 {
            color: #333;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .back-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .message {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .section-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .section-card:hover {
            transform: translateY(-5px);
        }
        
        .section-title {
            font-size: 22px;
            color: #333;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 5px solid #667eea;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .info-box h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .info-box p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        
        .info-box ul {
            padding-left: 20px;
            color: #666;
            margin-top: 10px;
        }
        
        .info-box li {
            margin-bottom: 5px;
            padding-left: 5px;
        }
        
        /* Driver List Styles */
        .driver-list {
            max-height: 500px;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .driver-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .driver-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .driver-list::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 4px;
        }
        
        .driver-item {
            padding: 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .driver-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: #667eea;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .driver-item:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }
        
        .driver-item:hover::before {
            opacity: 1;
        }
        
        .driver-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .driver-name {
            font-weight: 600;
            color: #333;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .driver-license {
            font-size: 12px;
            color: white;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 4px 10px;
            border-radius: 10px;
            font-weight: 500;
        }
        
        .driver-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .driver-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #999;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .status-busy {
            color: #ff6b6b;
            font-weight: 600;
            background: #ffeaea;
            padding: 3px 10px;
            border-radius: 15px;
        }
        
        .status-available {
            color: #51cf66;
            font-weight: 600;
            background: #ebfbee;
            padding: 3px 10px;
            border-radius: 15px;
        }
        
        /* Schedule Table Styles */
        .schedule-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .schedule-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border: none;
            position: sticky;
            top: 0;
        }
        
        .schedule-table th:first-child {
            border-top-left-radius: 10px;
        }
        
        .schedule-table th:last-child {
            border-top-right-radius: 10px;
        }
        
        .schedule-table td {
            padding: 18px 15px;
            border-bottom: 1px solid #e9ecef;
            background: white;
            transition: background 0.3s;
        }
        
        .schedule-table tr:hover td {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%);
        }
        
        .schedule-table tr:last-child td:first-child {
            border-bottom-left-radius: 10px;
        }
        
        .schedule-table tr:last-child td:last-child {
            border-bottom-right-radius: 10px;
        }
        
        .schedule-time {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .schedule-date {
            font-size: 13px;
            color: #999;
            margin-top: 5px;
        }
        
        .schedule-route {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .route-details {
            font-size: 12px;
            color: #666;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 5px;
            display: inline-block;
            margin-top: 5px;
        }
        
        .schedule-vehicle {
            font-size: 14px;
            color: #666;
            margin-top: 8px;
        }
        
        .booking-count {
            display: inline-block;
            background: #e9ecef;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 12px;
            margin-left: 5px;
            font-weight: 500;
        }
        
        .assign-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .driver-select {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            min-width: 200px;
            font-size: 14px;
            color: #333;
            transition: border-color 0.3s;
        }
        
        .driver-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .assign-btn {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .assign-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(81, 207, 102, 0.4);
        }
        
        .remove-btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #fa5252 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .remove-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }
        
        .assigned-driver {
            color: #51cf66;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #ebfbee;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .no-driver {
            color: #ff922b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff4e6;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        /* Conflict Section */
        .conflict-section {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #ffd43b;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { border-color: #ffd43b; }
            50% { border-color: #ffc107; }
            100% { border-color: #ffd43b; }
        }
        
        .conflict-title {
            color: #856404;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }
        
        .conflict-list {
            list-style: none;
        }
        
        .conflict-item {
            padding: 12px;
            background: white;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #ff922b;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 40px 20px;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        
        .legend {
            display: flex;
            gap: 25px;
            margin-top: 20px;
            padding: 15px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            font-size: 13px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-color {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .legend-high {
            background: linear-gradient(135deg, #ff6b6b 0%, #fa5252 100%);
        }
        
        .legend-medium {
            background: linear-gradient(135deg, #ff922b 0%, #fd7e14 100%);
        }
        
        .legend-low {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-scheduled {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1976d2;
        }
        
        .status-progress {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #f57c00;
        }
        
        .status-completed {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #388e3c;
        }
        
        .status-cancelled {
            background: linear-gradient(135deg, #fce4ec 0%, #f8bbd9 100%);
            color: #c2185b;
        }
        
        .status-delayed {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #ff8f00;
        }
        
        .seats-info {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .seats-available {
            color: #51cf66;
        }
        
        .seats-low {
            color: #ff922b;
        }
        
        .seats-full {
            color: #ff6b6b;
        }
        
        /* Quick Stats */
        .stats-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            transition: transform 0.3s;
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 42px;
            font-weight: bold;
            margin: 10px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
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
            
            .assign-form {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .driver-select {
                width: 100%;
            }
            
            .legend {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .schedule-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">
            🚌 Campus Shuttle Coordinator
        </div>
        <div class="user-info">
            <div class="nav-links">
                <a href="coordinator_dashboard.php" class="nav-btn">📊 Dashboard</a>
                <a href="createSchedule.php" class="nav-btn">📅 Schedules</a>
                <a href="manageRoute.php" class="nav-btn">🗺️ Routes</a>
            </div>
            <div class="user-badge">👤 <?php echo $_SESSION['username']; ?> (Coordinator)</div>
            <button class="logout-btn" onclick="logout()">🚪 Logout</button>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-title">
            <div>
                <h1>👨‍✈️ Assign Driver to Schedules</h1>
                <p style="color: #666; margin-top: 8px; font-size: 14px;">
                    Manage driver assignments for upcoming shuttle schedules. System time: <?php echo date('H:i:s, F j, Y'); ?>
                </p>
            </div>
            <a href="coordinator_dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>
        
        <!-- Messages -->
        <?php if($message): ?>
            <div class="message success">
                <span style="font-size: 20px;">✅</span>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>
        
        <?php if($error): ?>
            <div class="message error">
                <span style="font-size: 20px;">❌</span>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Driver Conflicts Warning -->
        <?php if(count($conflicts) > 0): ?>
            <div class="conflict-section">
                <div class="conflict-title">
                    ⚠️ Driver Schedule Conflicts Detected
                </div>
                <ul class="conflict-list">
                    <?php foreach($conflicts as $conflict): ?>
                        <li class="conflict-item">
                            <strong><?php echo $conflict['Full_Name']; ?></strong> has 
                            <span style="color: #ff6b6b; font-weight: 600;"><?php echo $conflict['conflict_count']; ?> overlapping schedules</span> 
                            in next 24 hours: 
                            <?php echo $conflict['conflicting_schedules']; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Main Content Grid -->
        <div class="dashboard-grid">
            <!-- Left Column: Available Drivers -->
            <div class="left-column">
                <div class="section-card">
                    <h2 class="section-title">👨‍✈️ Available Drivers</h2>
                    
                    <div class="info-box">
                        <h4>📋 Driver Information</h4>
                        <p>Total Drivers: <strong><?php echo count($drivers); ?></strong></p>
                        <p>Select a driver to assign to a schedule. The system automatically checks for schedule conflicts and shows driver availability.</p>
                        <ul>
                            <li><span style="color: #51cf66;">● Available</span>: 0-1 upcoming schedules</li>
                            <li><span style="color: #ff922b;">● Busy</span>: 2-3 upcoming schedules</li>
                            <li><span style="color: #ff6b6b;">● Very Busy</span>: 4+ upcoming schedules</li>
                        </ul>
                    </div>
                    
                    <?php if(count($drivers) > 0): ?>
                        <div class="driver-list">
                            <?php foreach($drivers as $driver): ?>
                                <?php
                                $schedule_count = $driver['schedule_count'] ?? 0;
                                $status_class = 'status-available';
                                $status_text = 'Available';
                                
                                if ($schedule_count >= 4) {
                                    $status_class = 'status-busy';
                                    $status_text = 'Very Busy';
                                } elseif ($schedule_count >= 2) {
                                    $status_class = 'status-busy';
                                    $status_text = 'Busy';
                                }
                                ?>
                                
                                <div class="driver-item" id="driver-<?php echo $driver['User_ID']; ?>">
                                    <div class="driver-header">
                                        <div class="driver-name">
                                            <span>👨‍✈️</span>
                                            <?php echo $driver['Full_Name']; ?>
                                        </div>
                                        <div class="driver-license">
                                            <?php echo $driver['License_Number'] ?? 'LIC-N/A'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="driver-details">
                                        <div>📧 <?php echo $driver['Email']; ?></div>
                                        <?php if($driver['Assigned_Vehicle']): ?>
                                            <div style="margin-top: 8px;">
                                                🚙 Assigned Vehicle: 
                                                <span style="font-weight: 600; color: #333;"><?php echo $driver['Assigned_Vehicle']; ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-top: 8px;">
                                                🚙 No vehicle currently assigned
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="driver-status">
                                        <div>
                                            📅 Upcoming Schedules: 
                                            <span style="font-weight: 600; color: #333;"><?php echo $schedule_count; ?></span>
                                        </div>
                                        <span class="<?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="legend">
                            <div class="legend-item">
                                <div class="legend-color legend-low"></div>
                                <span>Available (0-1 schedules)</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color legend-medium"></div>
                                <span>Busy (2-3 schedules)</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color legend-high"></div>
                                <span>Very Busy (4+ schedules)</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <div style="font-size: 48px; margin-bottom: 10px;">👨‍✈️</div>
                            <h3 style="color: #666; margin-bottom: 10px;">No Drivers Available</h3>
                            <p>Please add drivers to the system first.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column: Schedules -->
            <div class="right-column">
                <div class="section-card">
                    <h2 class="section-title">📅 Upcoming Schedules</h2>
                    
                    <div class="info-box">
                        <h4>📊 Schedule Overview</h4>
                        <p>Total Schedules: <strong><?php echo count($schedules); ?></strong> 
                        | Assigned: <strong style="color: #51cf66;"><?php echo $assigned_schedules; ?></strong> 
                        | Pending: <strong style="color: #ff922b;"><?php echo $unassigned_schedules; ?></strong></p>
                        <p>Assign drivers to upcoming shuttle schedules. Each schedule can only have one driver assigned at a time.</p>
                    </div>
                    
                    <?php if(count($schedules) > 0): ?>
                        <div style="overflow-x: auto;">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Route Details</th>
                                        <th>Seats</th>
                                        <th>Status</th>
                                        <th>Driver</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($schedules as $schedule): ?>
                                        <?php
                                        $seat_ratio = ($schedule['total_bookings'] / $schedule['Capacity']) * 100;
                                        $seat_class = 'seats-available';
                                        if ($seat_ratio >= 90) {
                                            $seat_class = 'seats-full';
                                        } elseif ($seat_ratio >= 70) {
                                            $seat_class = 'seats-low';
                                        }
                                        
                                        $status_class = 'status-' . strtolower(str_replace(' ', '-', $schedule['Status']));
                                        ?>
                                        
                                        <tr>
                                            <td>
                                                <div class="schedule-time">
                                                    <?php echo date('H:i', strtotime($schedule['Departure_time'])); ?>
                                                </div>
                                                <div class="schedule-date">
                                                    <?php echo date('M d, Y', strtotime($schedule['Departure_time'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="schedule-route">
                                                    <?php echo $schedule['Route_Name']; ?>
                                                </div>
                                                <div class="route-details">
                                                    🏁 <?php echo $schedule['Start_Location']; ?> → 🎯 <?php echo $schedule['End_Location']; ?>
                                                </div>
                                                <div class="schedule-vehicle">
                                                    🚌 <?php echo $schedule['Model']; ?> 
                                                    (<?php echo $schedule['Plate_number']; ?>)
                                                </div>
                                            </td>
                                            <td>
                                                <div class="seats-info <?php echo $seat_class; ?>">
                                                    <?php echo $schedule['total_bookings']; ?> / <?php echo $schedule['Capacity']; ?> seats
                                                </div>
                                                <div class="booking-count">
                                                    <?php echo $schedule['Available_Seats']; ?> seats available
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $schedule['Status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($schedule['assigned_driver']): ?>
                                                    <div class="assigned-driver">
                                                        ✅ <?php echo $schedule['assigned_driver']; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="no-driver">
                                                        ⚠️ Not Assigned
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" class="assign-form">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $schedule['Schedule_ID']; ?>">
                                                    
                                                    <?php if($schedule['assigned_driver']): ?>
                                                        <button type="submit" name="remove_assignment" class="remove-btn">
                                                            Remove
                                                        </button>
                                                    <?php else: ?>
                                                        <select name="driver_id" class="driver-select" required>
                                                            <option value="">Select Driver...</option>
                                                            <?php foreach($drivers as $driver): ?>
                                                                <?php
                                                                $schedule_count = $driver['schedule_count'] ?? 0;
                                                                $driver_status = '';
                                                                if ($schedule_count >= 4) {
                                                                    $driver_status = ' (Very Busy)';
                                                                } elseif ($schedule_count >= 2) {
                                                                    $driver_status = ' (Busy)';
                                                                } else {
                                                                    $driver_status = ' (Available)';
                                                                }
                                                                ?>
                                                                <option value="<?php echo $driver['User_ID']; ?>">
                                                                    <?php echo $driver['Full_Name']; ?> 
                                                                    <?php echo $driver_status; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="assign_driver" class="assign-btn">
                                                            Assign
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <div style="font-size: 48px; margin-bottom: 10px;">📅</div>
                            <h3 style="color: #666; margin-bottom: 10px;">No Upcoming Schedules</h3>
                            <p>Please create shuttle schedules first.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Stats -->
                <div class="section-card">
                    <h2 class="section-title">📊 Assignment Statistics</h2>
                    <div class="stats-container">
                        <div class="stat-item">
                            <div>👨‍✈️</div>
                            <div class="stat-number"><?php echo count($drivers); ?></div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                        <div class="stat-item">
                            <div>✅</div>
                            <div class="stat-number"><?php echo $assigned_schedules; ?></div>
                            <div class="stat-label">Assigned Schedules</div>
                        </div>
                        <div class="stat-item">
                            <div>⚠️</div>
                            <div class="stat-number"><?php echo $unassigned_schedules; ?></div>
                            <div class="stat-label">Pending Schedules</div>
                        </div>
                        <div class="stat-item">
                            <div>🚨</div>
                            <div class="stat-number"><?php echo count($conflicts); ?></div>
                            <div class="stat-label">Conflicts Detected</div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="color: #666; font-size: 14px;">Next 24 Hours:</span>
                            <span style="font-weight: 600; color: <?php echo count($conflicts) > 0 ? '#ff6b6b' : '#51cf66'; ?>;">
                                <?php echo count($conflicts); ?> schedule conflicts
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #666; font-size: 14px;">Today's Date:</span>
                            <span style="font-weight: 600; color: #333;">
                                <?php echo date('F j, Y'); ?>
                            </span>
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
        
        document.addEventListener('DOMContentLoaded', function() {
            const driverSelects = document.querySelectorAll('.driver-select');
            
            // Highlight selected driver in list
            driverSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // Remove all highlights
                    document.querySelectorAll('.driver-item').forEach(item => {
                        item.style.border = '2px solid #e9ecef';
                        item.style.boxShadow = 'none';
                        item.style.transform = 'none';
                    });
                    
                    // Highlight selected driver
                    if(this.value) {
                        const driverItem = document.getElementById('driver-' + this.value);
                        if(driverItem) {
                            driverItem.style.border = '2px solid #667eea';
                            driverItem.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.2)';
                            driverItem.style.transform = 'translateY(-5px)';
                            
                            // Scroll to driver item
                            setTimeout(() => {
                                driverItem.scrollIntoView({ 
                                    behavior: 'smooth', 
                                    block: 'center',
                                    inline: 'nearest'
                                });
                            }, 300);
                        }
                    }
                });
                
                // Show current selection on focus
                select.addEventListener('focus', function() {
                    if(this.value) {
                        this.dispatchEvent(new Event('change'));
                    }
                });
            });
            
            // Add loading effect to buttons
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if(submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<span class="loading"></span> Processing...';
                        submitBtn.disabled = true;
                        
                        // Re-enable button after 5 seconds (in case of error)
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 5000);
                    }
                });
            });
            
            // Add auto-refresh notification
            let idleTimer;
            function resetIdleTimer() {
                clearTimeout(idleTimer);
                idleTimer = setTimeout(() => {
                    const notification = document.createElement('div');
                    notification.className = 'message success';
                    notification.style.position = 'fixed';
                    notification.style.top = '100px';
                    notification.style.right = '20px';
                    notification.style.width = '300px';
                    notification.style.zIndex = '1001';
                    notification.innerHTML = `
                        <span style="font-size: 20px;">🔄</span>
                        <span>Refresh page for latest updates?</span>
                        <button onclick="location.reload()" style="
                            margin-left: 10px;
                            background: #667eea;
                            color: white;
                            border: none;
                            padding: 5px 10px;
                            border-radius: 5px;
                            cursor: pointer;
                            font-size: 12px;
                        ">Refresh Now</button>
                    `;
                    document.body.appendChild(notification);
                    
                    // Auto remove after 10 seconds
                    setTimeout(() => {
                        if(notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 10000);
                }, 300000); // 5 minutes
            }
            
            // Reset timer on user interaction
            document.addEventListener('mousemove', resetIdleTimer);
            document.addEventListener('keypress', resetIdleTimer);
            document.addEventListener('click', resetIdleTimer);
            
            // Initialize timer
            resetIdleTimer();
            
            // Add confirmation for remove actions
            document.querySelectorAll('.remove-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    if(!confirm('Are you sure you want to remove the driver from this schedule?\n\nThis will clear their vehicle assignment if they have no other schedules.')) {
                        e.preventDefault();
                    }
                });
            });
            
            // Add hover effect to schedule rows
            const scheduleRows = document.querySelectorAll('.schedule-table tbody tr');
            scheduleRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    const driverId = this.querySelector('select')?.value;
                    if(driverId) {
                        const driverItem = document.getElementById('driver-' + driverId);
                        if(driverItem) {
                            driverItem.style.border = '2px solid #667eea';
                            driverItem.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.2)';
                        }
                    }
                });
                
                row.addEventListener('mouseleave', function() {
                    const driverId = this.querySelector('select')?.value;
                    if(driverId) {
                        const driverItem = document.getElementById('driver-' + driverId);
                        if(driverItem) {
                            driverItem.style.border = '2px solid #e9ecef';
                            driverItem.style.boxShadow = 'none';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>