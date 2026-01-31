<?php
session_start();
require_once 'includes/config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $role = trim($_POST['role']);
    
    // Simple validation
    if (empty($username) || empty($password) || empty($role)) {
        header('Location: index.php?error=empty');
        exit();
    }
    
    // Query to check user
    $sql = "SELECT u.User_ID, u.Username, u.Password, r.Role_name 
            FROM user u
            JOIN user_roles ur ON u.User_ID = ur.User_ID
            JOIN roles r ON ur.Role_ID = r.Role_ID
            WHERE u.Username = ? AND r.Role_name = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Verify password (all passwords are: password123)
        if (password_verify($password, $user['Password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['User_ID'];
            $_SESSION['username'] = $user['Username'];
            $_SESSION['role'] = $user['Role_name'];
            
            // Redirect based on role
            if ($_SESSION['role'] == 'Admin') {
                header('Location: admin/dashboard.php');
            } elseif ($_SESSION['role'] == 'Student') {
                header('Location: student/dashboard.php');
            } elseif ($_SESSION['role'] == 'Driver') {
                header('Location: driver/dashboard.php');
            } elseif ($_SESSION['role'] == 'Transport Coordinator') {
                header('Location: coordinator/dashboard.php');
            } else {
                header('Location: index.php');
            }
            exit();
        } else {
            // Password incorrect
            redirectToLogin($role, 'incorrect');
        }
    } else {
        // User not found
        redirectToLogin($role, 'notfound');
    }
} else {
    header('Location: index.php');
    exit();
}

function redirectToLogin($role, $error) {
    switch ($role) {
        case 'Student':
            header('Location: student_login.php?error=' . $error);
            break;
        case 'Driver':
            header('Location: driver_login.php?error=' . $error);
            break;
        case 'Transport Coordinator':
            header('Location: coordinator_login.php?error=' . $error);
            break;
        case 'Admin':
            header('Location: admin_login.php?error=' . $error);
            break;
        default:
            header('Location: index.php?error=' . $error);
    }
    exit();
}
?>