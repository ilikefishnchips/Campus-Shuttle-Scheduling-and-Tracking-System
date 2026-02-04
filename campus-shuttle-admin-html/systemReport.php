<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit();
}

// Initialize variables
$reports = [];
$report_type = isset($_GET['report']) ? $_GET['report'] : 'overview';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$route_id = isset($_GET['route_id']) ? intval($_GET['route_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Get routes for dropdown
$routes_sql = "SELECT Route_ID, Route_Name FROM route ORDER BY Route_Name";
$routes_result = $conn->query($routes_sql);
$routes = $routes_result->fetch_all(MYSQLI_ASSOC);

// Get status options
$status_options = ['Scheduled', 'In Progress', 'Completed', 'Cancelled', 'Delayed'];

// Generate reports based on type
switch ($report_type) {
    case 'booking_summary':
        $reports = generateBookingReport($conn, $date_from, $date_to, $route_id, $status_filter);
        break;
    
    case 'incident_summary':
        $reports = generateIncidentReport($conn, $date_from, $date_to);
        break;
    
    case 'shuttle_usage':
        $reports = generateShuttleUsageReport($conn, $date_from, $date_to, $route_id);
        break;
    
    case 'user_activity':
        $reports = generateUserActivityReport($conn, $date_from, $date_to);
        break;
    
    case 'performance':
        $reports = generatePerformanceReport($conn, $date_from, $date_to);
        break;
    
    case 'revenue':
        $reports = generateRevenueReport($conn, $date_from, $date_to);
        break;
    
    default:
        $reports = generateOverviewReport($conn);
        break;
}

// Function to generate booking summary report
function generateBookingReport($conn, $date_from, $date_to, $route_id, $status_filter) {
    $params = [];
    $types = "";
    
    $sql = "
        SELECT 
            DATE(ss.Departure_time) as Booking_Date,
            r.Route_Name,
            COUNT(sr.Reservation_ID) as Total_Bookings,
            SUM(CASE WHEN sr.Status = 'Reserved' THEN 1 ELSE 0 END) as Confirmed_Bookings,
            SUM(CASE WHEN sr.Status = 'Cancelled' THEN 1 ELSE 0 END) as Cancelled_Bookings,
            SUM(CASE WHEN sr.Status = 'Used' THEN 1 ELSE 0 END) as Used_Bookings,
            COALESCE(AVG(ss.Available_Seats), 0) as Avg_Available_Seats,
            COUNT(DISTINCT ss.Schedule_ID) as Total_Schedules
        FROM shuttle_schedule ss
        LEFT JOIN route r ON ss.Route_ID = r.Route_ID
        LEFT JOIN seat_reservation sr ON ss.Schedule_ID = sr.Schedule_ID
        WHERE DATE(ss.Departure_time) BETWEEN ? AND ?
    ";
    
    $params[] = $date_from;
    $params[] = $date_to;
    $types = "ss";
    
    if ($route_id > 0) {
        $sql .= " AND ss.Route_ID = ?";
        $params[] = $route_id;
        $types .= "i";
    }
    
    if (!empty($status_filter)) {
        $sql .= " AND ss.Status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
    
    $sql .= " GROUP BY DATE(ss.Departure_time), r.Route_ID ORDER BY Booking_Date DESC, r.Route_Name";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reports = [
        'summary' => [
            'title' => 'Booking Summary Report',
            'period' => "$date_from to $date_to",
            'data' => $result->fetch_all(MYSQLI_ASSOC)
        ]
    ];
    
    // Add statistics
    $stats_sql = "
        SELECT 
            COUNT(DISTINCT sr.Student_ID) as Unique_Students,
            COUNT(DISTINCT ss.Schedule_ID) as Total_Schedules,
            COALESCE(SUM(CASE WHEN sr.Status = 'Reserved' THEN 1 ELSE 0 END), 0) as Total_Confirmed,
            COALESCE(SUM(CASE WHEN sr.Status = 'Cancelled' THEN 1 ELSE 0 END), 0) as Total_Cancelled,
            COALESCE(AVG(ss.Available_Seats), 0) as Avg_Seat_Availability
        FROM shuttle_schedule ss
        LEFT JOIN seat_reservation sr ON ss.Schedule_ID = sr.Schedule_ID
        WHERE DATE(ss.Departure_time) BETWEEN ? AND ?
    ";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    $reports['summary']['statistics'] = $stats;
    
    return $reports;
}

// Function to generate incident summary report - LIST OF INCIDENTS WITH DETAILS
function generateIncidentReport($conn, $date_from, $date_to) {
    // Use prepared statement for safety
    $sql = "SELECT 
                ir.Incident_ID,
                ir.Incident_Type,
                LEFT(ir.Description, 100) as Short_Description,
                ir.Description as Full_Description,
                ir.Priority,
                ir.Status,
                ir.admin_status,
                ir.Report_time,
                ir.Resolved_Time,
                u.Full_Name as Reporter_Name,
                v.Plate_number as Vehicle_Plate
            FROM incident_reports ir
            LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
            LEFT JOIN vehicle v ON ir.Vehicle_ID = v.Vehicle_ID
            WHERE DATE(ir.Report_time) BETWEEN ? AND ?
            ORDER BY ir.Report_time DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate resolution time if resolved
        if ($row['Resolved_Time'] && $row['Report_time']) {
            $report_time = strtotime($row['Report_time']);
            $resolved_time = strtotime($row['Resolved_Time']);
            $row['Resolution_Hours'] = round(($resolved_time - $report_time) / 3600, 2);
        } else {
            $row['Resolution_Hours'] = 0;
        }
        $data[] = $row;
    }
    
    return [
        'summary' => [
            'title' => 'Incident Summary Report',
            'period' => "$date_from to $date_to",
            'data' => $data
        ]
    ];
}

// Function to generate shuttle usage report
function generateShuttleUsageReport($conn, $date_from, $date_to, $route_id) {
    $params = [];
    $types = "";
    
    $sql = "
        SELECT 
            v.Vehicle_ID,
            v.Plate_number,
            v.Model,
            v.Capacity,
            COUNT(DISTINCT ss.Schedule_ID) as Total_Trips,
            COALESCE(SUM(ss.Available_Seats), 0) as Total_Available_Seats,
            COALESCE((
                SELECT COUNT(*) 
                FROM seat_reservation sr2 
                JOIN shuttle_schedule ss2 ON sr2.Schedule_ID = ss2.Schedule_ID
                WHERE ss2.Vehicle_ID = v.Vehicle_ID 
                AND DATE(ss2.Departure_time) BETWEEN ? AND ?
                AND sr2.Status = 'Used'
            ), 0) as Total_Passengers,
            COALESCE(AVG(ss.Available_Seats), 0) as Avg_Seat_Availability,
            COALESCE((
                SELECT COUNT(*) 
                FROM incident_reports ir 
                WHERE ir.Vehicle_ID = v.Vehicle_ID 
                AND DATE(ir.Report_time) BETWEEN ? AND ?
            ), 0) as Total_Incidents
        FROM vehicle v
        LEFT JOIN shuttle_schedule ss ON v.Vehicle_ID = ss.Vehicle_ID
            AND DATE(ss.Departure_time) BETWEEN ? AND ?
    ";
    
    $params = array_merge($params, [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]);
    $types = "ssssss";
    
    if ($route_id > 0) {
        $sql .= " AND ss.Route_ID = ?";
        $params[] = $route_id;
        $types .= "i";
    }
    
    $sql .= " GROUP BY v.Vehicle_ID, v.Plate_number, v.Model, v.Capacity
              ORDER BY Total_Trips DESC";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate utilization percentage
    foreach ($data as &$row) {
        if ($row['Capacity'] > 0 && $row['Total_Passengers'] > 0) {
            $row['Utilization_Percentage'] = round(($row['Total_Passengers'] / ($row['Total_Trips'] * $row['Capacity'])) * 100, 2);
        } else {
            $row['Utilization_Percentage'] = 0;
        }
    }
    
    $reports = [
        'summary' => [
            'title' => 'Shuttle Usage Report',
            'period' => "$date_from to $date_to",
            'data' => $data
        ]
    ];
    
    return $reports;
}

// Function to generate user activity report
function generateUserActivityReport($conn, $date_from, $date_to) {
    $sql = "
        SELECT 
            u.User_ID,
            u.Username,
            u.Full_Name,
            u.Email,
            r.Role_name as Role,
            COALESCE((
                SELECT COUNT(*) 
                FROM seat_reservation sr 
                JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
                WHERE sp.User_ID = u.User_ID 
                AND DATE(sr.Booking_Time) BETWEEN ? AND ?
            ), 0) as Total_Bookings,
            COALESCE((
                SELECT COUNT(*) 
                FROM incident_reports ir 
                WHERE ir.Reporter_ID = u.User_ID 
                AND DATE(ir.Report_time) BETWEEN ? AND ?
            ), 0) as Incidents_Reported,
            DATE(u.Created_At) as Account_Created
        FROM user u
        JOIN user_roles ur ON u.User_ID = ur.User_ID
        JOIN roles r ON ur.Role_ID = r.Role_ID
        WHERE u.Created_At IS NOT NULL
        ORDER BY u.Created_At DESC
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $user_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get user statistics for visualization
    $stats_sql = "
        SELECT 
            r.Role_name,
            COUNT(DISTINCT u.User_ID) as User_Count,
            COUNT(DISTINCT CASE WHEN DATE(u.Created_At) BETWEEN ? AND ? THEN u.User_ID END) as New_Users_Period
        FROM user u
        JOIN user_roles ur ON u.User_ID = ur.User_ID
        JOIN roles r ON ur.Role_ID = r.Role_ID
        GROUP BY r.Role_name
        ORDER BY User_Count DESC
    ";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $reports = [
        'summary' => [
            'title' => 'User Activity Report',
            'period' => "$date_from to $date_to",
            'data' => $user_data,
            'user_stats' => $stats,
            'chart_data' => [
                'labels' => array_column($stats, 'Role_name'),
                'user_counts' => array_column($stats, 'User_Count'),
                'new_users' => array_column($stats, 'New_Users_Period')
            ]
        ]
    ];
    
    return $reports;
}

// Function to generate performance report
function generatePerformanceReport($conn, $date_from, $date_to) {
    $sql = "
        SELECT 
            r.Route_ID,
            r.Route_Name,
            COUNT(DISTINCT ss.Schedule_ID) as Total_Schedules,
            COALESCE(SUM(CASE WHEN ss.Status = 'Completed' THEN 1 ELSE 0 END), 0) as Completed_Trips,
            COALESCE(SUM(CASE WHEN ss.Status = 'Delayed' THEN 1 ELSE 0 END), 0) as Delayed_Trips,
            COALESCE(SUM(CASE WHEN ss.Status = 'Cancelled' THEN 1 ELSE 0 END), 0) as Cancelled_Trips,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, ss.Departure_time, ss.Expected_Arrival)), 0) as Avg_Duration,
            COALESCE(AVG(ss.Available_Seats), 0) as Avg_Seat_Availability,
            COALESCE((
                SELECT COUNT(*) 
                FROM seat_reservation sr 
                JOIN shuttle_schedule ss2 ON sr.Schedule_ID = ss2.Schedule_ID
                WHERE ss2.Route_ID = r.Route_ID 
                AND DATE(ss2.Departure_time) BETWEEN ? AND ?
                AND sr.Status = 'Used'
            ), 0) as Total_Passengers
        FROM route r
        LEFT JOIN shuttle_schedule ss ON r.Route_ID = ss.Route_ID
            AND DATE(ss.Departure_time) BETWEEN ? AND ?
        GROUP BY r.Route_ID, r.Route_Name
        ORDER BY Total_Schedules DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $date_from, $date_to, $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate on-time performance and add chart data
    $chart_labels = [];
    $on_time_data = [];
    $delayed_data = [];
    
    foreach ($data as &$row) {
        if ($row['Total_Schedules'] > 0) {
            $row['On_Time_Percentage'] = round(($row['Completed_Trips'] / $row['Total_Schedules']) * 100, 2);
        } else {
            $row['On_Time_Percentage'] = 0;
        }
        
        // Prepare data for chart
        $chart_labels[] = $row['Route_Name'];
        $on_time_data[] = $row['Completed_Trips'];
        $delayed_data[] = $row['Delayed_Trips'];
    }
    
    $reports = [
        'summary' => [
            'title' => 'Performance & Efficiency Report',
            'period' => "$date_from to $date_to",
            'data' => $data,
            'chart_data' => [
                'labels' => $chart_labels,
                'on_time' => $on_time_data,
                'delayed' => $delayed_data
            ]
        ]
    ];
    
    return $reports;
}

