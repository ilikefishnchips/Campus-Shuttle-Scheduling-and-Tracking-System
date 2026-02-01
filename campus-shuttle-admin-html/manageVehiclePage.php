<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = '';
$vehicles = [];
$vehicle_details = null;
$edit_mode = false;
$search_query = '';
$status_filter = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_vehicle':
                // Create new vehicle
                $plate_number = strtoupper(trim($_POST['plate_number']));
                $model = trim($_POST['model']);
                $capacity = intval($_POST['capacity']);
                $status = $_POST['status'];
                $last_maintenance = !empty($_POST['last_maintenance']) ? $_POST['last_maintenance'] : null;
                
                // Check if plate number already exists
                $check_sql = "SELECT Vehicle_ID FROM vehicle WHERE Plate_number = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $plate_number);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Vehicle with this plate number already exists!";
                    $message_type = "error";
                } else {
                    // Insert new vehicle
                    $insert_sql = "INSERT INTO vehicle (Plate_number, Model, Capacity, Status, Last_Maintenance) 
                                   VALUES (?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ssiss", $plate_number, $model, $capacity, $status, $last_maintenance);
                    
                    if ($insert_stmt->execute()) {
                        $message = "Vehicle added successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error adding vehicle: " . $conn->error;
                        $message_type = "error";
                    }
                }
                break;
                
            case 'edit_vehicle':
                // Prepare vehicle data for editing
                $vehicle_id = $_POST['vehicle_id'];
                $edit_mode = true;
                
                // Get vehicle details
                $get_sql = "SELECT * FROM vehicle WHERE Vehicle_ID = ?";
                $get_stmt = $conn->prepare($get_sql);
                $get_stmt->bind_param("i", $vehicle_id);
                $get_stmt->execute();
                $vehicle_details = $get_stmt->get_result()->fetch_assoc();
                break;
                
            case 'update_vehicle':
                // Update existing vehicle
                $vehicle_id = $_POST['vehicle_id'];
                $plate_number = strtoupper(trim($_POST['plate_number']));
                $model = trim($_POST['model']);
                $capacity = intval($_POST['capacity']);
                $status = $_POST['status'];
                $last_maintenance = !empty($_POST['last_maintenance']) ? $_POST['last_maintenance'] : null;
                
                // Check if plate number already exists (excluding current vehicle)
                $check_sql = "SELECT Vehicle_ID FROM vehicle WHERE Plate_number = ? AND Vehicle_ID != ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("si", $plate_number, $vehicle_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Vehicle with this plate number already exists!";
                    $message_type = "error";
                } else {
                    // Update vehicle
                    $update_sql = "UPDATE vehicle SET Plate_number = ?, Model = ?, Capacity = ?, 
                                   Status = ?, Last_Maintenance = ? WHERE Vehicle_ID = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssissi", $plate_number, $model, $capacity, 
                                            $status, $last_maintenance, $vehicle_id);
                    
                    if ($update_stmt->execute()) {
                        $message = "Vehicle updated successfully!";
                        $message_type = "success";
                        $edit_mode = false;
                        $vehicle_details = null;
                    } else {
                        $message = "Error updating vehicle: " . $conn->error;
                        $message_type = "error";
                    }
                }
                break;
                
            case 'delete_vehicle':
                // Delete vehicle
                $vehicle_id = $_POST['vehicle_id'];
                
                // Check if vehicle is assigned to any active schedule
                $check_schedule_sql = "SELECT COUNT(*) as schedule_count FROM shuttle_schedule 
                                      WHERE Vehicle_ID = ? AND Status IN ('Scheduled', 'In Progress')";
                $check_schedule_stmt = $conn->prepare($check_schedule_sql);
                $check_schedule_stmt->bind_param("i", $vehicle_id);
                $check_schedule_stmt->execute();
                $check_schedule_result = $check_schedule_stmt->get_result()->fetch_assoc();
                
                if ($check_schedule_result['schedule_count'] > 0) {
                    $message = "Cannot delete vehicle! It is assigned to active or scheduled shuttles.";
                    $message_type = "error";
                } else {
                    // Mark vehicle as inactive instead of deleting (soft delete)
                    $delete_sql = "UPDATE vehicle SET Status = 'Inactive' WHERE Vehicle_ID = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $vehicle_id);
                    
                    if ($delete_stmt->execute()) {
                        $message = "Vehicle marked as inactive successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error deleting vehicle: " . $conn->error;
                        $message_type = "error";
                    }
                }
                break;
                
            case 'mark_maintenance':
                // Mark vehicle for maintenance
                $vehicle_id = $_POST['vehicle_id'];
                
                $maintenance_sql = "UPDATE vehicle SET Status = 'Maintenance', 
                                   Last_Maintenance = CURDATE() WHERE Vehicle_ID = ?";
                $maintenance_stmt = $conn->prepare($maintenance_sql);
                $maintenance_stmt->bind_param("i", $vehicle_id);
                
                if ($maintenance_stmt->execute()) {
                    $message = "Vehicle marked for maintenance! Maintenance date updated.";
                    $message_type = "success";
                } else {
                    $message = "Error updating vehicle status: " . $conn->error;
                    $message_type = "error";
                }
                break;
        }
    }
}

