<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Student
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Student') {
    header('Location: ../student_login.php');
    exit();
}

// Get student info
$user_id = $_SESSION['user_id'];
$sql = "SELECT u.*, sp.* FROM user u 
        JOIN student_profile sp ON u.User_ID = sp.User_ID
        WHERE u.User_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

// Get student's upcoming bookings
$sql_bookings = "SELECT sr.*, ss.*, r.Route_Name, v.Plate_number 
                 FROM seat_reservation sr
                 JOIN shuttle_schedule ss ON sr.Schedule_ID = ss.Schedule_ID
                 JOIN route r ON ss.Route_ID = r.Route_ID
                 JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
                 WHERE sr.Student_ID = ? AND sr.Status = 'Reserved' 
                 AND ss.Departure_time > NOW()
                 ORDER BY ss.Departure_time ASC
                 LIMIT 5";
$stmt_bookings = $conn->prepare($sql_bookings);
$stmt_bookings->bind_param("i", $student['Student_ID']);
$stmt_bookings->execute();
$upcoming_bookings = $stmt_bookings->get_result();

// Get active shuttles
$active_shuttles = $conn->query("
    SELECT ss.*, r.Route_Name, v.Plate_number, d.Full_Name as Driver_Name
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    JOIN user d ON ss.Driver_ID = d.User_ID
    WHERE ss.Status = 'In Progress'
    ORDER BY ss.Expected_Arrival ASC
    LIMIT 3
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
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
            background: #2196F3;
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
            color: #2196F3;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .logout-btn:hover {
            background: #E3F2FD;
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
            background: #2196F3;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .student-info {
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
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
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
            border-bottom: 2px solid #2196F3;
        }
        
        .booking-list {
            list-style: none;
        }
        
        .booking-item {
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .booking-item:hover {
            border-color: #2196F3;
            background: #f8f9fa;
        }
        
        .booking-route {
            font-weight: 600;
            color: #2196F3;
            margin-bottom: 5px;
        }
        
        .booking-details {
            font-size: 14px;
            color: #666;
        }
        
        .shuttle-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .status-on-time {
            background: #4CAF50;
            color: white;
        }
        
        .status-delayed {
            background: #FF9800;
            color: white;
        }
        
        .status-in-progress {
            background: #2196F3;
            color: white;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }
        
        .action-btn {
            background: #2196F3;
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
            background: #1976D2;
            transform: translateY(-2px);
        }
        
        .action-btn.secondary {
            background: #f8f9fa;
            color: #333;
            border: 2px solid #2196F3;
        }
        
        .action-btn.secondary:hover {
            background: #E3F2FD;
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
        <div class="logo">🚌 Campus Shuttle - Student Portal</div>
        <div class="user-info">
            <div class="user-badge">
                <?php echo $_SESSION['username']; ?> (Student)
            </div>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <span class="role-badge">STUDENT PORTAL</span>
            <h1>Welcome, <?php echo $student['Full_Name']; ?>!</h1>
            <p>Manage your shuttle bookings and track campus transportation.</p>
            
            <div class="student-info">
                <div class="info-item">
                    <span class="info-label">Student ID</span>
                    <span class="info-value"><?php echo $student['Student_Number']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Faculty</span>
                    <span class="info-value"><?php echo $student['Faculty']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Year of Study</span>
                    <span class="info-value">Year <?php echo $student['Year_Of_Study']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Emergency Contact</span>
                    <span class="info-value"><?php echo $student['Emergency_contact']; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Main Content Grid -->
        <div class="dashboard-grid">
            <!-- Left Column: Bookings & Actions -->
            <div class="left-column">
                <div class="section-card">
                    <h3 class="section-title">📅 My Upcoming Bookings</h3>
                    <?php if($upcoming_bookings->num_rows > 0): ?>
                        <ul class="booking-list">
                            <?php while($booking = $upcoming_bookings->fetch_assoc()): ?>
                                <li class="booking-item">
                                    <div class="booking-route">
                                        <?php echo $booking['Route_Name']; ?>
                                        <span class="shuttle-status status-on-time">Reserved</span>
                                    </div>
                                    <div class="booking-details">
                                        <strong>Departure:</strong> <?php echo date('M d, H:i', strtotime($booking['Departure_time'])); ?><br>
                                        <strong>Vehicle:</strong> <?php echo $booking['Plate_number']; ?> | 
                                        <strong>Seat:</strong> #<?php echo $booking['Seat_number']; ?>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div class="no-data">No upcoming bookings. Book your first shuttle!</div>
                    <?php endif; ?>
                </div>
                
                <div class="quick-actions">
                    <button class="action-btn" onclick="window.location.href='book_shuttle.php'">
                        🚌 Book Shuttle
                    </button>
                    <button class="action-btn secondary" onclick="window.location.href='my_bookings.php'">
                        📋 View All Bookings
                    </button>
                    <button class="action-btn secondary" onclick="window.location.href='track_shuttle.php'">
                        🗺️ Track Shuttle
                    </button>
                </div>
            </div>
            
            <!-- Right Column: Active Shuttles -->
            <div class="right-column">
                <div class="section-card">
                    <h3 class="section-title">🚍 Active Shuttles Now</h3>
                    <?php if(count($active_shuttles) > 0): ?>
                        <?php foreach($active_shuttles as $shuttle): ?>
                            <div class="booking-item">
                                <div class="booking-route">
                                    <?php echo $shuttle['Route_Name']; ?>
                                    <span class="shuttle-status status-in-progress">Live</span>
                                </div>
                                <div class="booking-details">
                                    <strong>Driver:</strong> <?php echo $shuttle['Driver_Name']; ?><br>
                                    <strong>Vehicle:</strong> <?php echo $shuttle['Plate_number']; ?><br>
                                    <strong>ETA:</strong> <?php echo date('H:i', strtotime($shuttle['Expected_Arrival'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">No active shuttles at the moment</div>
                    <?php endif; ?>
                </div>
                
                <div class="section-card" style="margin-top: 20px;">
                    <h3 class="section-title">📢 Announcements</h3>
                    <div class="booking-item">
                        <div class="booking-route">🚨 Route A Detour</div>
                        <div class="booking-details">
                            Route A (Main Gate to Library) will be detoured due to construction until March 15.
                        </div>
                    </div>
                    <div class="booking-item">
                        <div class="booking-route">🎉 Weekend Schedule</div>
                        <div class="booking-details">
                            Weekend shuttle service will be available from 8 AM to 10 PM.
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
        
        // Auto-refresh active shuttles every 30 seconds
        setInterval(function() {
            // In a real app, you would fetch new data via AJAX
            console.log('Auto-refreshing shuttle data...');
        }, 30000);
    </script>
</body>
</html>