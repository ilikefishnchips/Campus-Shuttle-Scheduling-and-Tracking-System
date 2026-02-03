<?php
session_start();
require_once '../includes/config.php';

/* ===============================
   TIMEZONE
================================ */
date_default_timezone_set('Asia/Kuala_Lumpur');

/* ===============================
   ACCESS CONTROL
================================ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* ===============================
   REQUIRED DATA
================================ */
if (
    !isset($_SESSION['schedule_id']) ||
    !isset($_POST['seat_number'])
) {
    header('Location: book_shuttle.php');
    exit();
}

$user_id     = (int) $_SESSION['user_id'];
$schedule_id = (int) $_SESSION['schedule_id'];
$seat_number = (int) $_POST['seat_number'];

/* ===============================
   GET STUDENT_ID
================================ */
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

$student_id = (int) $student['Student_ID'];

/* ===============================
   VERIFY SCHEDULE IS BOOKABLE
================================ */
$stmt = $conn->prepare("
    SELECT Route_ID, Available_Seats
    FROM shuttle_schedule
    WHERE Schedule_ID = ?
      AND Status = 'Scheduled'
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();

if (!$schedule) {
    die("❌ This shuttle is no longer available for booking.");
}

if ($schedule['Available_Seats'] <= 0) {
    die("❌ No seats available on this shuttle.");
}

$route_id = (int) $schedule['Route_ID'];

/* ===============================
   PREVENT DOUBLE SEAT BOOKING
================================ */
$stmt = $conn->prepare("
    SELECT 1
    FROM seat_reservation
    WHERE Schedule_ID = ?
      AND Seat_number = ?
      AND Status = 'Reserved'
");
$stmt->bind_param("ii", $schedule_id, $seat_number);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    die("❌ Seat already reserved. Please choose another seat.");
}

/* ===============================
   HANDLE REBOOKING
================================ */
$stmt = $conn->prepare("
    SELECT Reservation_ID
    FROM seat_reservation
    WHERE Schedule_ID = ?
      AND Seat_number = ?
      AND Status = 'Cancelled'
    LIMIT 1
");
$stmt->bind_param("ii", $schedule_id, $seat_number);
$stmt->execute();
$cancelled = $stmt->get_result()->fetch_assoc();

if ($cancelled) {
    // ♻️ Reuse cancelled reservation
    $stmt = $conn->prepare("
        UPDATE seat_reservation
        SET Student_ID = ?,
            Status = 'Reserved',
            Booking_Time = NOW()
        WHERE Reservation_ID = ?
    ");
    $stmt->bind_param("ii", $student_id, $cancelled['Reservation_ID']);
    $stmt->execute();
} else {
    // 🆕 New reservation
    $stmt = $conn->prepare("
        INSERT INTO seat_reservation
            (Student_ID, Schedule_ID, Route_ID, Seat_number, Status, Booking_Time)
        VALUES
            (?, ?, ?, ?, 'Reserved', NOW())
    ");
    $stmt->bind_param(
        "iiii",
        $student_id,
        $schedule_id,
        $route_id,
        $seat_number
    );
    $stmt->execute();
}

/* ===============================
   UPDATE AVAILABLE SEATS
================================ */
$stmt = $conn->prepare("
    UPDATE shuttle_schedule
    SET Available_Seats = Available_Seats - 1
    WHERE Schedule_ID = ?
      AND Available_Seats > 0
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();

/* ===============================
   CLEAN SESSION
================================ */
unset(
    $_SESSION['schedule_id'],
    $_SESSION['route_id'],
    $_SESSION['travel_date'],
    $_SESSION['pickup_stop_id'],
    $_SESSION['dropoff_stop_id']
);

/* ===============================
   SUCCESS
================================ */
header("Location: my_bookings.php?success=1");
exit();
