<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../campus-shuttle-admin-html/adminLoginPage.php');
    exit();
}

// Get admin info from database
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM user WHERE User_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Get system statistics
$user_count = $conn->query("SELECT COUNT(*) as count FROM user")->fetch_assoc()['count'];
$student_count = $conn->query("SELECT COUNT(*) as count FROM student_profile")->fetch_assoc()['count'];
$driver_count = $conn->query("SELECT COUNT(*) as count FROM driver_profile")->fetch_assoc()['count'];
$vehicle_count = $conn->query("SELECT COUNT(*) as count FROM vehicle")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Campus Shuttle</title>
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
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
            color: #F44336;
            border: none;
            padding: 8px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .logout-btn:hover {
            background: #ffebee;
        }
        
        .dashboard-container {
            padding: 30px ;
            max-width: 1200px;
            margin: 80px auto;
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
            background: #F44336;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 20px;
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
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #F44336;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .admin-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .action-card:hover {
            border-color: #F44336;
            transform: translateY(-5px);
        }
        
        .action-icon {
            font-size: 40px;
            margin-bottom: 15px;
            color: #F44336;
        }
        
        .action-card h3 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .action-card p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .quick-links {
            margin-top: 40px;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .quick-links h3 {
            margin-bottom: 20px;
            color: #333;
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
        }
        
        .link-item:hover {
            background: #F44336;
            color: white;
            border-color: #F44336;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 20px;
            }
            
            .navbar {
                flex-direction: column;
                height: auto;
                padding: 15px;
                gap: 15px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
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
            <div class="admin-profile">
                <img src="../assets/mmuShuttleLogo2.png" alt="Admin" class="profile-pic">
                <div class="user-badge">
                    <?php echo $_SESSION['username']; ?> 
                </div>
                <div class="profile-menu">
                    <button class="logout-btn" onclick="window.location.href='../logout.php'">Logout</button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <span class="role-badge">ADMINISTRATOR PANEL</span>
            <h1>Welcome, <?php echo $_SESSION['username']; ?>!</h1>
            <p>You have full system access to manage the Campus Shuttle System.</p>
            <p style="color: #666; font-size: 14px; margin-top: 10px;">
                Last Login: <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Users</div>
                <div class="stat-number"><?php echo $user_count; ?></div>
                <div class="stat-label">Registered in system</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Students</div>
                <div class="stat-number"><?php echo $student_count; ?></div>
                <div class="stat-label">Active student accounts</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Drivers</div>
                <div class="stat-number"><?php echo $driver_count; ?></div>
                <div class="stat-label">Licensed drivers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Vehicles</div>
                <div class="stat-number"><?php echo $vehicle_count; ?></div>
                <div class="stat-label">Active shuttle vehicles</div>
            </div>
        </div>
        
        <!-- Admin Actions -->
        <div class="admin-actions">
            <a href="manageUserPage.php" class="action-card" style="text-decoration: none; color: inherit;">
                <div class="action-icon">👥</div>
                <h3>Manage Users</h3>
                <p>Create, edit, or delete user accounts for students, drivers, and coordinators.</p>
            </a>
            
            <div class="action-card" onclick="alert('Vehicle Management - Coming Soon')">
                <div class="action-icon">🚌</div>
                <h3>Manage Vehicles</h3>
                <p>Add new shuttle vehicles, update capacity, or mark vehicles for maintenance.</p>
            </div>
            
            <div class="action-card" onclick="alert('System Reports - Coming Soon')">
                <div class="action-icon">📊</div>
                <h3>System Reports</h3>
                <p>View analytics, generate reports, and monitor system performance.</p>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="quick-links">
            <h3>Quick Access Links</h3>
            <div class="links-list">
                <a href="manageUserPage.php" class="link-item">User Management</a>
                <a href="manageRolesPage.php" class="link-item">Role Management</a>
                <a href="#" class="link-item">Vehicle Management</a>
                <a href="#" class="link-item">Route Management</a>
                <a href="#" class="link-item">System Settings</a>
                <a href="#" class="link-item">Audit Logs</a>
                <a href="#" class="link-item">Backup Database</a>
                <a href="#" class="link-item">System Health</a>
            </div>
        </div>
    </div>
    
    <script>
        // Add interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Update time every minute
            function updateTime() {
                const timeElement = document.querySelector('.welcome-section p:nth-child(4)');
                if(timeElement) {
                    const now = new Date();
                    timeElement.textContent = 'Last Login: ' + now.toLocaleString();
                }
            }
            
            // Update every minute
            setInterval(updateTime, 60000);
            
            // Add confirmation for logout
            document.querySelector('.logout-btn').addEventListener('click', function(e) {
                if(!confirm('Are you sure you want to logout?')) {
                    e.preventDefault();
                }
            });
            
            // Action card animations
            const actionCards = document.querySelectorAll('.action-card');
            actionCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
    </script>
</body>
</html>