// Handle search
if (isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
}

if (isset($_GET['status_filter'])) {
    $status_filter = $_GET['status_filter'];
}

// Get all vehicles based on search query and status filter
$vehicles_sql = "SELECT v.*, 
                (SELECT COUNT(*) FROM shuttle_schedule WHERE Vehicle_ID = v.Vehicle_ID AND Status IN ('Scheduled', 'In Progress')) as active_schedules,
                (SELECT GROUP_CONCAT(DISTINCT d.Full_Name) 
                 FROM shuttle_schedule ss 
                 JOIN user d ON ss.Driver_ID = d.User_ID 
                 WHERE ss.Vehicle_ID = v.Vehicle_ID AND ss.Status IN ('Scheduled', 'In Progress')
                ) as assigned_drivers
                FROM vehicle v WHERE 1=1";

// Add WHERE clause based on search and filter
$where_clauses = [];
$params = [];
$types = "";

if (!empty($search_query)) {
    $where_clauses[] = "(v.Plate_number LIKE ? OR v.Model LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params = array_merge($params, [$search_param, $search_param]);
    $types .= "ss";
}

if (!empty($status_filter)) {
    $where_clauses[] = "v.Status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (count($where_clauses) > 0) {
    $vehicles_sql .= " AND " . implode(" AND ", $where_clauses);
}

$vehicles_sql .= " ORDER BY 
                 CASE v.Status 
                   WHEN 'Active' THEN 1 
                   WHEN 'Maintenance' THEN 2 
                   WHEN 'Inactive' THEN 3 
                 END, v.Plate_number";

// Prepare and execute query
$vehicles_stmt = $conn->prepare($vehicles_sql);
if (!empty($params)) {
    $vehicles_stmt->bind_param($types, ...$params);
}
$vehicles_stmt->execute();
$vehicles_result = $vehicles_stmt->get_result();
$vehicles = $vehicles_result->fetch_all(MYSQLI_ASSOC);

