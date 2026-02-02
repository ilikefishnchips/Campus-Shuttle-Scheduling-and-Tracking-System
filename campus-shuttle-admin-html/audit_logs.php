<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit();
}

// Create audit_logs table if it doesn't exist
$table_sql = "CREATE TABLE IF NOT EXISTS audit_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    user_type VARCHAR(50),
    username VARCHAR(100),
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20) DEFAULT 'SUCCESS',
    severity VARCHAR(20) DEFAULT 'INFO',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_module (module),
    INDEX idx_created_at (created_at),
    INDEX idx_severity (severity),
    FOREIGN KEY (user_id) REFERENCES user(User_ID) ON DELETE SET NULL
)";
$conn->query($table_sql);

// Initialize variables
$message = '';
$message_type = '';
$logs = [];
$total_logs = 0;
$filtered_count = 0;

// Default filters
$filters = [
    'date_from' => date('Y-m-d', strtotime('-7 days')),
    'date_to' => date('Y-m-d'),
    'user_type' => '',
    'action' => '',
    'module' => '',
    'severity' => '',
    'status' => '',
    'search' => '',
    'page' => 1,
    'limit' => 50
];

// Get available filter options
$user_types = $conn->query("SELECT DISTINCT user_type FROM audit_logs WHERE user_type IS NOT NULL ORDER BY user_type")->fetch_all(MYSQLI_ASSOC);
$actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC);
$modules = $conn->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module")->fetch_all(MYSQLI_ASSOC);

// Apply filters from GET request
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    foreach ($filters as $key => $value) {
        if (isset($_GET[$key]) && !empty($_GET[$key])) {
            $filters[$key] = $_GET[$key];
        }
    }
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'export_csv':
                exportAuditLogs();
                exit();
                
            case 'clear_logs':
                if (isset($_POST['clear_date'])) {
                    $clear_date = $_POST['clear_date'];
                    $clear_sql = "DELETE FROM audit_logs WHERE created_at < ? AND severity != 'CRITICAL'";
                    $clear_stmt = $conn->prepare($clear_sql);
                    $clear_stmt->bind_param("s", $clear_date);
                    
                    if ($clear_stmt->execute()) {
                        $message = "Logs before " . date('Y-m-d', strtotime($clear_date)) . " cleared successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error clearing logs.";
                        $message_type = "error";
                    }
                }
                break;
                
            case 'add_test_log':
                // Add a test log entry for demonstration
                $test_sql = "INSERT INTO audit_logs (user_id, user_type, username, action, module, details, ip_address, user_agent, status, severity) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $test_stmt = $conn->prepare($test_sql);
                
                $test_data = [
                    $_SESSION['user_id'],
                    'Admin',
                    $_SESSION['username'],
                    'TEST_LOG',
                    'Audit Logs',
                    'Test log entry added for demonstration',
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'],
                    'SUCCESS',
                    'INFO'
                ];
                $test_stmt->bind_param("isssssssss", ...$test_data);
                $test_stmt->execute();
                
                $message = "Test log entry added successfully!";
                $message_type = "success";
                break;
        }
    }
}

