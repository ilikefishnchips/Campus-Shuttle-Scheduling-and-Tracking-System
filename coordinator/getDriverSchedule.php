<?php
session_start();
require_once '../includes/config.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    exit('Unauthorized');
}

$driver_id = $_GET['driver_id'] ?? 0;

$schedules = $conn->query("
    SELECT ss.*, r.Route_Name, v.Plate_number, v.Model,
           DATE(ss.Departure_time) as schedule_date,
           TIME(ss.Departure_time) as schedule_time
    FROM shuttle_schedule ss
    JOIN route r ON ss.Route_ID = r.Route_ID
    JOIN vehicle v ON ss.Vehicle_ID = v.Vehicle_ID
    WHERE ss.Driver_ID = $driver_id
    AND ss.Departure_time >= CURDATE()
    AND ss.Status = 'Scheduled'
    ORDER BY ss.Departure_time
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

if(empty($schedules)): ?>
    <div class="no-schedules">
        No upcoming schedules found for this driver.
    </div>
<?php else: 
    // Group schedules by date
    $grouped_schedules = [];
    foreach($schedules as $schedule) {
        $date = $schedule['schedule_date'];
        $grouped_schedules[$date][] = $schedule;
    }
?>
    <?php foreach($grouped_schedules as $date => $schedules): ?>
        <div class="schedule-day">
            <div class="day-header">
                <div><?php echo date('l, F j, Y', strtotime($date)); ?></div>
                <div class="day-date"><?php echo count($schedules); ?> trips</div>
            </div>
            <div class="day-schedules">
                <?php foreach($schedules as $schedule): ?>
                    <div class="schedule-item">
                        <div class="schedule-info">
                            <h4><?php echo $schedule['Route_Name']; ?></h4>
                            <div class="schedule-details">
                                <span class="schedule-time">
                                    <?php echo date('H:i', strtotime($schedule['schedule_time'])); ?>
                                </span>
                                <span class="schedule-vehicle">
                                    <?php echo $schedule['Plate_number']; ?>
                                </span>
                                <span class="schedule-route">
                                    Seats: <?php echo $schedule['Available_Seats']; ?>
                                </span>
                            </div>
                        </div>
                        <button class="remove-btn" 
                                onclick="removeSchedule(<?php echo $schedule['Schedule_ID']; ?>)">
                            Cancel
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>