// Get maintenance statistics
$stats_sql = "SELECT 
              COUNT(*) as total_vehicles,
              SUM(CASE WHEN Status = 'Active' THEN 1 ELSE 0 END) as active_vehicles,
              SUM(CASE WHEN Status = 'Maintenance' THEN 1 ELSE 0 END) as maintenance_vehicles,
              SUM(CASE WHEN Status = 'Inactive' THEN 1 ELSE 0 END) as inactive_vehicles,
              SUM(Capacity) as total_capacity,
              AVG(Capacity) as avg_capacity
              FROM vehicle";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Vehicles - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <style>
        .manage-vehicles-container {
            padding: 30px;
            max-width: 1400px;
            margin: 80px auto 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .page-title {
            color: #333;
            font-size: 28px;
        }
        
        .back-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .search-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #F44336;
            box-shadow: 0 0 0 3px rgba(244, 67, 54, 0.1);
        }
        
        .search-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            height: 40px;
        }
        
        .search-btn:hover {
            background: #d32f2f;
        }
        
        .reset-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            height: 40px;
            text-decoration: none;
            display: inline-block;
            line-height: 20px;
        }
        
        .reset-btn:hover {
            background: #5a6268;
        }
        
        .btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #d32f2f;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .btn-group {
            padding-top: 30px;
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .vehicles-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .vehicles-table th,
        .vehicles-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .vehicles-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .vehicles-table tr:hover {
            background: #f8f9fa;
        }
        
        .vehicle-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            margin: 2px;
        }
        
        .edit-btn {
            background: #ffc107;
            color: #212529;
        }
        
        .edit-btn:hover {
            background: #e0a800;
        }
        
        .delete-btn {
            background: #dc3545;
            color: white;
        }
        
        .delete-btn:hover {
            background: #c82333;
        }
        
        .maintenance-btn {
            background: #fd7e14;
            color: white;
        }
        
        .maintenance-btn:hover {
            background: #e9690c;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin: 2px;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-maintenance {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .capacity-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .schedule-badge {
            display: inline-block;
            background: #e8f5e9;
            color: #388e3c;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #F44336;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .stat-subtext {
            color: #999;
            font-size: 12px;
        }
        
        .search-info {
            margin-bottom: 20px;
            padding: 10px 15px;
            background: #e7f3ff;
            border-radius: 5px;
            font-size: 14px;
            color: #0c5460;
        }
        
        .search-info strong {
            color: #155724;
        }
        
        .vehicle-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .results-count {
            margin: 15px 0;
            color: #6c757d;
            font-size: 14px;
            font-style: italic;
        }
        
        .driver-list {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        @media (max-width: 768px) {
            .manage-vehicles-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .vehicles-table th,
            .vehicles-table td {
                padding: 10px 5px;
                font-size: 12px;
            }
            
            .vehicle-actions {
                flex-direction: column;
            }
            
            .vehicle-form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .maintenance-status {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
        }
        
        .last-maintenance {
            color: #666;
            font-size: 12px;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-logo">
                <img src="../assets/mmuShuttleLogo2.png" alt="Logo" class="logo-icon">
                <span class="logo-text">Campus Shuttle Admin</span>
            </div>
            <div class="admin-profile">
                <img src="../assets/mmuShuttleLogo2.png" alt="Admin" class="profile-pic">
                <div class="user-badge">
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </div>
                <div class="profile-menu">
                    <button class="logout-btn" onclick="window.location.href='../logout.php'">Logout</button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="manage-vehicles-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Manage Vehicles</h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Vehicle Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Vehicles</div>
                <div class="stat-number"><?php echo $stats['total_vehicles']; ?></div>
                <div class="stat-subtext">In system</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Active Vehicles</div>
                <div class="stat-number"><?php echo $stats['active_vehicles']; ?></div>
                <div class="stat-subtext">Ready for service</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">In Maintenance</div>
                <div class="stat-number"><?php echo $stats['maintenance_vehicles']; ?></div>
                <div class="stat-subtext">Under repair</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Capacity</div>
                <div class="stat-number"><?php echo $stats['total_capacity']; ?></div>
                <div class="stat-subtext">Average: <?php echo round($stats['avg_capacity']); ?> seats</div>
            </div>
        </div>
        
        <!-- Vehicle Form Section -->
        <div class="section">
            <div class="form-header">
                <h2 class="section-title"><?php echo $edit_mode ? 'Edit Vehicle' : 'Add New Vehicle'; ?></h2>
                <?php if ($edit_mode): ?>
                    <button class="btn btn-secondary" onclick="cancelEdit()">Cancel Edit</button>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="" id="vehicleForm">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="action" value="update_vehicle">
                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle_details['Vehicle_ID']; ?>">
                <?php else: ?>
                    <input type="hidden" name="action" value="create_vehicle">
                <?php endif; ?>
                
                <div class="vehicle-form-row">
                    <div class="form-group">
                        <label class="form-label" for="plate_number">Plate Number *</label>
                        <input type="text" id="plate_number" name="plate_number" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($vehicle_details['Plate_number']) : ''; ?>" 
                               required pattern="[A-Z0-9\s-]{3,20}" 
                               placeholder="e.g., ABC1234, XYZ 5678" 
                               title="Enter valid plate number (letters, numbers, spaces, hyphens)">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="model">Model *</label>
                        <input type="text" id="model" name="model" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($vehicle_details['Model']) : ''; ?>" 
                               required placeholder="e.g., Toyota Coaster, Mercedes-Benz Sprinter">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="capacity">Seating Capacity *</label>
                        <input type="number" id="capacity" name="capacity" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($vehicle_details['Capacity']) : '30'; ?>" 
                               required min="10" max="100" step="1" placeholder="Number of seats">
                    </div>
                </div>
                
                <div class="vehicle-form-row">
                    <div class="form-group">
                        <label class="form-label" for="status">Status *</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Active" <?php echo ($edit_mode && $vehicle_details['Status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Maintenance" <?php echo ($edit_mode && $vehicle_details['Status'] == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="Inactive" <?php echo ($edit_mode && $vehicle_details['Status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="last_maintenance">Last Maintenance Date</label>
                        <input type="date" id="last_maintenance" name="last_maintenance" class="form-control"
                               value="<?php echo $edit_mode && $vehicle_details['Last_Maintenance'] ? htmlspecialchars($vehicle_details['Last_Maintenance']) : ''; ?>">
                        <small style="color: #666; font-size: 12px;">Leave empty if no maintenance recorded</small>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn">
                        <?php echo $edit_mode ? 'Update Vehicle' : 'Add Vehicle'; ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Search Section -->
        <div class="search-section">
            <h2 class="section-title">Search Vehicles</h2>
            <form method="GET" action="" class="search-form">
                <div class="form-group">
                    <label class="form-label" for="search">Search by Plate or Model</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Enter plate number or model...">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="status_filter">Filter by Status</label>
                    <select id="status_filter" name="status_filter" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="Active" <?php if ($status_filter == 'Active') echo 'selected'; ?>>Active</option>
                        <option value="Maintenance" <?php if ($status_filter == 'Maintenance') echo 'selected'; ?>>Maintenance</option>
                        <option value="Inactive" <?php if ($status_filter == 'Inactive') echo 'selected'; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="search-btn">Search</button>
                    <a href="manage_vehicles.php" class="reset-btn">Reset</a>
                </div>
            </form>
            
            <?php if (!empty($search_query) || !empty($status_filter)): ?>
                <div class="search-info">
                    <?php 
                    $search_info = [];
                    if (!empty($search_query)) {
                        $search_info[] = "Search: <strong>" . htmlspecialchars($search_query) . "</strong>";
                    }
                    if (!empty($status_filter)) {
                        $search_info[] = "Status: <strong>" . htmlspecialchars($status_filter) . "</strong>";
                    }
                    echo "Showing results for: " . implode(", ", $search_info);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Vehicles List Section -->
        <div class="section">
            <h2 class="section-title">Vehicle List</h2>
            
            <?php if (empty($vehicles)): ?>
                <div class="no-data">
                    <?php if (!empty($search_query) || !empty($status_filter)): ?>
                        No vehicles found matching your search criteria.
                    <?php else: ?>
                        No vehicles found in the system. Add your first vehicle above.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="results-count">
                    Found <?php echo count($vehicles); ?> vehicle(s)
                </div>
                
                <div class="table-container">
                    <table class="vehicles-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Plate Number</th>
                                <th>Model</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Last Maintenance</th>
                                <th>Active Schedules</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): 
                                $status_class = strtolower($vehicle['Status']);
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vehicle['Vehicle_ID']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($vehicle['Plate_number']); ?></strong>
                                        <?php if ($vehicle['active_schedules'] > 0): ?>
                                            <span class="schedule-badge" title="Active schedules"><?php echo $vehicle['active_schedules']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($vehicle['Model']); ?></td>
                                    <td>
                                        <span class="capacity-badge"><?php echo htmlspecialchars($vehicle['Capacity']); ?> seats</span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($vehicle['Status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($vehicle['Last_Maintenance']): ?>
                                            <?php 
                                            $maintenance_date = new DateTime($vehicle['Last_Maintenance']);
                                            $today = new DateTime();
                                            $interval = $today->diff($maintenance_date);
                                            $days_ago = $interval->days;
                                            
                                            echo date('Y-m-d', strtotime($vehicle['Last_Maintenance']));
                                            echo '<div class="maintenance-status">';
                                            if ($days_ago == 0) {
                                                echo '<span style="color: #28a745;">Today</span>';
                                            } elseif ($days_ago < 30) {
                                                echo '<span style="color: #28a745;">' . $days_ago . ' days ago</span>';
                                            } elseif ($days_ago < 90) {
                                                echo '<span style="color: #ffc107;">' . $days_ago . ' days ago</span>';
                                            } else {
                                                echo '<span style="color: #dc3545;">' . $days_ago . ' days ago</span>';
                                            }
                                            echo '</div>';
                                            ?>
                                        <?php else: ?>
                                            <span style="color: #6c757d; font-style: italic;">Not recorded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($vehicle['active_schedules'] > 0): ?>
                                            <div style="color: #28a745; font-weight: 500;">
                                                <?php echo $vehicle['active_schedules']; ?> active
                                            </div>
                                            <?php if ($vehicle['assigned_drivers']): ?>
                                                <div class="driver-list" title="Assigned drivers: <?php echo htmlspecialchars($vehicle['assigned_drivers']); ?>">
                                                    <?php 
                                                    $drivers = explode(',', $vehicle['assigned_drivers']);
                                                    echo htmlspecialchars($drivers[0]);
                                                    if (count($drivers) > 1) {
                                                        echo ' +' . (count($drivers) - 1) . ' more';
                                                    }
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #6c757d; font-style: italic;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="vehicle-actions">
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="edit_vehicle">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                                <button type="submit" class="action-btn edit-btn">Edit</button>
                                            </form>
                                            
                                            <form method="POST" action="" style="display: inline;" 
                                                  onsubmit="return confirmDeleteVehicle(<?php echo $vehicle['Vehicle_ID']; ?>, '<?php echo htmlspecialchars(addslashes($vehicle['Plate_number'])); ?>', <?php echo $vehicle['active_schedules']; ?>)">
                                                <input type="hidden" name="action" value="delete_vehicle">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                                <button type="submit" class="action-btn delete-btn" 
                                                        <?php echo $vehicle['Status'] == 'Inactive' ? 'disabled title="Vehicle already inactive"' : ''; ?>>
                                                    <?php echo $vehicle['Status'] == 'Inactive' ? 'Inactive' : 'Delete'; ?>
                                                </button>
                                            </form>
                                            
                                            <?php if ($vehicle['Status'] != 'Maintenance'): ?>
                                                <form method="POST" action="" style="display: inline;" 
                                                      onsubmit="return confirmMaintenance(<?php echo $vehicle['Vehicle_ID']; ?>, '<?php echo htmlspecialchars(addslashes($vehicle['Plate_number'])); ?>')">
                                                    <input type="hidden" name="action" value="mark_maintenance">
                                                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['Vehicle_ID']; ?>">
                                                    <button type="submit" class="action-btn maintenance-btn">
                                                        Maintenance
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Cancel edit mode
        function cancelEdit() {
            window.location.href = 'manage_vehicles.php';
        }
        
        // Confirm delete vehicle
        function confirmDeleteVehicle(vehicleId, plateNumber, activeSchedules) {
            if (activeSchedules > 0) {
                alert(`Cannot delete vehicle "${plateNumber}"! It has ${activeSchedules} active or scheduled shuttle(s).\n\nPlease reassign or cancel the schedules first.`);
                return false;
            }
            
            return confirm(`Are you sure you want to mark vehicle "${plateNumber}" (ID: ${vehicleId}) as inactive?\n\nThis will remove it from active service but keep historical data.`);
        }
        
        // Confirm mark for maintenance
        function confirmMaintenance(vehicleId, plateNumber) {
            return confirm(`Mark vehicle "${plateNumber}" (ID: ${vehicleId}) for maintenance?\n\nThis will update the status to "Maintenance" and set today's date as last maintenance.`);
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const vehicleForm = document.getElementById('vehicleForm');
            if (vehicleForm) {
                vehicleForm.addEventListener('submit', function(e) {
                    const plateNumber = document.getElementById('plate_number').value.trim();
                    const model = document.getElementById('model').value.trim();
                    const capacity = document.getElementById('capacity').value;
                    
                    if (!plateNumber) {
                        e.preventDefault();
                        alert('Please enter a plate number.');
                        return false;
                    }
                    
                    if (!model) {
                        e.preventDefault();
                        alert('Please enter a vehicle model.');
                        return false;
                    }
                    
                    if (!capacity || capacity < 10 || capacity > 100) {
                        e.preventDefault();
                        alert('Please enter a valid capacity between 10 and 100 seats.');
                        return false;
                    }
                    
                    return true;
                });
            }
            
            // Auto-hide messages after 5 seconds
            const alert = document.querySelector('.alert');
            if (alert) {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            }
            
            // Initialize tooltips for disabled buttons
            document.querySelectorAll('button[disabled][title]').forEach(button => {
                button.addEventListener('mouseover', function() {
                    const title = this.getAttribute('title');
                    if (title) {
                        // Create and show tooltip
                        const tooltip = document.createElement('div');
                        tooltip.className = 'tooltip';
                        tooltip.textContent = title;
                        tooltip.style.cssText = `
                            position: absolute;
                            background: #333;
                            color: white;
                            padding: 5px 10px;
                            border-radius: 4px;
                            font-size: 12px;
                            z-index: 1000;
                            pointer-events: none;
                        `;
                        document.body.appendChild(tooltip);
                        
                        const rect = this.getBoundingClientRect();
                        tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
                        tooltip.style.left = (rect.left + rect.width/2 - tooltip.offsetWidth/2) + 'px';
                        
                        this.tooltip = tooltip;
                    }
                });
                
                button.addEventListener('mouseout', function() {
                    if (this.tooltip) {
                        this.tooltip.remove();
                        this.tooltip = null;
                    }
                });
            });
            
            // Auto-format plate number to uppercase
            const plateInput = document.getElementById('plate_number');
            if (plateInput) {
                plateInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
            
            // Set default date for last maintenance to today
            const lastMaintenanceInput = document.getElementById('last_maintenance');
            if (lastMaintenanceInput && !lastMaintenanceInput.value) {
                const today = new Date().toISOString().split('T')[0];
                lastMaintenanceInput.value = today;
            }
        });
        
        // Search form validation
        const searchForm = document.querySelector('.search-form');
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                const searchInput = document.getElementById('search');
                const statusFilter = document.getElementById('status_filter');
                
                // If both search and filter are empty, prevent submission
                if (!searchInput.value.trim() && !statusFilter.value) {
                    e.preventDefault();
                    // Just refresh the page to show all vehicles
                    window.location.href = 'manage_vehicles.php';
                    return false;
                }
                
                return true;
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus on search input
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('search');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Ctrl+N to add new vehicle
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = '#vehicleForm';
                document.getElementById('plate_number').focus();
            }
        });
        
        // Quick status filter
        function filterByStatus(status) {
            window.location.href = 'manage_vehicles.php?status_filter=' + status;
        }
    </script>
</body>
</html>