// Function to generate revenue report (if applicable)
function generateRevenueReport($conn, $date_from, $date_to) {
    $sql = "
        SELECT 
            DATE(sr.Booking_Time) as Revenue_Date,
            COUNT(*) as Total_Transactions,
            0 as Total_Revenue, -- Placeholder - add actual revenue calculation if applicable
            AVG(0) as Avg_Transaction_Value
        FROM seat_reservation sr
        WHERE DATE(sr.Booking_Time) BETWEEN ? AND ?
        AND sr.Status IN ('Reserved', 'Used')
        GROUP BY DATE(sr.Booking_Time)
        ORDER BY Revenue_Date DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reports = [
        'summary' => [
            'title' => 'Revenue Report',
            'period' => "$date_from to $date_to",
            'data' => $result->fetch_all(MYSQLI_ASSOC)
        ]
    ];
    
    return $reports;
}

// Function to generate overview report
function generateOverviewReport($conn) {
    // Get today's date
    $today = date('Y-m-d');
    $week_ago = date('Y-m-d', strtotime('-7 days'));
    $month_ago = date('Y-m-d', strtotime('-30 days'));
    
    // System Overview Statistics
    $overview_stats = [
        'today' => getDateStats($conn, $today, $today),
        'week' => getDateStats($conn, $week_ago, $today),
        'month' => getDateStats($conn, $month_ago, $today),
        'system' => getSystemStats($conn)
    ];
    
    // Recent bookings
    $recent_bookings_sql = "
        SELECT 
            sr.Reservation_ID,
            sr.Booking_Time,
            sp.Student_Number,
            u.Full_Name as Student_Name,
            r.Route_Name,
            ss.Departure_time,
            sr.Status
        FROM seat_reservation sr
        JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
        JOIN user u ON sp.User_ID = u.User_ID
        JOIN shuttle_schedule ss ON sr.Schedule_ID = ss.Schedule_ID
        JOIN route r ON ss.Route_ID = r.Route_ID
        ORDER BY sr.Booking_Time DESC
        LIMIT 10
    ";
    
    $recent_bookings = $conn->query($recent_bookings_sql)->fetch_all(MYSQLI_ASSOC);
    
    // Recent incidents
    $recent_incidents_sql = "
        SELECT 
            ir.Incident_ID,
            ir.Incident_Type,
            LEFT(ir.Description, 50) as Description,
            ir.Priority,
            ir.Status,
            ir.admin_status,
            ir.Report_time,
            u.Full_Name as Reporter_Name,
            v.Plate_number as Vehicle
        FROM incident_reports ir
        LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
        LEFT JOIN vehicle v ON ir.Vehicle_ID = v.Vehicle_ID
        ORDER BY ir.Report_time DESC
        LIMIT 10
    ";
    
    $recent_incidents = $conn->query($recent_incidents_sql)->fetch_all(MYSQLI_ASSOC);
    
    // Upcoming schedules
    $upcoming_schedules_sql = "
        SELECT 
            ss.Schedule_ID,
            r.Route_Name,
            ss.Departure_time,
            ss.Status,
            ss.Available_Seats,
            v.Plate_number,
            u.Full_Name as Driver_Name
        FROM shuttle_schedule ss
        LEFT JOIN route r ON ss.Route_ID = r.Route_ID
        LEFT JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
        LEFT JOIN user u ON ss.Driver_ID = u.User_ID
        WHERE ss.Departure_time >= CURDATE()
        ORDER BY ss.Departure_time ASC
        LIMIT 10
    ";
    
    $upcoming_schedules = $conn->query($upcoming_schedules_sql)->fetch_all(MYSQLI_ASSOC);
    
    $reports = [
        'overview' => [
            'title' => 'System Overview Dashboard',
            'statistics' => $overview_stats,
            'recent_bookings' => $recent_bookings,
            'recent_incidents' => $recent_incidents,
            'upcoming_schedules' => $upcoming_schedules
        ]
    ];
    
    return $reports;
}