// Build query with filters
function buildQuery($filters) {
    global $conn;
    
    $where = [];
    $params = [];
    $types = '';
    
    // Date range filter - FIXED: Specify al.created_at
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "DATE(al.created_at) BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
        $types .= 'ss';
    }
    
    // User type filter
    if (!empty($filters['user_type'])) {
        $where[] = "al.user_type = ?";
        $params[] = $filters['user_type'];
        $types .= 's';
    }
    
    // Action filter
    if (!empty($filters['action'])) {
        $where[] = "al.action = ?";
        $params[] = $filters['action'];
        $types .= 's';
    }
    
    // Module filter
    if (!empty($filters['module'])) {
        $where[] = "al.module = ?";
        $params[] = $filters['module'];
        $types .= 's';
    }
    
    // Severity filter
    if (!empty($filters['severity'])) {
        $where[] = "al.severity = ?";
        $params[] = $filters['severity'];
        $types .= 's';
    }
    
    // Status filter
    if (!empty($filters['status'])) {
        $where[] = "al.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    // Search filter
    if (!empty($filters['search'])) {
        $where[] = "(al.username LIKE ? OR al.details LIKE ? OR al.ip_address LIKE ?)";
        $search_term = "%{$filters['search']}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Count total filtered logs - FIXED: Specify table
    $count_sql = "SELECT COUNT(*) as total FROM audit_logs al $where_clause";
    $count_stmt = $conn->prepare($count_sql);
    
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    // Get logs with pagination - FIXED: Specify columns explicitly
    $offset = ($filters['page'] - 1) * $filters['limit'];
    $logs_sql = "SELECT al.*, u.Full_Name, u.Email 
                FROM audit_logs al
                LEFT JOIN user u ON al.user_id = u.User_ID
                $where_clause
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?";
    
    $logs_stmt = $conn->prepare($logs_sql);
    
    if (!empty($params)) {
        $params[] = $filters['limit'];
        $params[] = $offset;
        $types .= 'ii';
        $logs_stmt->bind_param($types, ...$params);
    } else {
        $logs_stmt->bind_param("ii", $filters['limit'], $offset);
    }
    
    $logs_stmt->execute();
    $logs_result = $logs_stmt->get_result();
    $logs = $logs_result->fetch_all(MYSQLI_ASSOC);
    
    return [
        'logs' => $logs,
        'total' => $total,
        'params' => $params,
        'types' => $types
    ];
}

// Export logs to CSV
function exportAuditLogs() {
    global $conn, $filters;
    
    $result = buildQuery($filters);
    $logs = $result['logs'];
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, [
        'Log ID', 'Timestamp', 'User ID', 'User Type', 'Username', 
        'Full Name', 'Email', 'Action', 'Module', 'Details', 
        'IP Address', 'User Agent', 'Status', 'Severity'
    ]);
    
    // Add data rows
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['log_id'],
            $log['created_at'],
            $log['user_id'] ?? 'N/A',
            $log['user_type'] ?? 'N/A',
            $log['username'] ?? 'N/A',
            $log['Full_Name'] ?? 'N/A',
            $log['Email'] ?? 'N/A',
            $log['action'],
            $log['module'] ?? 'N/A',
            strip_tags($log['details'] ?? ''),
            $log['ip_address'] ?? 'N/A',
            $log['user_agent'] ?? 'N/A',
            $log['status'],
            $log['severity']
        ]);
    }
    
    fclose($output);
    exit();
}

// Get logs with current filters
$result = buildQuery($filters);
$logs = $result['logs'];
$total_logs = $result['total'];
$filtered_count = count($logs);

// Calculate pagination
$total_pages = ceil($total_logs / $filters['limit']);
$start_log = ($filters['page'] - 1) * $filters['limit'] + 1;
$end_log = min($start_log + $filters['limit'] - 1, $total_logs);

// Get log statistics
$stats_sql = "SELECT 
    COUNT(*) as total_logs,
    COUNT(DISTINCT al.user_id) as unique_users,
    COUNT(DISTINCT al.ip_address) as unique_ips,
    MIN(al.created_at) as first_log,
    MAX(al.created_at) as last_log,
    SUM(CASE WHEN al.severity = 'CRITICAL' THEN 1 ELSE 0 END) as critical_count,
    SUM(CASE WHEN al.severity = 'ERROR' THEN 1 ELSE 0 END) as error_count,
    SUM(CASE WHEN al.severity = 'WARNING' THEN 1 ELSE 0 END) as warning_count,
    SUM(CASE WHEN al.status = 'FAILED' THEN 1 ELSE 0 END) as failed_count
    FROM audit_logs al";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent activity summary
$recent_activity_sql = "SELECT 
    DATE(al.created_at) as date,
    COUNT(*) as log_count,
    SUM(CASE WHEN al.severity = 'CRITICAL' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN al.severity = 'ERROR' THEN 1 ELSE 0 END) as error
    FROM audit_logs al
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(al.created_at)
    ORDER BY date DESC";
$recent_activity = $conn->query($recent_activity_sql)->fetch_all(MYSQLI_ASSOC);

// Get top users by activity
$top_users_sql = "SELECT 
    al.user_type,
    al.username,
    COUNT(*) as activity_count
    FROM audit_logs al
    WHERE al.user_type IS NOT NULL
    GROUP BY al.user_type, al.username
    ORDER BY activity_count DESC
    LIMIT 10";
