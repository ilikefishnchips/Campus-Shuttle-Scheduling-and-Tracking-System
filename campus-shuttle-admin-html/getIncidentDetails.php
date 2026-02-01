<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

if(!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="alert alert-danger">Invalid incident ID</div>';
    exit();
}

$incident_id = intval($_GET['id']);

// Fetch incident details
$sql = "SELECT 
            ir.*,
            u.Full_Name as Reporter_Name,
            u.Email as Reporter_Email,
            u.Username as Reporter_Username,
            v.Plate_number as Vehicle_Plate,
            v.Model as Vehicle_Model,
            v.Capacity as Vehicle_Capacity,
            ss.Schedule_ID,
            ss.Departure_time,
            r.Route_Name,
            r.Start_Location,
            r.End_Location,
            d.Full_Name as Driver_Name
        FROM incident_reports ir
        LEFT JOIN user u ON ir.Reporter_ID = u.User_ID
        LEFT JOIN vehicle v ON ir.Vehicle_ID = v.Vehicle_ID
        LEFT JOIN shuttle_schedule ss ON ir.Schedule_ID = ss.Schedule_ID
        LEFT JOIN route r ON ss.Route_ID = r.Route_ID
        LEFT JOIN user d ON ss.Driver_ID = d.User_ID
        WHERE ir.Incident_ID = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    echo '<div class="alert alert-warning">Incident not found</div>';
    exit();
}

$incident = $result->fetch_assoc();

// Function to format date
function formatDate($date) {
    if($date) {
        return date('F j, Y, g:i a', strtotime($date));
    }
    return 'N/A';
}

// Priority badge color
function getPriorityClass($priority) {
    switch($priority) {
        case 'Critical': return 'metric-danger';
        case 'High': return 'metric-danger';
        case 'Medium': return 'metric-warning';
        case 'Low': return 'metric-good';
        default: return '';
    }
}

// Status badge color
function getStatusClass($status) {
    switch($status) {
        case 'Resolved': return 'metric-good';
        case 'Closed': return 'metric-warning';
        case 'In Progress': return 'metric-warning';
        case 'Under Review': return 'metric-warning';
        case 'Reported': return 'metric-danger';
        default: return '';
    }
}
?>

<style>
    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .detail-card {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border-left: 4px solid #4CAF50;
    }
    
    .detail-card.blue {
        border-left-color: #2196F3;
    }
    
    .detail-card.orange {
        border-left-color: #FF9800;
    }
    
    .detail-card.red {
        border-left-color: #F44336;
    }
    
    .detail-label {
        font-weight: 600;
        color: #555;
        font-size: 14px;
        margin-bottom: 5px;
    }
    
    .detail-value {
        font-size: 16px;
        color: #333;
    }
    
    .description-box {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        margin: 20px 0;
        white-space: pre-wrap;
        line-height: 1.6;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }
    
    .badge-warning {
        background: #fff3cd;
        color: #856404;
    }
    
    .badge-success {
        background: #d4edda;
        color: #155724;
    }
    
    .badge-info {
        background: #d1ecf1;
        color: #0c5460;
    }
</style>

<div class="details-grid">
    <div class="detail-card">
        <div class="detail-label">Incident ID</div>
        <div class="detail-value">#<?php echo $incident['Incident_ID']; ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-label">Incident Type</div>
        <div class="detail-value"><?php echo htmlspecialchars($incident['Incident_Type']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-label">Priority</div>
        <div class="detail-value">
            <span class="badge <?php echo getPriorityClass($incident['Priority']); ?>">
                <?php echo $incident['Priority']; ?>
            </span>
        </div>
    </div>
    
    <div class="detail-card">
        <div class="detail-label">Status</div>
        <div class="detail-value">
            <span class="badge <?php echo getStatusClass($incident['Status']); ?>">
                <?php echo $incident['Status']; ?>
            </span>
        </div>
    </div>
    
    <div class="detail-card blue">
        <div class="detail-label">Reported By</div>
        <div class="detail-value">
            <?php echo htmlspecialchars($incident['Reporter_Name'] ?: 'N/A'); ?><br>
            <small><?php echo htmlspecialchars($incident['Reporter_Email'] ?: ''); ?></small>
        </div>
    </div>
    
    <div class="detail-card blue">
        <div class="detail-label">Report Time</div>
        <div class="detail-value"><?php echo formatDate($incident['Report_time']); ?></div>
    </div>
    
    <?php if($incident['Resolved_Time']): ?>
    <div class="detail-card orange">
        <div class="detail-label">Resolved Time</div>
        <div class="detail-value"><?php echo formatDate($incident['Resolved_Time']); ?></div>
    </div>
    
    <?php if($incident['Report_time'] && $incident['Resolved_Time']): 
        $hours = round((strtotime($incident['Resolved_Time']) - strtotime($incident['Report_time'])) / 3600, 2);
    ?>
    <div class="detail-card orange">
        <div class="detail-label">Resolution Time</div>
        <div class="detail-value"><?php echo $hours; ?> hours</div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if($incident['Vehicle_Plate']): ?>
<div class="detail-card">
    <div class="detail-label">Vehicle Involved</div>
    <div class="detail-value">
        <?php echo htmlspecialchars($incident['Vehicle_Plate']); ?> 
        (<?php echo htmlspecialchars($incident['Vehicle_Model']); ?>)
    </div>
</div>
<?php endif; ?>

<?php if($incident['Schedule_ID']): ?>
<div class="detail-card">
    <div class="detail-label">Schedule Details</div>
    <div class="detail-value">
        Schedule #<?php echo $incident['Schedule_ID']; ?><br>
        Route: <?php echo htmlspecialchars($incident['Route_Name']); ?><br>
        Departure: <?php echo formatDate($incident['Departure_time']); ?><br>
        Driver: <?php echo htmlspecialchars($incident['Driver_Name'] ?: 'N/A'); ?>
    </div>
</div>
<?php endif; ?>

<div class="description-box">
    <div class="detail-label" style="margin-bottom: 10px;">Description</div>
    <div class="detail-value"><?php echo nl2br(htmlspecialchars($incident['Description'])); ?></div>
</div>

<?php if($incident['Resolved_Time']): ?>
<div class="alert alert-success" style="padding: 10px 15px; background: #d4edda; color: #155724; border-radius: 5px; margin-top: 20px;">
    <i class="fas fa-check-circle"></i> This incident has been resolved on <?php echo formatDate($incident['Resolved_Time']); ?>
</div>
<?php else: ?>
<div class="alert alert-warning" style="padding: 10px 15px; background: #fff3cd; color: #856404; border-radius: 5px; margin-top: 20px;">
    <i class="fas fa-exclamation-triangle"></i> This incident is currently <?php echo strtolower($incident['Status']); ?>
</div>
<?php endif; ?>