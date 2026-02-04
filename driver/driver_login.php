<?php
session_start();
require_once '../includes/config.php';

/* ===============================
   REDIRECT IF ALREADY LOGGED IN
================================ */
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'Driver') {
    header('Location: driver_dashboard.php');
    exit();
}

/* ===============================
   LOGIN LOGIC (UNCHANGED)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = trim($_POST['driver_id']);
    $password  = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT u.User_ID, u.Password, u.Username
        FROM user u
        JOIN user_roles ur ON u.User_ID = ur.User_ID
        JOIN roles r ON ur.Role_ID = r.Role_ID
        WHERE u.Username = ? AND r.Role_Name = 'Driver'
    ");
    $stmt->bind_param("s", $driver_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['Password'])) {
        $_SESSION['user_id']  = $user['User_ID'];
        $_SESSION['username'] = $user['Username'];
        $_SESSION['role']     = 'Driver';

        header("Location: driver_dashboard.php");
        exit();
    } else {
        $error = "Invalid Driver ID or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MMU Shuttle | Driver Login</title>

<style>
* {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, sans-serif;
}

body {
    margin: 0;
    height: 100vh;
    background: 
        linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.45)),
        url('../assets/indexBg.jpg') no-repeat center center / cover;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Login Card */
.login-card {
    background: #fff;
    width: 360px;
    padding: 35px 30px;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    text-align: center;
}

/* Logo */
.logo img {
    width: 180px;
    margin-bottom: 20px;
}

/* Title */
.login-card h2 {
    margin-bottom: 20px;
    font-weight: 600;
    color: #222;
}

/* Inputs */
.input-group {
    margin-bottom: 15px;
}

.input-group input {
    width: 100%;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 15px;
}

.input-group input:focus {
    outline: none;
    border-color: #1976D2;
}

/* Button */
.login-btn {
    width: 100%;
    padding: 12px;
    background: #1976D2;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
}

.login-btn:hover {
    background: #0D47A1;
}

/* Error */
.error {
    background: #ffebee;
    color: #c62828;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    font-size: 14px;
}

/* Footer text */
.footer-text {
    margin-top: 15px;
    font-size: 13px;
    color: #777;
}
</style>
</head>

<body>

<div class="login-card">

    <div class="logo">
        <img src="../assets/mmuShuttleLogo2.png" alt="MMU Shuttle">
    </div>

    <h2>Log in as Driver</h2>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <input type="text" name="driver_id" placeholder="Driver ID" required>
        </div>

        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>

        <button type="submit" class="login-btn">Proceed</button>
    </form>

    <div class="footer-text">
        MMU Shuttle Transport System
    </div>

</div>

</body>
</html>
