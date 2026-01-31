<?php
require_once 'config.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check specific role
function checkRole($requiredRole) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] != $requiredRole) {
        header('Location: ../index.php');
        exit();
    }
}

// Redirect based on role
function redirectBasedOnRole() {
    if (isLoggedIn()) {
        switch ($_SESSION['role']) {
            case 'Student':
                header('Location: student/dashboard.php');
                break;
            case 'Driver':
                header('Location: driver/dashboard.php');
                break;
            case 'Transport Coordinator':
                header('Location: coordinator/dashboard.php');
                break;
            case 'Admin':
                header('Location: admin/dashboard.php');
                break;
            default:
                header('Location: index.php');
        }
        exit();
    }
}
?>