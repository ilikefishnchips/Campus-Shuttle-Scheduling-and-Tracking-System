<?php
session_start();
require_once '../includes/config.php';

/* -----------------------------------------
   TIMEZONE
----------------------------------------- */
date_default_timezone_set('Asia/Kuala_Lumpur');

/* -----------------------------------------
   Access control
----------------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* -----------------------------------------
   Get Student_ID
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT Student_ID
    FROM student_profile
    WHERE User_ID = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student profile not found.");
}

$student_id = (int)$student['Student_ID'];

/* -----------------------------------------
   Get bookings with REAL schedule datetime
----------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        sr.Reservation_ID,
        sr.Seat_number,
        sr.Status AS db_status,
        ss.Departure_time,
        ss.Expected_Arrival,
        r.Route_Name
    FROM seat_reservation sr
    JOIN route r ON sr.Route_ID = r.Route_ID
    JOIN shuttle_schedule ss ON sr.Schedule_ID = ss.Schedule_ID
    WHERE sr.Student_ID = ?
    ORDER BY ss.Departure_time DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$bookings = $stmt->get_result();

$now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Bookings</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f5f5f5;
            margin: 0;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-title {
            font-size: 26px;
            margin-bottom: 20px;
            color: #333;
        }

        .success {
            background: #E8F5E9;
            color: #2E7D32;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .booking-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .booking-table th {
            background: #2196F3;
            color: white;
            padding: 15px;
            text-align: left;
        }

        .booking-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }

        .status-reserved {
            background: #4CAF50;
            color: white;
        }

        .status-inprogress {
            background: #FF9800;
            color: white;
        }

        .status-completed {
            background: #333;
            color: white;
        }

        .status-cancelled {
            background: #F44336;
            color: white;
        }

        .cancel-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }

        .cancel-btn:hover {
            background: #D32F2F;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #777;
            font-style: italic;
        }
    </style>
</head>
<body>

<?php include 'student_navbar.php'; ?>

<div class="container">
    <div class="page-title">My Shuttle Reservations</div>

    <?php if (isset($_GET['success'])): ?>
        <div class="success">✅ Booking successful!</div>
    <?php endif; ?>

    <?php if ($bookings->num_rows > 0): ?>
        <table class="booking-table">
            <tr>
                <th>Route</th>
                <th>Date</th>
                <th>Time</th>
                <th>Seat</th>
                <th>Status</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $bookings->fetch_assoc()): ?>
                <?php
                $departure = new DateTime($row['Departure_time']);
                $arrival   = new DateTime($row['Expected_Arrival']);

                if ($row['db_status'] === 'Cancelled') {
                    $displayStatus = 'Cancelled';
                    $statusClass = 'status-cancelled';
                } elseif ($now < $departure) {
                    $displayStatus = 'Reserved';
                    $statusClass = 'status-reserved';
                } elseif ($now >= $departure && $now <= $arrival) {
                    $displayStatus = 'In Progress';
                    $statusClass = 'status-inprogress';
                } else {
                    $displayStatus = 'Completed';
                    $statusClass = 'status-completed';
                }
                ?>

                <tr>
                    <td><?= htmlspecialchars($row['Route_Name']); ?></td>
                    <td><?= $departure->format('Y-m-d'); ?></td>
                    <td><?= $departure->format('H:i'); ?></td>
                    <td>#<?= (int)$row['Seat_number']; ?></td>
                    <td>
                        <span class="status-badge <?= $statusClass; ?>">
                            <?= $displayStatus; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($displayStatus === 'Reserved'): ?>
                            <form action="cancel_booking.php" method="POST" style="margin:0;">
                                <input type="hidden" name="reservation_id" value="<?= (int)$row['Reservation_ID']; ?>">
                                <button type="submit" class="cancel-btn"
                                        onclick="return confirm('Cancel this booking?');">
                                    Cancel
                                </button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>

            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <div class="no-data">You have no bookings yet.</div>
    <?php endif; ?>
</div>

</body>
</html>
