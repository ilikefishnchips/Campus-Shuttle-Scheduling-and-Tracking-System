<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header('Location: ../student_login.php');
    exit();
}

if (!isset($_POST['reservation_id'])) {
    header('Location: my_bookings.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$reservation_id = (int)$_POST['reservation_id'];

/* Verify ownership */
$stmt = $conn->prepare("
    SELECT sr.Reservation_ID
    FROM seat_reservation sr
    JOIN student_profile sp ON sr.Student_ID = sp.Student_ID
    WHERE sr.Reservation_ID = ?
      AND sp.User_ID = ?
      AND sr.Status = 'Reserved'
");
$stmt->bind_param("ii", $reservation_id, $user_id);
$stmt->execute();

if ($stmt->get_result()->num_rows === 0) {
    header('Location: my_bookings.php');
    exit();
}

/* Cancel booking */
$stmt = $conn->prepare("
    UPDATE seat_reservation
    SET Status = 'Cancelled'
    WHERE Reservation_ID = ?
");
$stmt->bind_param("i", $reservation_id);
$stmt->execute();

header('Location: my_bookings.php?cancelled=1');
exit();
