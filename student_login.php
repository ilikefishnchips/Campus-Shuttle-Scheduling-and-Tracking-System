<?php
session_start();
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'Student') {
    header('Location: student/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MMU Shuttle | Student Login</title>

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
        url('assets/indexBg.jpg') no-repeat center center / cover;
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
    border-color: #e53935;
}

/* Button */
.login-btn {
    width: 100%;
    padding: 12px;
    background: #e53935;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
}

.login-btn:hover {
    background: #c62828;
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
        <img src="assets/mmuShuttleLogo2.png" alt="MMU Shuttle">
    </div>

    <h2>Log in as Student</h2>

    <?php if (isset($_GET['error'])): ?>
        <div class="error">Invalid Student ID or password</div>
    <?php endif; ?>

    <form method="POST" action="login_process.php">
        <input type="hidden" name="role" value="Student">

        <div class="input-group">
            <input type="text" name="username" placeholder="Student ID" required>
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
