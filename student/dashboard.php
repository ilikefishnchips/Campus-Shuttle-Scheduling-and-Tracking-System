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
$stmt_active = $conn->prepare("
    SELECT ss.*, r.Route_Name, v.Plate_number, d.Full_Name as Driver_Name
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    JOIN user d ON ss.Driver_ID = d.User_ID
    WHERE ss.Status = 'In Progress'
    ORDER BY ss.Expected_Arrival ASC
    LIMIT 3
");
$stmt_active->execute();
$active_shuttles = $stmt_active->get_result()->fetch_all(MYSQLI_ASSOC);

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
        font-family: 'Segoe UI', Tahoma, sans-serif;
    }

    body {
        background: #f2f2f2;
    }

    /* Navbar */
    .navbar {
        background: #ffffff;
        height: 80px;
        padding: 0 40px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #ddd;
    }

    .nav-logo {
        height: 45px;
    }

    .nav-right {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .user-name {
        font-size: 14px;
        color: #333;
    }

    .nav-btn {
        background: #333;
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
    }

    .nav-btn:hover {
        background: #000;
    }

    /* Page Container */
    .dashboard-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
    }

    /* Page Header */
    .page-header {
        margin-bottom: 30px;
    }

    .page-header h1 {
        font-size: 36px;
        font-weight: 700;
    }

    .page-header p {
        font-size: 18px;
        color: #555;
    }

    /* Cards */
    .section-card {
        background: #fff;
        padding: 25px;
        border-radius: 8px;
        border: 1px solid #ddd;
        margin-bottom: 25px;
    }

    /* Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 25px;
    }

    /* Student Info */
    .student-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .info-label {
        font-size: 12px;
        color: #777;
        text-transform: uppercase;
    }

    .info-value {
        font-size: 16px;
        font-weight: 600;
        margin-top: 5px;
    }

    /* Booking List */
    .booking-item {
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 12px;
    }

    .booking-route {
        font-weight: 600;
        margin-bottom: 6px;
    }

    /* Status */
    .shuttle-status {
        background: #222;
        color: #fff;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12px;
    }

    /* Buttons */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .action-btn {
        background: #222;
        color: white;
        border: none;
        padding: 14px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 15px;
    }

    .action-btn:hover {
        background: #000;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="nav-left">
            <img src="../assets/mmuShuttleLogo2.png" class="nav-logo" alt="MMU Shuttle">
        </div>

        <div class="nav-right">
            <span class="user-name"><?php echo $_SESSION['username']; ?> (Student)</span>
            <button class="nav-btn" onclick="logout()">Log out</button>
        </div>
    </nav>

    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <div class="page-header">
            <h1>Student Dashboard</h1>
            <p>View bookings, manage shuttle services, and track your transportation</p>
        </div>

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
                                    <strong>ETA:</strong>
                                    <?php 
                                    echo $shuttle['Expected_Arrival'] 
                                        ? date('H:i', strtotime($shuttle['Expected_Arrival'])) 
                                        : 'Pending'; 
                                    ?>

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