$top_users = $conn->query($top_users_sql)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Audit Logs - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .audit-logs-container {
            padding: 30px;
            max-width: 1600px;
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            border-top: 4px solid #4CAF50;
        }
        
        .stat-card.critical {
            border-top-color: #dc3545;
        }
        
        .stat-card.error {
            border-top-color: #fd7e14;
        }
        
        .stat-card.warning {
            border-top-color: #ffc107;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filters-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-label {
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
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        select.form-control {
            height: 42px;
        }
        
        .btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background: #388E3C;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .logs-table th,
        .logs-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .logs-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }
        
        .logs-table tr:hover {
            background: #f8f9fa;
        }
        
        .log-details {
            max-width: 300px;
            word-wrap: break-word;
        }
        
        .severity-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .severity-critical {
            background: #f8d7da;
            color: #721c24;
        }
        
        .severity-error {
            background: #fff3cd;
            color: #856404;
        }
        
        .severity-warning {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .severity-info {
            background: #d4edda;
            color: #155724;
        }
        
        .severity-debug {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .action-cell {
            white-space: nowrap;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .page-btn {
            padding: 8px 15px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .page-btn:hover:not(:disabled) {
            background: #f8f9fa;
        }
        
        .page-btn.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .results-info {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .clear-logs-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #e9ecef;
        }
        
        .clear-form-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .clear-input {
            width: 200px;
        }
        
        .user-agent {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
        }
        
        .user-agent:hover::after {
            content: attr(title);
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 1000;
            max-width: 300px;
            word-wrap: break-word;
            white-space: normal;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 25px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-title {
            color: #333;
            font-size: 22px;
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: #aaa;
            cursor: pointer;
            line-height: 1;
        }
        
        .log-detail-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .log-detail-label {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .log-detail-value {
            font-weight: 500;
            color: #333;
            word-wrap: break-word;
        }
        
        .recent-activity-chart {
            height: 200px;
            margin-top: 20px;
        }
        
        .chart-bar {
            display: flex;
            align-items: flex-end;
            height: 150px;
            gap: 10px;
            padding: 10px 0;
        }
        
        .chart-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .chart-bar-segment {
            width: 20px;
            background: #4CAF50;
            border-radius: 3px 3px 0 0;
            margin-bottom: 5px;
        }
        
        .chart-bar-segment.critical {
            background: #dc3545;
        }
        
        .chart-label {
            font-size: 11px;
            color: #666;
            transform: rotate(-45deg);
            white-space: nowrap;
        }
        
        .top-users-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .top-user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .top-user-item:last-child {
            border-bottom: none;
        }
        
        .top-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #4CAF50;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .activity-count {
            font-weight: 600;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .audit-logs-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .filters-container {
                grid-template-columns: 1fr;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 10px 5px;
                font-size: 12px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 20px;
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
    <div class="audit-logs-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Audit Logs & System Monitoring</h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_logs']); ?></div>
                <div class="stat-label">Total Log Entries</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['unique_users']); ?></div>
                <div class="stat-label">Unique Users</div>
            </div>
            
            <div class="stat-card critical">
                <div class="stat-number"><?php echo number_format($stats['critical_count']); ?></div>
                <div class="stat-label">Critical Events</div>
            </div>
            
            <div class="stat-card error">
                <div class="stat-number"><?php echo number_format($stats['error_count']); ?></div>
                <div class="stat-label">Errors</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-number"><?php echo number_format($stats['failed_count']); ?></div>
                <div class="stat-label">Failed Actions</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['first_log'] ? date('Y-m-d', strtotime($stats['first_log'])) : 'N/A'; ?></div>
                <div class="stat-label">First Log Date</div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="section">
            <h3 class="section-title">Filter Logs</h3>
            
            <form method="GET" action="" id="filterForm">
                <div class="filters-container">
                    <div class="filter-group">
                        <label class="filter-label">Date Range</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="date" name="date_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                            <input type="date" name="date_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">User Type</label>
                        <select name="user_type" class="form-control">
                            <option value="">All Types</option>
                            <?php foreach ($user_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['user_type']); ?>"
                                    <?php echo $filters['user_type'] == $type['user_type'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['user_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Action</label>
                        <select name="action" class="form-control">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action['action']); ?>"
                                    <?php echo $filters['action'] == $action['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Module</label>
                        <select name="module" class="form-control">
                            <option value="">All Modules</option>
                            <?php foreach ($modules as $module): ?>
                                <option value="<?php echo htmlspecialchars($module['module']); ?>"
                                    <?php echo $filters['module'] == $module['module'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($module['module']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Severity</label>
                        <select name="severity" class="form-control">
                            <option value="">All Severity</option>
                            <option value="CRITICAL" <?php echo $filters['severity'] == 'CRITICAL' ? 'selected' : ''; ?>>Critical</option>
                            <option value="ERROR" <?php echo $filters['severity'] == 'ERROR' ? 'selected' : ''; ?>>Error</option>
                            <option value="WARNING" <?php echo $filters['severity'] == 'WARNING' ? 'selected' : ''; ?>>Warning</option>
                            <option value="INFO" <?php echo $filters['severity'] == 'INFO' ? 'selected' : ''; ?>>Info</option>
                            <option value="DEBUG" <?php echo $filters['severity'] == 'DEBUG' ? 'selected' : ''; ?>>Debug</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="SUCCESS" <?php echo $filters['status'] == 'SUCCESS' ? 'selected' : ''; ?>>Success</option>
                            <option value="FAILED" <?php echo $filters['status'] == 'FAILED' ? 'selected' : ''; ?>>Failed</option>
                            <option value="PENDING" <?php echo $filters['status'] == 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($filters['search']); ?>" 
                               placeholder="Search in logs...">
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                    <button type="submit" class="btn btn-info" name="export" value="1">
                        <i class="fas fa-download"></i> Export CSV
                    </button>
                    <button type="button" class="btn btn-warning" onclick="showClearLogsModal()">
                        <i class="fas fa-trash"></i> Clear Old Logs
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Recent Activity Chart -->
        <div class="section">
            <h3 class="section-title">Recent Activity (Last 7 Days)</h3>
            
            <div class="recent-activity-chart">
                <div class="chart-bar">
                    <?php foreach ($recent_activity as $activity): ?>
                        <?php 
                        $max_height = 150;
                        $total_logs = $activity['log_count'];
                        $critical_percentage = $total_logs > 0 ? ($activity['critical'] / $total_logs) * 100 : 0;
                        $error_percentage = $total_logs > 0 ? ($activity['error'] / $total_logs) * 100 : 0;
                        $info_percentage = 100 - $critical_percentage - $error_percentage;
                        ?>
                        <div class="chart-item">
                            <div style="display: flex; flex-direction: column-reverse; height: 100%;">
                                <?php if ($info_percentage > 0): ?>
                                    <div class="chart-bar-segment" 
                                         style="height: <?php echo ($info_percentage / 100) * $max_height; ?>px; background: #4CAF50;"></div>
                                <?php endif; ?>
                                <?php if ($error_percentage > 0): ?>
                                    <div class="chart-bar-segment" 
                                         style="height: <?php echo ($error_percentage / 100) * $max_height; ?>px; background: #fd7e14;"></div>
                                <?php endif; ?>
                                <?php if ($critical_percentage > 0): ?>
                                    <div class="chart-bar-segment critical" 
                                         style="height: <?php echo ($critical_percentage / 100) * $max_height; ?>px;"></div>
                                <?php endif; ?>
                            </div>
                            <div class="chart-label"><?php echo date('M d', strtotime($activity['date'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 15px; height: 15px; background: #4CAF50; border-radius: 3px;"></div>
                        <span style="font-size: 12px;">Info</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 15px; height: 15px; background: #fd7e14; border-radius: 3px;"></div>
                        <span style="font-size: 12px;">Error</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 15px; height: 15px; background: #dc3545; border-radius: 3px;"></div>
                        <span style="font-size: 12px;">Critical</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top Users Activity -->
        <div class="section">
            <h3 class="section-title">Top Active Users</h3>
            
            <ul class="top-users-list">
                <?php foreach ($top_users as $user): ?>
                    <li class="top-user-item">
                        <div class="top-user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['username'] ?: 'U', 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($user['username']); ?></div>
                                <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($user['user_type']); ?></div>
                            </div>
                        </div>
                        <div class="activity-count"><?php echo number_format($user['activity_count']); ?> actions</div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <!-- Logs Table -->
        <div class="section">
            <div class="section-title">
                <span>Log Entries (<?php echo number_format($filtered_count); ?> of <?php echo number_format($total_logs); ?>)</span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="add_test_log">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="fas fa-plus"></i> Add Test Log
                    </button>
                </form>
            </div>
            
            <div class="results-info">
                Showing <?php echo $start_log; ?> - <?php echo $end_log; ?> of <?php echo number_format($total_logs); ?> entries
            </div>
            
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-search fa-3x"></i>
                    <p style="margin-top: 15px;">No log entries found matching your filters.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['log_id']); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($log['username']); ?></div>
                                            <div style="font-size: 12px; color: #666;">
                                                <?php echo htmlspecialchars($log['user_type']); ?>
                                                <?php if ($log['Full_Name']): ?>
                                                    (<?php echo htmlspecialchars($log['Full_Name']); ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #666; font-style: italic;">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-cell">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($log['action']); ?></div>
                                        <?php if ($log['module']): ?>
                                            <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($log['module']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="log-details">
                                        <?php 
                                        $details = strip_tags($log['details']);
                                        if (strlen($details) > 100) {
                                            echo htmlspecialchars(substr($details, 0, 100)) . '...';
                                        } else {
                                            echo htmlspecialchars($details);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="severity-badge severity-<?php echo strtolower($log['severity']); ?>">
                                            <?php echo htmlspecialchars($log['severity']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($log['status']); ?>">
                                            <?php echo htmlspecialchars($log['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </td>
                                    <td>
                                        <button class="action-btn btn-secondary" onclick="viewLogDetails(<?php echo $log['log_id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <button class="page-btn" onclick="changePage(1)" <?php echo $filters['page'] == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-left"></i>
                        </button>
                        <button class="page-btn" onclick="changePage(<?php echo $filters['page'] - 1; ?>)" <?php echo $filters['page'] == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-left"></i>
                        </button>
                        
                        <?php
                        $start_page = max(1, $filters['page'] - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <button class="page-btn <?php echo $i == $filters['page'] ? 'active' : ''; ?>" 
                                    onclick="changePage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button class="page-btn" onclick="changePage(<?php echo $filters['page'] + 1; ?>)" <?php echo $filters['page'] == $total_pages ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-right"></i>
                        </button>
                        <button class="page-btn" onclick="changePage(<?php echo $total_pages; ?>)" <?php echo $filters['page'] == $total_pages ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Log Details Modal -->
    <div id="logDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Log Entry Details</h3>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <div id="logDetailsContent">
                <!-- Log details will be loaded here via AJAX -->
            </div>
        </div>
    </div>
    
    <!-- Clear Logs Modal -->
    <div id="clearLogsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Clear Old Logs</h3>
                <button type="button" class="close-modal" onclick="closeClearModal()">&times;</button>
            </div>
            
            <div style="padding: 20px 0;">
                <p style="color: #666; margin-bottom: 20px;">
                    This will permanently delete log entries older than the specified date.
                    <strong>Critical logs will not be deleted.</strong>
                </p>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="clear_logs">
                    
                    <div class="clear-form-group">
                        <label>Delete logs before:</label>
                        <input type="date" name="clear_date" class="form-control clear-input" 
                               value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete these logs? This action cannot be undone.')">
                            <i class="fas fa-trash"></i> Clear Logs
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeClearModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
        });
        
        // View log details
        function viewLogDetails(logId) {
            // Show loading
            document.getElementById('logDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #4CAF50;"></i>
                    <p style="margin-top: 15px; color: #666;">Loading log details...</p>
                </div>
            `;
            
            // Fetch log details via AJAX
            fetch(`get_log_details.php?log_id=${logId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('logDetailsContent').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle fa-2x"></i>
                                <p style="margin-top: 15px;">${data.error}</p>
                            </div>
                        `;
                        return;
                    }
                    
                    const log = data.log;
                    let html = `
                        <div class="log-detail-item">
                            <div class="log-detail-label">Log ID</div>
                            <div class="log-detail-value">${log.log_id}</div>
                        </div>
                        
                        <div class="log-detail-item">
                            <div class="log-detail-label">Timestamp</div>
                            <div class="log-detail-value">${new Date(log.created_at).toLocaleString()}</div>
                        </div>
                        
                        <div class="log-detail-item">
                            <div class="log-detail-label">User Information</div>
                            <div class="log-detail-value">
                                ${log.user_id ? `
                                    <strong>${log.username || 'N/A'}</strong><br>
                                    <small>${log.user_type || 'N/A'} (ID: ${log.user_id})</small><br>
                                    ${log.Full_Name ? `<small>${log.Full_Name}</small><br>` : ''}
                                    ${log.Email ? `<small>${log.Email}</small>` : ''}
                                ` : '<em>System-generated log</em>'}
                            </div>
                        </div>
                        
                        <div class="log-detail-item">
                            <div class="log-detail-label">Action & Module</div>
                            <div class="log-detail-value">
                                <strong>${log.action}</strong><br>
                                <small>Module: ${log.module || 'N/A'}</small>
                            </div>
                        </div>
                        
                        <div class="log-detail-item">
                            <div class="log-detail-label">Severity & Status</div>
                            <div class="log-detail-value">
                                <span class="severity-badge severity-${log.severity.toLowerCase()}">
                                    ${log.severity}
                                </span>
                                <span class="status-badge status-${log.status.toLowerCase()}">
                                    ${log.status}
                                </span>
                            </div>
                        </div>
                    `;
                    
                    if (log.details) {
                        html += `
                            <div class="log-detail-item">
                                <div class="log-detail-label">Details</div>
                                <div class="log-detail-value" style="white-space: pre-wrap; background: #f8f9fa; padding: 10px; border-radius: 5px;">
                                    ${log.details}
                                </div>
                            </div>
                        `;
                    }
                    
                    html += `
                        <div class="log-detail-item">
                            <div class="log-detail-label">IP Address</div>
                            <div class="log-detail-value">${log.ip_address || 'N/A'}</div>
                        </div>
                        
                        <div class="log-detail-item">
                            <div class="log-detail-label">User Agent</div>
                            <div class="log-detail-value" style="font-size: 12px;">
                                ${log.user_agent || 'N/A'}
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; text-align: center;">
                            <button class="btn btn-secondary" onclick="closeModal()">
                                Close
                            </button>
                        </div>
                    `;
                    
                    document.getElementById('logDetailsContent').innerHTML = html;
                    document.getElementById('logDetailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('logDetailsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-circle fa-2x"></i>
                            <p style="margin-top: 15px;">Error loading log details. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        // Show clear logs modal
        function showClearLogsModal() {
            document.getElementById('clearLogsModal').style.display = 'block';
        }
        
        // Close modals
        function closeModal() {
            document.getElementById('logDetailsModal').style.display = 'none';
        }
        
        function closeClearModal() {
            document.getElementById('clearLogsModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['logDetailsModal', 'clearLogsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Reset filters
        function resetFilters() {
            window.location.href = 'audit_logs.php';
        }
        
        // Change page
        function changePage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        // Auto-refresh logs every 30 seconds
        let autoRefresh = true;
        
        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            const btn = document.getElementById('refreshBtn');
            if (autoRefresh) {
                btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Auto-refresh ON';
                btn.classList.add('active');
                startAutoRefresh();
            } else {
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Auto-refresh OFF';
                btn.classList.remove('active');
                stopAutoRefresh();
            }
        }
        
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    // Only refresh if page is visible
                    const currentPage = <?php echo $filters['page']; ?>;
                    changePage(currentPage);
                }
            }, 30000); // 30 seconds
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }
        
        // Initialize auto-refresh
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide messages after 5 seconds
            const alertMsg = document.querySelector('.alert');
            if (alertMsg) {
                setTimeout(() => {
                    alertMsg.style.transition = 'opacity 0.5s';
                    alertMsg.style.opacity = '0';
                    setTimeout(() => {
                        alertMsg.style.display = 'none';
                    }, 500);
                }, 5000);
            }
            
            // Start auto-refresh if enabled
            startAutoRefresh();
            
            // Pause auto-refresh when page is not visible
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') {
                    stopAutoRefresh();
                } else if (autoRefresh) {
                    startAutoRefresh();
                }
            });
        });
        
        // Export functionality
        document.querySelector('button[name="export"]').addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get current filter values
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            formData.append('export', '1');
            
            // Create hidden form for export
            const exportForm = document.createElement('form');
            exportForm.method = 'POST';
            exportForm.action = 'audit_logs.php';
            exportForm.style.display = 'none';
            
            // Add all filter values
            formData.forEach((value, key) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                exportForm.appendChild(input);
            });
            
            // Add export action
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'export_csv';
            exportForm.appendChild(actionInput);
            
            document.body.appendChild(exportForm);
            exportForm.submit();
        });
    </script>
</body>
</html>