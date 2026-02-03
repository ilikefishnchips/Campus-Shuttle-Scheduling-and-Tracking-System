<?php
session_start();
require_once '../includes/config.php';

/* ---------------------------
   Access control
--------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

/* ---------------------------
   Required data
--------------------------- */
if (
    !isset($_SESSION['route_id']) ||
    !isset($_SESSION['time_id']) ||
    !isset($_POST['seat_number'])
) {
    header('Location: book_shuttle.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$route_id = (int)$_SESSION['route_id'];
$time_id  = (int)$_SESSION['time_id'];
$seat_number = (int)$_POST['seat_number'];

/* ---------------------------
   Get Student_ID
--------------------------- */
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

/* ---------------------------
   Prevent double booking
--------------------------- */
$stmt = $conn->prepare("
    SELECT 1
    FROM seat_reservation
    WHERE Route_ID = ?
      AND Time_ID = ?
      AND Seat_number = ?
      AND Status = 'Reserved'
");
$stmt->bind_param("iii", $route_id, $time_id, $seat_number);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    die("❌ Seat already reserved. Please choose another seat.");
}

/* ---------------------------
   Insert booking
--------------------------- */
$stmt = $conn->prepare("
    INSERT INTO seat_reservation
    (Student_ID, Seat_number, Route_ID, Time_ID, Status)
    VALUES (?, ?, ?, ?, 'Reserved')
");
$stmt->bind_param(
    "iiii",
    $student_id,
    $seat_number,
    $route_id,
    $time_id
);

if (!$stmt->execute()) {
    die("Booking failed. Please try again.");
}

/* ---------------------------
   Clear booking session
--------------------------- */
unset(
    $_SESSION['route_id'],
    $_SESSION['time_id'],
    $_SESSION['pickup_stop_id'],
    $_SESSION['dropoff_stop_id']
);

/* ---------------------------
   Success
--------------------------- */
header("Location: my_bookings.php?success=1");
exit();
