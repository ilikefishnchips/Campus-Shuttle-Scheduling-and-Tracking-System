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
    if (isset($_POST['add_route'])) {
        // Add new route
        $route_name = trim($_POST['route_name']);
        $start_location = trim($_POST['start_location']);
        $end_location = trim($_POST['end_location']);
        $total_stops = intval($_POST['total_stops']);
        $estimated_duration = intval($_POST['estimated_duration']);
        $status = $_POST['status'];
        
        if (!empty($route_name) && !empty($start_location) && !empty($end_location)) {
            $sql = "INSERT INTO route (Route_Name, Start_Location, End_Location, Total_Stops, Estimated_Duration_Minutes, Status) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssiis", $route_name, $start_location, $end_location, $total_stops, $estimated_duration, $status);
            
            if ($stmt->execute()) {
                $message = "Route added successfully!";
                $message_type = "success";
            } else {
                $message = "Error adding route: " . $conn->error;
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields";
            $message_type = "error";
        }
    }
    elseif (isset($_POST['edit_route'])) {
        // Edit existing route
        $route_id = intval($_POST['route_id']);
        $route_name = trim($_POST['route_name']);
        $start_location = trim($_POST['start_location']);
        $end_location = trim($_POST['end_location']);
        $total_stops = intval($_POST['total_stops']);
        $estimated_duration = intval($_POST['estimated_duration']);
        $status = $_POST['status'];
        
        $sql = "UPDATE route SET 
                Route_Name = ?, 
                Start_Location = ?, 
                End_Location = ?, 
                Total_Stops = ?, 
                Estimated_Duration_Minutes = ?, 
                Status = ? 
                WHERE Route_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisi", $route_name, $start_location, $end_location, $total_stops, $estimated_duration, $status, $route_id);
        
        if ($stmt->execute()) {
            $message = "Route updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating route: " . $conn->error;
            $message_type = "error";
        }
    }
    elseif (isset($_POST['delete_route'])) {
        // Delete route
        $route_id = intval($_POST['route_id']);
        
        // Check if route has active schedules
        $check_sql = "SELECT COUNT(*) as count FROM shuttle_schedule 
                     WHERE Route_ID = ? AND Status IN ('Scheduled', 'In Progress')";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $route_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $schedule_count = $check_result->fetch_assoc()['count'];
        
        if ($schedule_count > 0) {
            $message = "Cannot delete route with active schedules!";
            $message_type = "error";
        } else {
            // Delete the route
            $sql = "DELETE FROM route WHERE Route_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $route_id);
            
            if ($stmt->execute()) {
                $message = "Route deleted successfully!";
                $message_type = "success";
            } else {
                $message = "Error deleting route: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Get all routes
$sql = "SELECT r.*,
        (SELECT COUNT(*) FROM shuttle_schedule ss WHERE ss.Route_ID = r.Route_ID AND ss.Status IN ('Scheduled', 'In Progress')) as active_schedules
        FROM route r 
        ORDER BY r.Status, r.Route_Name";
$routes_result = $conn->query($sql);
$routes = $routes_result->fetch_all(MYSQLI_ASSOC);

// Get route statistics
$route_stats = $conn->query("
    SELECT 
        COUNT(*) as total_routes,
        SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) as active_routes,
        SUM(CASE WHEN Status = 'Inactive' THEN 1 ELSE 0 END) as inactive_routes,
        AVG(Estimated_Duration_Minutes) as avg_duration,
        SUM(Total_Stops) as total_stops
    FROM route
")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Routes - Coordinator</title>
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
        
        .btn-danger {
            background: #F44336;
        }
        
        .btn-danger:hover {
            background: #D32F2F;
        }
        
        .btn-success {
            background: #4CAF50;
        }
        
        .btn-success:hover {
            background: #388E3C;
        }
        
        .routes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .routes-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }
        
        .routes-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .routes-table tr:hover {
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
        
        .status-inactive {
            background: #9E9E9E;
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
        
        .route-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .route-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .info-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-weight: 600;
            color: #333;
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
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 20px;
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
            
            .route-info-grid {
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
            <a href="manage_routes.php" class="nav-link active">Manage Routes</a>
            <a href="create_schedule.php" class="nav-link">Schedules</a>
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
            <h1 class="page-title">🚏 Manage Bus Routes</h1>
            <p class="page-subtitle">Create, edit, and manage campus shuttle routes</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Routes</div>
                <div class="stat-number"><?php echo $route_stats['total_routes']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Active Routes</div>
                <div class="stat-number"><?php echo $route_stats['active_routes']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Avg Duration</div>
                <div class="stat-number"><?php echo round($route_stats['avg_duration']); ?> min</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Stops</div>
                <div class="stat-number"><?php echo $route_stats['total_stops']; ?></div>
            </div>
        </div>
        
        <!-- Message Display -->
        <?php if($message): ?>
            <div class="message message-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column: Add/Edit Route Form -->
            <div class="section-card">
                <h2 class="section-title">Add New Route</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label required">Route Name</label>
                        <input type="text" name="route_name" class="form-control" 
                               placeholder="e.g., Route A - Main Gate to Library" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Start Location</label>
                        <input type="text" name="start_location" class="form-control" 
                               placeholder="e.g., Main Gate" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">End Location</label>
                        <input type="text" name="end_location" class="form-control" 
                               placeholder="e.g., Library" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Total Stops</label>
                        <input type="number" name="total_stops" class="form-control" 
                               min="2" max="20" value="3">
                        <small style="color: #666;">Including start and end locations</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Duration (minutes)</label>
                        <input type="number" name="estimated_duration" class="form-control" 
                               min="5" max="180" value="15">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" name="add_route" class="btn btn-success">
                            🚀 Add New Route
                        </button>
                    </div>
                </form>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                
                <h2 class="section-title">Quick Actions</h2>
                <div class="quick-actions">
                    <button class="btn-secondary" onclick="openRouteMap()">
                        🗺️ View Route Map
                    </button>
                    <button class="btn-secondary" onclick="generateReport()">
                        📊 Generate Report
                    </button>
                    <button class="btn-secondary" onclick="importRoutes()">
                        📥 Import Routes
                    </button>
                    <button class="btn-secondary" onclick="exportRoutes()">
                        📤 Export Routes
                    </button>
                </div>
            </div>
            
            <!-- Right Column: Route List -->
            <div class="section-card">
                <h2 class="section-title">All Routes (<?php echo count($routes); ?>)</h2>
                
                <?php if(count($routes) > 0): ?>
                    <table class="routes-table">
                        <thead>
                            <tr>
                                <th>Route Name</th>
                                <th>Route</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($routes as $route): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $route['Route_Name']; ?></strong>
                                        <?php if($route['active_schedules'] > 0): ?>
                                            <br><small style="color: #666;"><?php echo $route['active_schedules']; ?> active schedule(s)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $route['Start_Location']; ?> → <?php echo $route['End_Location']; ?>
                                        <br><small><?php echo $route['Total_Stops']; ?> stops</small>
                                    </td>
                                    <td><?php echo $route['Estimated_Duration_Minutes']; ?> min</td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($route['Status']); ?>">
                                            <?php echo $route['Status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn btn-secondary" 
                                                    onclick="editRoute(<?php echo $route['Route_ID']; ?>)">
                                                ✏️ Edit
                                            </button>
                                            <button class="action-btn btn-danger" 
                                                    onclick="deleteRoute(<?php echo $route['Route_ID']; ?>, '<?php echo addslashes($route['Route_Name']); ?>')">
                                                🗑️ Delete
                                            </button>
                                            <button class="action-btn btn" 
                                                    onclick="showDetails(<?php echo $route['Route_ID']; ?>)">
                                                👁️ View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Hidden details row -->
                                <tr id="details-<?php echo $route['Route_ID']; ?>" style="display: none;">
                                    <td colspan="5">
                                        <div class="route-details">
                                            <div class="route-info-grid">
                                                <div class="info-item">
                                                    <div class="info-label">Route ID</div>
                                                    <div class="info-value">#<?php echo $route['Route_ID']; ?></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">Route Name</div>
                                                    <div class="info-value"><?php echo $route['Route_Name']; ?></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">Status</div>
                                                    <div class="info-value">
                                                        <span class="status-badge status-<?php echo strtolower($route['Status']); ?>">
                                                            <?php echo $route['Status']; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">Start Location</div>
                                                    <div class="info-value"><?php echo $route['Start_Location']; ?></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">End Location</div>
                                                    <div class="info-value"><?php echo $route['End_Location']; ?></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">Total Stops</div>
                                                    <div class="info-value"><?php echo $route['Total_Stops']; ?></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label">Duration</div>
                                                    <div class="info-value"><?php echo $route['Estimated_Duration_Minutes']; ?> minutes</div>
                                                </div>
                                            </div>
                                            <button onclick="hideDetails(<?php echo $route['Route_ID']; ?>)" 
                                                    class="btn-secondary" style="margin-top: 10px;">
                                                Hide Details
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No routes found. Create your first route!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit Route Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Route</h2>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="route_id" id="edit_route_id">
                
                <div class="form-group">
                    <label class="form-label required">Route Name</label>
                    <input type="text" name="route_name" id="edit_route_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Start Location</label>
                    <input type="text" name="start_location" id="edit_start_location" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">End Location</label>
                    <input type="text" name="end_location" id="edit_end_location" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Total Stops</label>
                    <input type="number" name="total_stops" id="edit_total_stops" class="form-control" 
                           min="2" max="20">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Estimated Duration (minutes)</label>
                    <input type="number" name="estimated_duration" id="edit_estimated_duration" class="form-control" 
                           min="5" max="180">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="edit_route" class="btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Delete Route</h2>
            <p id="deleteMessage">Are you sure you want to delete this route?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="route_id" id="delete_route_id">
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_route" class="btn-danger">Delete Route</button>
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
        
        function showDetails(routeId) {
            const detailsRow = document.getElementById('details-' + routeId);
            if (detailsRow) {
                detailsRow.style.display = 'table-row';
            }
        }
        
        function hideDetails(routeId) {
            const detailsRow = document.getElementById('details-' + routeId);
            if (detailsRow) {
                detailsRow.style.display = 'none';
            }
        }
        
        function editRoute(routeId) {
            // Fetch route details via AJAX
            fetch('get_route_details.php?route_id=' + routeId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_route_id').value = data.route.Route_ID;
                        document.getElementById('edit_route_name').value = data.route.Route_Name;
                        document.getElementById('edit_start_location').value = data.route.Start_Location;
                        document.getElementById('edit_end_location').value = data.route.End_Location;
                        document.getElementById('edit_total_stops').value = data.route.Total_Stops;
                        document.getElementById('edit_estimated_duration').value = data.route.Estimated_Duration_Minutes;
                        document.getElementById('edit_status').value = data.route.Status;
                        
                        document.getElementById('editModal').style.display = 'flex';
                    } else {
                        alert('Error loading route details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading route details');
                });
        }
        
        function deleteRoute(routeId, routeName) {
            document.getElementById('delete_route_id').value = routeId;
            document.getElementById('deleteMessage').innerHTML = 
                `Are you sure you want to delete route <strong>"${routeName}"</strong>?<br><br>
                <small style="color: #666;">This action cannot be undone.</small>`;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function openRouteMap() {
            window.open('route_map.php', '_blank');
        }
        
        function generateReport() {
            window.open('route_report.php', '_blank');
        }
        
        function importRoutes() {
            alert('Import functionality would open here');
        }
        
        function exportRoutes() {
            window.location.href = 'export_routes.php?format=csv';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                closeModal();
                closeDeleteModal();
            }
        };
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });
        
        // Auto-refresh page every 2 minutes
        setTimeout(function() {
            window.location.reload();
        }, 120000);
    </script>
</body>
</html>