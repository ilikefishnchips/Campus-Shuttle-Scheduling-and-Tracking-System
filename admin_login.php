<?php
session_start();
// Redirect if already logged in as admin
if(isset($_SESSION['user_id']) && $_SESSION['role'] == 'Admin') {
    header('Location: admin/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <style>
        body { 
            font-family: Arial; 
            background: linear-gradient(135deg, #F44336, #EF5350);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 350px;
        }
        .login-box h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .admin-badge {
            display: inline-block;
            background: #F44336;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .login-btn {
            width: 100%;
            padding: 12px;
            background: #F44336;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        .login-btn:hover {
            background: #d32f2f;
        }
        .test-credentials {
            margin-top: 20px;
            padding: 15px;
            background: #ffebee;
            border-radius: 5px;
            font-size: 14px;
        }
        .error {
            color: #F44336;
            text-align: center;
            margin-bottom: 15px;
        }
        .back-btn {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Admin Login</h1>
        <div style="text-align: center;">
            <span class="admin-badge">SYSTEM ADMINISTRATOR</span>
        </div>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="error">Invalid username or password!</div>
        <?php endif; ?>
        
        <form action="login_process.php" method="POST">
            <input type="hidden" name="role" value="Admin">
            
            <div class="form-group">
                <input type="text" name="username" placeholder="Admin Username" required>
            </div>
            
            <div class="form-group">
                <input type="password" name="password" placeholder="Password" required>
            </div>
            
            <button type="submit" class="login-btn">Login to Admin Panel</button>
        </form>
        
        <div class="test-credentials">
            <strong>Test Admin Account:</strong><br>
            Username: <strong>admin</strong><br>
            Password: <strong>password</strong>
        </div>
        
        <a href="staff_selection.php" class="back-btn">← Back to Staff Selection</a>
    </div>
</body>
</html>