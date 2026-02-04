<?php
// reports.php - 突发事件报告生成与管理页面
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Transport Coordinator
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

// 获取查询参数
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$incident_type = isset($_GET['incident_type']) ? $_GET['incident_type'] : '';
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// 构建查询条件
$where_conditions = ["DATE(ir.Report_time) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$param_types = "ss";

if (!empty($incident_type) && $incident_type != '') {
    $where_conditions[] = "ir.Incident_Type = ?";
    $params[] = $incident_type;
    $param_types .= "s";
}

if (!empty($priority) && $priority != '') {
    $where_conditions[] = "ir.Priority = ?";
    $params[] = $priority;
    $param_types .= "s";
}

if (!empty($status) && $status != '') {
    $where_conditions[] = "ir.Status = ?";
    $params[] = $status;
    $param_types .= "s";
}

$where_clause = count($where_conditions) > 0 ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 获取突发事件统计数据
$stats_sql = "
    SELECT 
        COUNT(*) as total_incidents,
        SUM(CASE WHEN Priority = 'Critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN Priority = 'High' THEN 1 ELSE 0 END) as high_count,
        SUM(CASE WHEN Priority = 'Medium' THEN 1 ELSE 0 END) as medium_count,
        SUM(CASE WHEN Priority = 'Low' THEN 1 ELSE 0 END) as low_count,
        SUM(CASE WHEN Status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN Status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN Status = 'Reported' THEN 1 ELSE 0 END) as reported_count,
        AVG(TIMESTAMPDIFF(HOUR, Report_time, COALESCE(Resolved_Time, NOW()))) as avg_resolution_hours
    FROM incident_reports ir
    $where_clause
";

// 获取按类型统计
$type_stats_where = "";
$type_stats_params = [];
$type_stats_param_types = "";

if (!empty($start_date) && !empty($end_date)) {
    $type_stats_where = "WHERE DATE(Report_time) BETWEEN ? AND ?";
    $type_stats_params = [$start_date, $end_date];
    $type_stats_param_types = "ss";
}

if (!empty($incident_type) && $incident_type != '') {
    $type_stats_where .= empty($type_stats_where) ? "WHERE " : " AND ";
    $type_stats_where .= "Incident_Type = ?";
    $type_stats_params[] = $incident_type;
    $type_stats_param_types .= "s";
}

if (!empty($priority) && $priority != '') {
    $type_stats_where .= empty($type_stats_where) ? "WHERE " : " AND ";
    $type_stats_where .= "Priority = ?";
    $type_stats_params[] = $priority;
    $type_stats_param_types .= "s";
}

if (!empty($status) && $status != '') {
    $type_stats_where .= empty($type_stats_where) ? "WHERE " : " AND ";
    $type_stats_where .= "Status = ?";
    $type_stats_params[] = $status;
    $type_stats_param_types .= "s";
}

$type_stats_sql = "
    SELECT 
        Incident_Type,
        COUNT(*) as count
    FROM incident_reports
    $type_stats_where
    GROUP BY Incident_Type
    ORDER BY count DESC
";

// 获取突发事件列表
$incidents_sql = "
    SELECT 
        ir.*,
        u.Full_Name as reporter_name,
        u.Email as reporter_email,
        v.Plate_number,
        r.Route_Name,
        ss.Departure_time,
        ss.Status as schedule_status,
        TIMESTAMPDIFF(HOUR, ir.Report_time, COALESCE(ir.Resolved_Time, NOW())) as hours_open
    FROM incident_reports ir
    LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
    LEFT JOIN vehicle v ON ir.Vehicle_ID = v.Vehicle_ID
    LEFT JOIN shuttle_schedule ss ON ir.Schedule_ID = ss.Schedule_ID
    LEFT JOIN route r ON ss.Route_ID = r.Route_ID
    $where_clause
    ORDER BY ir.Priority DESC, ir.Report_time DESC
";

// 执行查询
try {
    // 统计数据查询
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        if (!empty($params)) {
            $stats_stmt->bind_param($param_types, ...$params);
        }
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
    } else {
        $stats = [
            'total_incidents' => 0,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0,
            'resolved_count' => 0,
            'in_progress_count' => 0,
            'reported_count' => 0,
            'avg_resolution_hours' => 0
        ];
    }

    // 类型统计数据查询
    $type_stats_stmt = $conn->prepare($type_stats_sql);
    if ($type_stats_stmt) {
        if (!empty($type_stats_params)) {
            $type_stats_stmt->bind_param($type_stats_param_types, ...$type_stats_params);
        }
        $type_stats_stmt->execute();
        $type_stats_result = $type_stats_stmt->get_result();
        $type_stats = $type_stats_result->fetch_all(MYSQLI_ASSOC);
        
        // 计算百分比
        $total = $stats['total_incidents'] ?? 0;
        foreach ($type_stats as &$type) {
            $type['percentage'] = $total > 0 ? round(($type['count'] / $total) * 100, 1) : 0;
        }
        $type_stats_stmt->close();
    } else {
        $type_stats = [];
    }

    // 突发事件列表查询
    $incidents_stmt = $conn->prepare($incidents_sql);
    if ($incidents_stmt) {
        if (!empty($params)) {
            $incidents_stmt->bind_param($param_types, ...$params);
        }
        $incidents_stmt->execute();
        $incidents_result = $incidents_stmt->get_result();
        $incidents = $incidents_result->fetch_all(MYSQLI_ASSOC);
        $incidents_stmt->close();
    } else {
        $incidents = [];
    }

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $stats = ['total_incidents' => 0];
    $type_stats = [];
    $incidents = [];
}

// 获取优先级选项
$priorities = $conn->query("SELECT DISTINCT Priority FROM incident_reports ORDER BY 
    CASE Priority 
        WHEN 'Critical' THEN 1
        WHEN 'High' THEN 2
        WHEN 'Medium' THEN 3
        WHEN 'Low' THEN 4
        ELSE 5
    END")->fetch_all(MYSQLI_ASSOC);

// 获取状态选项
$statuses = $conn->query("SELECT DISTINCT Status FROM incident_reports")->fetch_all(MYSQLI_ASSOC);

// 获取突发事件类型
$incident_types = $conn->query("SELECT DISTINCT Incident_Type FROM incident_reports")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Reports - Coordinator Panel</title>
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
            position: sticky;
            top: 0;
            z-index: 1000;
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
        
        .nav-btn {
            background: white;
            color: #9C27B0;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .nav-btn:hover {
            background: #F3E5F5;
        }
        
        .dashboard-container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .page-description {
            color: #666;
            margin-bottom: 20px;
        }
        
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
            font-size: 14px;
        }
        
        .filter-select, .filter-input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #9C27B0;
            color: white;
        }
        
        .btn-primary:hover {
            background: #7B1FA2;
        }
        
        .btn-secondary {
            background: white;
            color: #9C27B0;
            border: 2px solid #9C27B0;
        }
        
        .btn-secondary:hover {
            background: #F3E5F5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-critical { color: #F44336; }
        .stat-high { color: #FF9800; }
        .stat-medium { color: #2196F3; }
        .stat-low { color: #4CAF50; }
        
        .chart-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .chart-container {
            height: 300px;
            margin-top: 20px;
        }
        
        .incidents-table-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        .incidents-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .incidents-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
            position: sticky;
            top: 0;
        }
        
        .incidents-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        
        .incidents-table tr:hover {
            background: #f8f9fa;
        }
        
        .priority-badge, .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .priority-critical { background: #F44336; color: white; }
        .priority-high { background: #FF9800; color: white; }
        .priority-medium { background: #2196F3; color: white; }
        .priority-low { background: #4CAF50; color: white; }
        
        .status-reported { background: #FFC107; color: #000; }
        .status-under-review { background: #2196F3; color: white; }
        .status-in-progress { background: #FF9800; color: white; }
        .status-resolved { background: #4CAF50; color: white; }
        .status-closed { background: #9E9E9E; color: white; }
        
        .type-badge {
            background: #E1BEE7;
            color: #7B1FA2;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .action-cell {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-view {
            background: #2196F3;
            color: white;
        }
        
        .btn-view:hover {
            background: #1976D2;
        }
        
        .btn-update {
            background: #FF9800;
            color: white;
        }
        
        .btn-update:hover {
            background: #F57C00;
        }
        
        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            font-style: italic;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #666;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 2000;
        }
        
        .chart-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: #f8f9fa;
            border-radius: 5px;
            border: 2px dashed #ddd;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
            
            .incidents-table {
                font-size: 14px;
            }
            
            .incidents-table th,
            .incidents-table td {
                padding: 10px 5px;
            }
            
            .action-cell {
                flex-direction: column;
            }
            
            .btn {
                padding: 8px 15px;
                font-size: 13px;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">🚌 Campus Shuttle - Incident Reports</div>
        <div class="user-info">
            <div class="user-badge">Transport Coordinator</div>
            <a href="controlPanel.php" class="nav-btn">Dashboard</a>
            <button class="nav-btn" onclick="logout()">Logout</button>
        </div>
    </nav>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>📊 Incident Reports & Analytics</h1>
            <p class="page-description">
                Monitor and analyze incident reports from <?php echo date('F j, Y', strtotime($start_date)); ?> 
                to <?php echo date('F j, Y', strtotime($end_date)); ?>
            </p>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="reports.php">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Start Date</label>
                        <input type="date" name="start_date" class="filter-input" 
                               value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">End Date</label>
                        <input type="date" name="end_date" class="filter-input" 
                               value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Incident Type</label>
                        <select name="incident_type" class="filter-select">
                            <option value="">All Types</option>
                            <?php foreach($incident_types as $type): ?>
                                <option value="<?php echo $type['Incident_Type']; ?>" 
                                    <?php echo $incident_type == $type['Incident_Type'] ? 'selected' : ''; ?>>
                                    <?php echo $type['Incident_Type']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Priority</label>
                        <select name="priority" class="filter-select">
                            <option value="">All Priorities</option>
                            <?php foreach($priorities as $p): ?>
                                <option value="<?php echo $p['Priority']; ?>" 
                                    <?php echo $priority == $p['Priority'] ? 'selected' : ''; ?>>
                                    <?php echo $p['Priority']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="">All Statuses</option>
                            <?php foreach($statuses as $s): ?>
                                <option value="<?php echo $s['Status']; ?>" 
                                    <?php echo $status == $s['Status'] ? 'selected' : ''; ?>>
                                    <?php echo $s['Status']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Reset Filters</button>
                </div>
            </form>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Incidents</div>
                <div class="stat-number"><?php echo $stats['total_incidents'] ?? 0; ?></div>
                <div class="stat-label">In selected period</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Critical</div>
                <div class="stat-number stat-critical"><?php echo $stats['critical_count'] ?? 0; ?></div>
                <div class="stat-label">Require immediate action</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">High Priority</div>
                <div class="stat-number stat-high"><?php echo $stats['high_count'] ?? 0; ?></div>
                <div class="stat-label">Urgent attention needed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Resolved</div>
                <div class="stat-number stat-low"><?php echo $stats['resolved_count'] ?? 0; ?></div>
                <div class="stat-label">
                    <?php 
                        $total = $stats['total_incidents'] ?? 0;
                        $resolved = $stats['resolved_count'] ?? 0;
                        $percentage = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;
                        echo $percentage . '% resolution rate';
                    ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Avg Resolution Time</div>
                <div class="stat-number"><?php echo round($stats['avg_resolution_hours'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Hours to resolve</div>
            </div>
        </div>
        
        <!-- Chart Section -->
        <div class="chart-section">
            <h2 style="margin-bottom: 10px;">Incidents by Type</h2>
            <p style="color: #666; margin-bottom: 20px;">Distribution of incident types for the selected period</p>
            
            <div class="chart-container">
                <?php if(!empty($type_stats)): ?>
                    <canvas id="typeChart"></canvas>
                <?php else: ?>
                    <div class="chart-placeholder">
                        No data available for the selected filters
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Incidents Table -->
        <div class="incidents-table-container">
            <h2 style="margin-bottom: 10px;">Incident Details</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Showing <?php echo count($incidents); ?> incident(s) for selected criteria
            </p>
            
            <?php if(count($incidents) > 0): ?>
                <table class="incidents-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Vehicle/Route</th>
                            <th>Reporter</th>
                            <th>Reported</th>
                            <th>Open Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($incidents as $incident): ?>
                            <tr>
                                <td><strong>#<?php echo $incident['Incident_ID']; ?></strong></td>
                                <td>
                                    <span class="type-badge"><?php echo $incident['Incident_Type']; ?></span>
                                </td>
                                <td>
                                    <?php 
                                        $priority_class = strtolower($incident['Priority']);
                                        if($priority_class == 'critical' || $priority_class == 'high' || 
                                           $priority_class == 'medium' || $priority_class == 'low') {
                                    ?>
                                        <span class="priority-badge priority-<?php echo $priority_class; ?>">
                                            <?php echo $incident['Priority']; ?>
                                        </span>
                                    <?php } else { ?>
                                        <span><?php echo $incident['Priority']; ?></span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php 
                                        $status_class = strtolower(str_replace(' ', '-', $incident['Status']));
                                        if(in_array($status_class, ['reported', 'under-review', 'in-progress', 'resolved', 'closed'])) {
                                    ?>
                                        <span class="status-badge status-<?php echo $status_class; ?>">
                                            <?php echo $incident['Status']; ?>
                                        </span>
                                    <?php } else { ?>
                                        <span><?php echo $incident['Status']; ?></span>
                                    <?php } ?>
                                </td>
                                <td style="max-width: 250px;">
                                    <?php echo substr($incident['Description'], 0, 100); ?>
                                    <?php if(strlen($incident['Description']) > 100): ?>...<?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($incident['Plate_number'])): ?>
                                        <div><strong>Vehicle:</strong> <?php echo $incident['Plate_number']; ?></div>
                                    <?php endif; ?>
                                    <?php if(!empty($incident['Route_Name'])): ?>
                                        <div><strong>Route:</strong> <?php echo $incident['Route_Name']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo !empty($incident['reporter_name']) ? $incident['reporter_name'] : 'Unknown'; ?>
                                    <?php if(!empty($incident['reporter_email'])): ?>
                                        <br><small><?php echo $incident['reporter_email']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M d', strtotime($incident['Report_time'])); ?><br>
                                    <?php echo date('H:i', strtotime($incident['Report_time'])); ?>
                                </td>
                                <td>
                                    <?php echo round($incident['hours_open'], 1); ?>h
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <h3>No incidents found for the selected criteria</h3>
                    <p>Try adjusting your filters or select a different date range</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Loading Indicator -->
    <div class="loading" id="loading">
        <h3>Generating report...</h3>
        <p>Please wait while we compile your data.</p>
        <div style="margin-top: 20px;">
            <div style="width: 100%; height: 4px; background: #f0f0f0; border-radius: 2px; overflow: hidden;">
                <div id="progressBar" style="width: 0%; height: 100%; background: #9C27B0; transition: width 0.3s;"></div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize Chart if data exists
        <?php if(!empty($type_stats)): ?>
        const typeStats = <?php echo json_encode($type_stats); ?>;
        
        // Create chart if canvas exists
        const ctx = document.getElementById('typeChart');
        if(ctx) {
            const typeChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: typeStats.map(item => item.Incident_Type),
                    datasets: [{
                        data: typeStats.map(item => item.count),
                        backgroundColor: [
                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                            '#9966FF', '#FF9F40', '#8AC926', '#1982C4',
                            '#6A4C93', '#FF595E', '#1982C4', '#8AC926'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const item = typeStats[context.dataIndex];
                                    return `${item.Incident_Type}: ${item.count} (${item.percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
        }
        <?php endif; ?>
        
        // Utility Functions
        function logout() {
            if(confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
        }
        
        function resetFilters() {
            window.location.href = 'reports.php';
        }
        
        function viewIncident(incidentId) {
            window.open(`view_incident.php?id=${incidentId}`, '_blank');
        }
        
        function updateIncident(incidentId) {
            window.open(`update_incident.php?id=${incidentId}`, '_blank');
        }
        
        // Auto-refresh data every 5 minutes
        setInterval(() => {
            if(!document.hidden) {
                console.log('Auto-refreshing incident data...');
            }
        }, 300000);
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modals (if any)
            if(e.key === 'Escape') {
                const loading = document.getElementById('loading');
                if(loading.style.display === 'block') {
                    loading.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>