// Helper function to get date-based statistics
function getDateStats($conn, $date_from, $date_to) {
    // Get bookings count
    $bookings_sql = "SELECT COUNT(*) as cnt FROM seat_reservation WHERE DATE(Booking_Time) BETWEEN ? AND ?";
    $stmt = $conn->prepare($bookings_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_assoc()['cnt'];
    
    // Get incidents count
    $incidents_sql = "SELECT COUNT(*) as cnt FROM incident_reports WHERE DATE(Report_time) BETWEEN ? AND ?";
    $stmt = $conn->prepare($incidents_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $incidents = $stmt->get_result()->fetch_assoc()['cnt'];
    
    // Get schedules count
    $schedules_sql = "SELECT COUNT(*) as cnt FROM shuttle_schedule WHERE DATE(Departure_time) BETWEEN ? AND ?";
    $stmt = $conn->prepare($schedules_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_assoc()['cnt'];
    
    // Get active students count
    $students_sql = "SELECT COUNT(DISTINCT Student_ID) as cnt FROM seat_reservation WHERE DATE(Booking_Time) BETWEEN ? AND ?";
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $active_students = $stmt->get_result()->fetch_assoc()['cnt'];
    
    return [
        'bookings' => $bookings,
        'incidents' => $incidents,
        'schedules' => $schedules,
        'active_students' => $active_students
    ];
}

// Helper function to get system statistics
function getSystemStats($conn) {
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM user) as total_users,
            (SELECT COUNT(*) FROM student_profile) as total_students,
            (SELECT COUNT(*) FROM driver_profile) as total_drivers,
            (SELECT COUNT(*) FROM vehicle WHERE Status = 'Active') as active_vehicles,
            (SELECT COUNT(*) FROM route WHERE Status = 'Active') as active_routes,
            (SELECT COUNT(*) FROM shuttle_schedule WHERE Status = 'Scheduled' AND Departure_time >= CURDATE()) as upcoming_schedules,
            (SELECT COUNT(*) FROM incident_reports WHERE Status IN ('Reported', 'Under Review')) as pending_incidents,
            (SELECT COUNT(*) FROM seat_reservation WHERE Status = 'Reserved' AND DATE(Booking_Time) = CURDATE()) as today_bookings
    ";
    
    return $conn->query($sql)->fetch_assoc();
}

// Handle export requests
if (isset($_GET['export']) && isset($_GET['report_type'])) {
    exportReport($conn, $_GET['report_type'], $date_from, $date_to, $route_id, $status_filter);
    exit();
}

// Export function
function exportReport($conn, $report_type, $date_from, $date_to, $route_id, $status_filter) {
    $filename = "shuttle_report_" . $report_type . "_" . date('Y-m-d') . ".csv";
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Generate appropriate report data
    switch ($report_type) {
        case 'booking_summary':
            $data = generateBookingReport($conn, $date_from, $date_to, $route_id, $status_filter);
            $report_data = $data['summary']['data'];
            $headers = ['Booking Date', 'Route Name', 'Total Bookings', 'Confirmed', 'Cancelled', 'Used', 'Avg Available Seats', 'Total Schedules'];
            break;
        
        case 'incident_summary':
            $data = generateIncidentReport($conn, $date_from, $date_to);
            $report_data = $data['summary']['data'];
            $headers = ['Incident ID', 'Incident Type', 'Priority', 'Status', 'Admin Status', 'Report Time', 'Resolved Time', 'Resolution Hours', 'Reporter Name', 'Vehicle Plate'];
            break;
        
        case 'shuttle_usage':
            $data = generateShuttleUsageReport($conn, $date_from, $date_to, $route_id);
            $report_data = $data['summary']['data'];
            $headers = ['Vehicle ID', 'Plate Number', 'Model', 'Capacity', 'Total Trips', 'Total Available Seats', 'Total Passengers', 'Avg Seat Availability', 'Total Incidents', 'Utilization %'];
            break;
        
        case 'user_activity':
            $data = generateUserActivityReport($conn, $date_from, $date_to);
            $report_data = $data['summary']['data'];
            $headers = ['User ID', 'Username', 'Full Name', 'Email', 'Role', 'Total Bookings', 'Incidents Reported', 'Account Created'];
            break;
            
        case 'performance':
            $data = generatePerformanceReport($conn, $date_from, $date_to);
            $report_data = $data['summary']['data'];
            $headers = ['Route ID', 'Route Name', 'Total Schedules', 'Completed Trips', 'Delayed Trips', 'Cancelled Trips', 'Avg Duration', 'Avg Seat Availability', 'Total Passengers', 'On Time %'];
            break;
        
        default:
            $report_data = [];
            $headers = ['No data available'];
            break;
    }
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data
    if (!empty($report_data)) {
        foreach ($report_data as $row) {
            fputcsv($output, array_values($row));
        }
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>System Reports - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Incident Details Modal Styles */
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        .close-modal:hover {
            color: #f44336;
        }

        /* Incident Details Content */
        .incident-details {
            font-size: 14px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .detail-item {
            margin-bottom: 8px;
        }

        .detail-item strong {
            display: inline-block;
            min-width: 120px;
            color: #495057;
        }

        .details-section {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .details-section h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .description-box, .notes-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin-top: 10px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .notes-box {
            background: #fff3cd;
            border-color: #ffeaa7;
        }

        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-type {
            background: #e3f2fd;
            color: #1565c0;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-critical {
            background: #721c24;
            color: white;
        }

        .status-reported {
            background: #fff3cd;
            color: #856404;
        }

        .status-under_review {
            background: #cce5ff;
            color: #004085;
        }

        .status-in_progress {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .status-closed {
            background: #d6d8db;
            color: #383d41;
        }

        .admin-status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .admin-status-approved {
            background: #d4edda;
            color: #155724;
        }

        .admin-status-declined {
            background: #f8d7da;
            color: #721c24;
        }

        /* Action Buttons */
        .incident-actions {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .approve-btn {
            background: #4CAF50;
            color: white;
        }

        .approve-btn:hover {
            background: #388E3C;
        }

        .decline-btn {
            background: #f44336;
            color: white;
        }

        .decline-btn:hover {
            background: #d32f2f;
        }

        .view-btn {
            background: #2196F3;
            color: white;
        }

        .view-btn:hover {
            background: #1976D2;
        }

        /* Admin Notes Form */
        .admin-notes-form {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #495057;
            font-weight: 500;
        }

        .form-textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            min-height: 80px;
            resize: vertical;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .confirm-decline {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .confirm-decline p {
            margin: 0 0 10px 0;
            color: #721c24;
        }
        
        .system-reports-container {
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
        
        .report-controls {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
        }
        
        .control-label {
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-control {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
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
        
        .btn-export {
            background: #2196F3;
        }
        
        .btn-export:hover {
            background: #0b7dda;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .reports-table th,
        .reports-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .reports-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .reports-table tr:hover {
            background: #f8f9fa;
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
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
            border-top: 4px solid #4CAF50;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.blue {
            border-top-color: #2196F3;
        }
        
        .stat-card.orange {
            border-top-color: #FF9800;
        }
        
        .stat-card.red {
            border-top-color: #F44336;
        }
        
        .stat-card.purple {
            border-top-color: #9C27B0;
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .stat-period {
            color: #999;
            font-size: 12px;
        }
        
        .chart-container {
            margin: 30px 0;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .chart-title {
            margin-bottom: 20px;
            color: #333;
            font-size: 18px;
        }
        
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .report-tab {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .report-tab:hover {
            background: #e9ecef;
        }
        
        .report-tab.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }
        
        .metric-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-right: 5px;
        }
        
        .metric-good {
            background: #d4edda;
            color: #155724;
        }
        
        .metric-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .metric-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .period-comparison {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .period-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .period-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .period-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .period-stat {
            text-align: center;
        }
        
        .period-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 5px;
        }
        
        .period-stat-label {
            font-size: 12px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .system-reports-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .controls-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .reports-table th,
            .reports-table td {
                padding: 8px 5px;
                font-size: 12px;
            }
            
            .report-tabs {
                flex-direction: column;
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
    <div class="system-reports-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">System Reports & Analytics</h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </button>
        </div>
        
        <!-- Report Tabs -->
        <div class="report-tabs">
            <a href="?report=overview" class="report-tab <?php echo $report_type == 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Overview
            </a>
            <a href="?report=booking_summary&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-tab <?php echo $report_type == 'booking_summary' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i> Booking Summary
            </a>
            <a href="?report=incident_summary&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-tab <?php echo $report_type == 'incident_summary' ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle"></i> Incident Summary
            </a>
            <a href="?report=shuttle_usage&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-tab <?php echo $report_type == 'shuttle_usage' ? 'active' : ''; ?>">
                <i class="fas fa-bus"></i> Shuttle Usage
            </a>
            <a href="?report=user_activity&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-tab <?php echo $report_type == 'user_activity' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> User Activity
            </a>
            <a href="?report=performance&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-tab <?php echo $report_type == 'performance' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Performance
            </a>
        </div>
        
        <!-- Report Controls -->
        <div class="report-controls">
            <form method="GET" action="" id="reportForm">
                <div class="controls-grid">
                    <div class="control-group">
                        <label class="control-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="control-group">
                        <label class="control-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <?php if (in_array($report_type, ['booking_summary', 'shuttle_usage', 'performance'])): ?>
                    <div class="control-group">
                        <label class="control-label">Route</label>
                        <select name="route_id" class="form-control">
                            <option value="">All Routes</option>
                            <?php foreach ($routes as $route): ?>
                                <option value="<?php echo $route['Route_ID']; ?>"
                                    <?php echo $route_id == $route['Route_ID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($route['Route_Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type == 'booking_summary'): ?>
                    <div class="control-group">
                        <label class="control-label">Schedule Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <?php foreach ($status_options as $status): ?>
                                <option value="<?php echo $status; ?>"
                                    <?php echo $status_filter == $status ? 'selected' : ''; ?>>
                                    <?php echo $status; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <input type="hidden" name="report" value="<?php echo $report_type; ?>">
                
                <div class="btn-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    
                    <?php if ($report_type != 'overview'): ?>
                    <button type="button" class="btn btn-export" onclick="exportReport('<?php echo $report_type; ?>')">
                        <i class="fas fa-download"></i> Export as CSV
                    </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
            </form>
            
            <!-- Quick Filter Buttons -->
            <div class="btn-group" style="margin-top: 15px;">
                <small style="margin-right: 10px; color: #666; align-self: center;">Quick Filters:</small>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="setDateRange(1)">Today</button>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="setDateRange(7)">Last 7 Days</button>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="setDateRange(30)">Last 30 Days</button>
                <button type="button" class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="setDateRange(90)">Last 90 Days</button>
            </div>
        </div>
        
        <!-- Report Content -->
        <?php if ($report_type == 'overview'): ?>
            <!-- Overview Dashboard -->
            <div class="section">
                <h2 class="section-title">System Overview Dashboard</h2>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-number"><?php echo $reports['overview']['statistics']['system']['total_users']; ?></div>
                        <div class="stat-period">Registered in system</div>
                    </div>
                    
                    <div class="stat-card blue">
                        <div class="stat-label">Active Students</div>
                        <div class="stat-number"><?php echo $reports['overview']['statistics']['system']['total_students']; ?></div>
                        <div class="stat-period">With shuttle access</div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-label">Active Vehicles</div>
                        <div class="stat-number"><?php echo $reports['overview']['statistics']['system']['active_vehicles']; ?></div>
                        <div class="stat-period">In service</div>
                    </div>
                    
                    <div class="stat-card purple">
                        <div class="stat-label">Active Routes</div>
                        <div class="stat-number"><?php echo $reports['overview']['statistics']['system']['active_routes']; ?></div>
                        <div class="stat-period">Currently operational</div>
                    </div>
                </div>
                
                <!-- Period Comparison -->
                <div class="period-comparison">
                    <div class="period-card">
                        <div class="period-title">Today (<?php echo date('Y-m-d'); ?>)</div>
                        <div class="period-stats">
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['today']['bookings'] ?? 0; ?></div>
                                <div class="period-stat-label">Bookings</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['today']['incidents'] ?? 0; ?></div>
                                <div class="period-stat-label">Incidents</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['today']['schedules'] ?? 0; ?></div>
                                <div class="period-stat-label">Schedules</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['today']['active_students'] ?? 0; ?></div>
                                <div class="period-stat-label">Active Students</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="period-card">
                        <div class="period-title">Last 7 Days</div>
                        <div class="period-stats">
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['week']['bookings'] ?? 0; ?></div>
                                <div class="period-stat-label">Bookings</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['week']['incidents'] ?? 0; ?></div>
                                <div class="period-stat-label">Incidents</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['week']['schedules'] ?? 0; ?></div>
                                <div class="period-stat-label">Schedules</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['week']['active_students'] ?? 0; ?></div>
                                <div class="period-stat-label">Active Students</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="period-card">
                        <div class="period-title">Last 30 Days</div>
                        <div class="period-stats">
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['month']['bookings'] ?? 0; ?></div>
                                <div class="period-stat-label">Bookings</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['month']['incidents'] ?? 0; ?></div>
                                <div class="period-stat-label">Incidents</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['month']['schedules'] ?? 0; ?></div>
                                <div class="period-stat-label">Schedules</div>
                            </div>
                            <div class="period-stat">
                                <div class="period-stat-value"><?php echo $reports['overview']['statistics']['month']['active_students'] ?? 0; ?></div>
                                <div class="period-stat-label">Active Students</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Bookings -->
            <div class="section">
                <h2 class="section-title">
                    Recent Bookings
                    <span style="font-size: 14px; color: #666;">(Last 10 bookings)</span>
                </h2>
                
                <div class="table-container">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Reservation ID</th>
                                <th>Student</th>
                                <th>Route</th>
                                <th>Departure Time</th>
                                <th>Booking Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reports['overview']['recent_bookings'])): ?>
                                <?php foreach ($reports['overview']['recent_bookings'] as $booking): ?>
                                    <tr>
                                        <td>#<?php echo $booking['Reservation_ID']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($booking['Student_Name']); ?></strong><br>
                                            <small><?php echo $booking['Student_Number']; ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($booking['Route_Name']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($booking['Departure_time'])); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($booking['Booking_Time'])); ?></td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $booking['Status'] == 'Reserved' ? 'metric-good' : 
                                                       ($booking['Status'] == 'Cancelled' ? 'metric-danger' : 'metric-warning'); ?>">
                                                <?php echo $booking['Status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="no-data">No recent bookings found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Incidents -->
            <div class="section">
                <h2 class="section-title">
                    Recent Incidents
                    <span style="font-size: 14px; color: #666;">(Last 10 incidents)</span>
                </h2>
                
                <div class="table-container">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Incident ID</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Admin Status</th>
                                <th>Report Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reports['overview']['recent_incidents'])): ?>
                                <?php foreach ($reports['overview']['recent_incidents'] as $incident): ?>
                                    <tr>
                                        <td>#<?php echo $incident['Incident_ID']; ?></td>
                                        <td><?php echo htmlspecialchars($incident['Incident_Type']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($incident['Description']); ?>
                                        </td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $incident['Priority'] == 'High' || $incident['Priority'] == 'Critical' ? 'metric-danger' : 
                                                       ($incident['Priority'] == 'Medium' ? 'metric-warning' : 'metric-good'); ?>">
                                                <?php echo $incident['Priority']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $incident['Status'] == 'Resolved' ? 'metric-good' : 
                                                       ($incident['Status'] == 'Closed' ? 'metric-warning' : 'metric-danger'); ?>">
                                                <?php echo $incident['Status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $incident['admin_status'] == 'Approved' ? 'metric-good' : 
                                                       ($incident['admin_status'] == 'Declined' ? 'metric-danger' : 'metric-warning'); ?>">
                                                <?php echo $incident['admin_status'] ?: 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($incident['Report_time'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="no-data">No recent incidents found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Upcoming Schedules -->
            <div class="section">
                <h2 class="section-title">
                    Upcoming Schedules
                    <span style="font-size: 14px; color: #666;">(Next 10 schedules)</span>
                </h2>
                
                <div class="table-container">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Schedule ID</th>
                                <th>Route</th>
                                <th>Departure Time</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Available Seats</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reports['overview']['upcoming_schedules'])): ?>
                                <?php foreach ($reports['overview']['upcoming_schedules'] as $schedule): ?>
                                    <tr>
                                        <td>#<?php echo $schedule['Schedule_ID']; ?></td>
                                        <td><?php echo htmlspecialchars($schedule['Route_Name']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($schedule['Departure_time'])); ?></td>
                                        <td><?php echo $schedule['Plate_number'] ?: 'Not assigned'; ?></td>
                                        <td><?php echo $schedule['Driver_Name'] ?: 'Not assigned'; ?></td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $schedule['Available_Seats'] > 10 ? 'metric-good' : 
                                                       ($schedule['Available_Seats'] > 0 ? 'metric-warning' : 'metric-danger'); ?>">
                                                <?php echo $schedule['Available_Seats']; ?> seats
                                            </span>
                                        </td>
                                        <td>
                                            <span class="metric-badge 
                                                <?php echo $schedule['Status'] == 'Scheduled' ? 'metric-good' : 
                                                       ($schedule['Status'] == 'In Progress' ? 'metric-warning' : 'metric-danger'); ?>">
                                                <?php echo $schedule['Status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="no-data">No upcoming schedules found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Detailed Report Content -->
            <div class="section">
                <h2 class="section-title">
                    <?php echo $reports['summary']['title']; ?>
                    <span style="font-size: 14px; color: #666;">(<?php echo $reports['summary']['period']; ?>)</span>
                </h2>
                
                <?php if (isset($reports['summary']['statistics'])): ?>
                    <div class="stats-grid">
                        <?php foreach ($reports['summary']['statistics'] as $key => $value): ?>
                            <?php if (!is_numeric($value)) continue; ?>
                            <div class="stat-card">
                                <div class="stat-label"><?php echo ucwords(str_replace('_', ' ', $key)); ?></div>
                                <div class="stat-number"><?php echo is_numeric($value) ? number_format($value) : $value; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($reports['summary']['user_stats'])): ?>
                    <div class="stats-grid">
                        <?php foreach ($reports['summary']['user_stats'] as $stat): ?>
                            <div class="stat-card">
                                <div class="stat-label"><?php echo $stat['Role_name']; ?> Users</div>
                                <div class="stat-number"><?php echo $stat['User_Count']; ?></div>
                                <?php if ($stat['New_Users_Period'] > 0): ?>
                                    <div class="stat-period">+<?php echo $stat['New_Users_Period']; ?> this period</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($reports['summary']['data'])): ?>
                    <div class="table-container">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <?php if ($report_type == 'incident_summary'): ?>
                                        <th>Incident ID</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Admin Status</th>
                                        <th>Report Time</th>
                                        <th>Actions</th>
                                    <?php else: ?>
                                        <?php foreach (array_keys($reports['summary']['data'][0]) as $column): ?>
                                            <th><?php echo ucwords(str_replace('_', ' ', $column)); ?></th>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($report_type == 'incident_summary'): ?>
                                    <?php foreach ($reports['summary']['data'] as $incident): ?>
                                        <tr>
                                            <td>#<?php echo $incident['Incident_ID']; ?></td>
                                            <td><?php echo htmlspecialchars($incident['Incident_Type']); ?></td>
                                            <td>
                                                <?php 
                                                $short_desc = $incident['Short_Description'] ?? 
                                                            (strlen($incident['Full_Description']) > 100 ? 
                                                             substr($incident['Full_Description'], 0, 100) . '...' : 
                                                             $incident['Full_Description']);
                                                echo htmlspecialchars($short_desc);
                                                ?>
                                            </td>
                                            <td>
                                                <span class="metric-badge 
                                                    <?php echo $incident['Priority'] == 'High' || $incident['Priority'] == 'Critical' ? 'metric-danger' : 
                                                           ($incident['Priority'] == 'Medium' ? 'metric-warning' : 'metric-good'); ?>">
                                                    <?php echo $incident['Priority']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="metric-badge 
                                                    <?php echo $incident['Status'] == 'Resolved' ? 'metric-good' : 
                                                           ($incident['Status'] == 'Closed' ? 'metric-warning' : 'metric-danger'); ?>">
                                                    <?php echo $incident['Status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="metric-badge 
                                                    <?php echo $incident['admin_status'] == 'Approved' ? 'metric-good' : 
                                                           ($incident['admin_status'] == 'Declined' ? 'metric-danger' : 'metric-warning'); ?>">
                                                    <?php echo $incident['admin_status'] ?: 'Pending'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($incident['Report_time'])); ?></td>
                                            <td>
                                                <button class="btn" style="padding: 5px 10px; font-size: 12px;" 
                                                        onclick="showIncidentDetails(<?php echo $incident['Incident_ID']; ?>)">
                                                    <i class="fas fa-eye"></i> View Details
                                                </button>
                                                <?php if (($incident['admin_status'] == 'Pending' || !$incident['admin_status']) && $incident['Status'] != 'Resolved' && $incident['Status'] != 'Closed'): ?>
                                                    <span class="metric-badge metric-warning" style="margin-left: 5px;">
                                                        <i class="fas fa-clock"></i> Needs Review
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($reports['summary']['data'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $key => $value): ?>
                                                <td>
                                                    <?php if (strpos($key, 'Percentage') !== false || strpos($key, 'Rate') !== false): ?>
                                                        <span class="metric-badge 
                                                            <?php echo $value > 80 ? 'metric-good' : 
                                                                   ($value > 50 ? 'metric-warning' : 'metric-danger'); ?>">
                                                            <?php echo is_numeric($value) ? number_format($value, 2) : $value; ?>%
                                                        </span>
                                                    <?php elseif (strpos($key, 'Status') !== false): ?>
                                                        <span class="metric-badge 
                                                            <?php echo $value == 'Completed' || $value == 'Resolved' ? 'metric-good' : 
                                                                   ($value == 'In Progress' || $value == 'Under Review' ? 'metric-warning' : 'metric-danger'); ?>">
                                                            <?php echo $value; ?>
                                                        </span>
                                                    <?php elseif (strpos($key, 'Priority') !== false): ?>
                                                        <span class="metric-badge 
                                                            <?php echo $value == 'High' || $value == 'Critical' ? 'metric-danger' : 
                                                                   ($value == 'Medium' ? 'metric-warning' : 'metric-good'); ?>">
                                                            <?php echo $value; ?>
                                                        </span>
                                                    <?php elseif (is_numeric($value) && !in_array($key, ['Vehicle_ID', 'Route_ID', 'Schedule_ID', 'User_ID'])): ?>
                                                        <?php echo number_format($value, 2); ?>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($value); ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-chart-bar fa-3x" style="color: #6c757d;"></i>
                        <h4>No Data Available</h4>
                        <p>No records found for the selected filters and period.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Chart Section -->
            <?php if (!empty($reports['summary']['data']) && count($reports['summary']['data']) > 0 && 
                     in_array($report_type, ['booking_summary', 'shuttle_usage', 'user_activity', 'performance'])): ?>
                <div class="chart-container">
                    <h3 class="chart-title">Visualization</h3>
                    <canvas id="reportChart" width="400" height="200"></canvas>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
    
    <!-- Incident Details Modal -->
    <div id="incidentModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Incident Details & Approval</h2>
                <button onclick="closeModal()" class="close-modal">&times;</button>
            </div>
            <div class="modal-body" id="incidentDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer" id="incidentActionsFooter" style="display: none;">
                <!-- Action buttons will be added here -->
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });
        
        // Incident details modal functions
        function showIncidentDetails(incidentId) {
            // Show loading
            document.getElementById('incidentDetailsContent').innerHTML = 
                '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading incident details...</p></div>';
            
            // Clear previous actions
            document.getElementById('incidentActionsFooter').innerHTML = '';
            document.getElementById('incidentActionsFooter').style.display = 'none';
            
            // Show modal
            document.getElementById('incidentModal').style.display = 'block';
            
            // Fetch incident details via AJAX
            fetch('getIncidentDetails.php?id=' + incidentId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('incidentDetailsContent').innerHTML = html;
                    
                    // Add action buttons if incident is pending
                    if (html.includes('admin-status-pending')) {
                        showActionButtons(incidentId);
                    }
                })
                .catch(error => {
                    document.getElementById('incidentDetailsContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading incident details. Please try again.</div>';
                    console.error('Error:', error);
                });
        }

        // Show action buttons for pending incidents
        function showActionButtons(incidentId) {
            const actionsFooter = document.getElementById('incidentActionsFooter');
            
            actionsFooter.innerHTML = `
                <div class="incident-actions">
                    <div class="admin-notes-form">
                        <div class="form-group">
                            <label class="form-label">Admin Notes (Optional):</label>
                            <textarea id="adminNotes" class="form-textarea" placeholder="Add any notes for the transport coordinator..."></textarea>
                        </div>
                        <div class="btn-group">
                            <button onclick="approveIncident(${incidentId})" class="action-btn approve-btn">
                                <i class="fas fa-check-circle"></i> Approve & Send to Coordinator
                            </button>
                            <button onclick="showDeclineForm(${incidentId})" class="action-btn decline-btn">
                                <i class="fas fa-times-circle"></i> Decline (Troll Report)
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            actionsFooter.style.display = 'block';
        }

        // Show decline confirmation form
        function showDeclineForm(incidentId) {
            const actionsFooter = document.getElementById('incidentActionsFooter');
            
            actionsFooter.innerHTML = `
                <div class="incident-actions">
                    <div class="confirm-decline">
                        <p><i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> This action will permanently delete the incident report. This should only be done for obvious troll/spam reports.</p>
                    </div>
                    <div class="admin-notes-form">
                        <div class="form-group">
                            <label class="form-label">Reason for Decline:</label>
                            <textarea id="declineReason" class="form-textarea" placeholder="Explain why this report is being declined..."></textarea>
                        </div>
                        <div class="btn-group">
                            <button onclick="declineIncident(${incidentId})" class="action-btn decline-btn">
                                <i class="fas fa-trash"></i> Confirm Decline & Delete
                            </button>
                            <button onclick="showIncidentDetails(${incidentId})" class="action-btn">
                                <i class="fas fa-arrow-left"></i> Back to Details
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Approve incident and send to transport coordinator
        function approveIncident(incidentId) {
            const notes = document.getElementById('adminNotes').value;
            
            if (!confirm('Are you sure you want to approve this incident and send it to the transport coordinator?')) {
                return;
            }
            
            // Show loading
            document.getElementById('incidentActionsFooter').innerHTML = 
                '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Processing approval...</div>';
            
            // Send approval request
            fetch('processIncidentAction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=approve&id=${incidentId}&notes=${encodeURIComponent(notes)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    document.getElementById('incidentActionsFooter').innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Incident approved and sent to transport coordinator!
                        </div>
                        <button onclick="closeModal()" class="action-btn">
                            <i class="fas fa-times"></i> Close
                        </button>
                    `;
                    
                    // Refresh the page after 2 seconds
                    setTimeout(() => {
                        closeModal();
                        location.reload();
                    }, 2000);
                } else {
                    document.getElementById('incidentActionsFooter').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Error: ${data.message}
                        </div>
                        <button onclick="showIncidentDetails(${incidentId})" class="action-btn">
                            <i class="fas fa-arrow-left"></i> Back to Details
                        </button>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('incidentActionsFooter').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> Network error. Please try again.
                    </div>
                    <button onclick="showIncidentDetails(${incidentId})" class="action-btn">
                        <i class="fas fa-arrow-left"></i> Back to Details
                    </button>
                `;
                console.error('Error:', error);
            });
        }

        // Decline incident (delete it)
        function declineIncident(incidentId) {
            const reason = document.getElementById('declineReason').value;
            
            if (!reason.trim()) {
                alert('Please provide a reason for declining this report.');
                return;
            }
            
            if (!confirm('⚠️ WARNING: This will permanently delete the incident report. Are you absolutely sure?')) {
                return;
            }
            
            // Show loading
            document.getElementById('incidentActionsFooter').innerHTML = 
                '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Deleting incident report...</div>';
            
            // Send decline request
            fetch('processIncidentAction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=decline&id=${incidentId}&reason=${encodeURIComponent(reason)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    document.getElementById('incidentActionsFooter').innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-trash"></i> Incident report deleted successfully.
                        </div>
                        <button onclick="closeModal()" class="action-btn">
                            <i class="fas fa-times"></i> Close
                        </button>
                    `;
                    
                    // Refresh the page after 2 seconds
                    setTimeout(() => {
                        closeModal();
                        location.reload();
                    }, 2000);
                } else {
                    document.getElementById('incidentActionsFooter').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Error: ${data.message}
                        </div>
                        <button onclick="showIncidentDetails(${incidentId})" class="action-btn">
                            <i class="fas fa-arrow-left"></i> Back to Details
                        </button>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('incidentActionsFooter').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> Network error. Please try again.
                    </div>
                    <button onclick="showIncidentDetails(${incidentId})" class="action-btn">
                        <i class="fas fa-arrow-left"></i> Back to Details
                    </button>
                `;
                console.error('Error:', error);
            });
        }

        function closeModal() {
            document.getElementById('incidentModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('incidentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Export report function
        function exportReport(reportType) {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            
            // Build query string
            const params = new URLSearchParams();
            params.append('export', 'true');
            params.append('report_type', reportType);
            
            for (const [key, value] of formData.entries()) {
                params.append(key, value);
            }
            
            // Use the current file path
            const currentPath = window.location.pathname;
            const url = currentPath + '?' + params.toString();
            
            console.log('Export URL:', url);
            window.location.href = url;
        }
        
        // Reset filters
        function resetFilters() {
            const today = new Date().toISOString().split('T')[0];
            const monthAgo = new Date();
            monthAgo.setDate(monthAgo.getDate() - 30);
            const monthAgoStr = monthAgo.toISOString().split('T')[0];
            
            // Build URL with only report type and default dates
            const url = `?report=<?php echo $report_type; ?>&date_from=${monthAgoStr}&date_to=${today}`;
            window.location.href = url;
        }
        
        // Quick date range function
        function setDateRange(days) {
            const today = new Date();
            const fromDate = new Date();
            fromDate.setDate(today.getDate() - days);
            
            const dateFromInput = document.querySelector('input[name="date_from"]');
            const dateToInput = document.querySelector('input[name="date_to"]');
            
            dateFromInput.value = fromDate.toISOString().split('T')[0];
            dateToInput.value = today.toISOString().split('T')[0];
            
            document.getElementById('reportForm').submit();
        }
        
        // Chart initialization
        <?php if (isset($reports['summary']) && !empty($reports['summary']['data']) && 
                 in_array($report_type, ['booking_summary', 'shuttle_usage', 'user_activity', 'performance'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('reportChart');
            if (!ctx) return;
            
            const ctx2d = ctx.getContext('2d');
            
            // Extract data for chart based on report type
            let labels = [];
            let datasets = [];
            
            const reportType = '<?php echo $report_type; ?>';
            
            if (reportType === 'booking_summary') {
                const reportData = <?php echo json_encode($reports['summary']['data']); ?>;
                if (reportData.length > 0) {
                    // Take top 10 for better visualization
                    const topData = reportData.slice(0, Math.min(10, reportData.length));
                    labels = topData.map(item => item.Route_Name + ' (' + item.Booking_Date + ')');
                    datasets = [
                        {
                            label: 'Confirmed Bookings',
                            data: topData.map(item => item.Confirmed_Bookings),
                            backgroundColor: '#4CAF50'
                        },
                        {
                            label: 'Cancelled Bookings',
                            data: topData.map(item => item.Cancelled_Bookings),
                            backgroundColor: '#F44336'
                        }
                    ];
                }
            } else if (reportType === 'shuttle_usage') {
                const shuttleData = <?php echo json_encode($reports['summary']['data']); ?>;
                if (shuttleData.length > 0) {
                    labels = shuttleData.map(item => item.Plate_number + ' (' + item.Model + ')');
                    datasets = [
                        {
                            label: 'Total Trips',
                            data: shuttleData.map(item => item.Total_Trips),
                            backgroundColor: '#2196F3'
                        },
                        {
                            label: 'Total Passengers',
                            data: shuttleData.map(item => item.Total_Passengers),
                            backgroundColor: '#9C27B0'
                        }
                    ];
                }
            } else if (reportType === 'user_activity') {
                <?php if (isset($reports['summary']['chart_data'])): ?>
                const userChartData = <?php echo json_encode($reports['summary']['chart_data']); ?>;
                if (userChartData && userChartData.labels && userChartData.labels.length > 0) {
                    labels = userChartData.labels;
                    datasets = [
                        {
                            label: 'Total Users',
                            data: userChartData.user_counts,
                            backgroundColor: '#4CAF50'
                        },
                        {
                            label: 'New Users (Period)',
                            data: userChartData.new_users,
                            backgroundColor: '#2196F3'
                        }
                    ];
                }
                <?php endif; ?>
            } else if (reportType === 'performance') {
                <?php if (isset($reports['summary']['chart_data'])): ?>
                const perfChartData = <?php echo json_encode($reports['summary']['chart_data']); ?>;
                if (perfChartData && perfChartData.labels && perfChartData.labels.length > 0) {
                    labels = perfChartData.labels;
                    datasets = [
                        {
                            label: 'Completed Trips',
                            data: perfChartData.on_time,
                            backgroundColor: '#4CAF50'
                        },
                        {
                            label: 'Delayed Trips',
                            data: perfChartData.delayed,
                            backgroundColor: '#FF9800'
                        }
                    ];
                }
                <?php endif; ?>
            }
            
            // Draw chart if we have data
            if (labels.length > 0 && datasets.length > 0) {
                new Chart(ctx2d, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: '<?php echo isset($reports["summary"]["title"]) ? $reports["summary"]["title"] : "Report Chart"; ?>'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Count'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: reportType === 'booking_summary' ? 'Routes (Date)' :
                                          reportType === 'shuttle_usage' ? 'Vehicles' :
                                          reportType === 'user_activity' ? 'User Roles' :
                                          reportType === 'performance' ? 'Routes' : 'Categories'
                                }
                            }
                        }
                    }
                });
            } else {
                // Hide chart container if no data
                const chartContainer = document.querySelector('.chart-container');
                if (chartContainer) {
                    chartContainer.style.display = 'none';
                }
            }
        });
        <?php endif; ?>
        
        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>