<?php
session_start();
// Redirect if already logged in as student
if(isset($_SESSION['user_id']) && $_SESSION['role'] == 'Student') {
    header('Location: student/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Login</title>
    <style>
        body { 
            font-family: Arial; 
            background: linear-gradient(135deg, #2196F3, #1976D2);
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
        .student-badge {
            display: inline-block;
            background: #2196F3;
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
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        .login-btn:hover {
            background: #1976D2;
        }
        .test-credentials {
            margin-top: 20px;
            padding: 15px;
            background: #E3F2FD;
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
        <h1>Student Login</h1>
        <div style="text-align: center;">
            <span class="student-badge">STUDENT PORTAL</span>
        </div>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="error">Invalid username or password!</div>
        <?php endif; ?>
        
        <form action="login_process.php" method="POST">
            <input type="hidden" name="role" value="Student">
            
            <div class="form-group">
                <input type="text" name="username" placeholder="Student ID / Username" required>
            </div>
            
            <div class="form-group">
                <input type="password" name="password" placeholder="Password" required>
            </div>
            
            <button type="submit" class="login-btn">Login to Student Portal</button>
        </form>
        
        <div class="test-credentials">
            <strong>Test Student Accounts:</strong><br>
            • Username: <strong>student1</strong> | Password: <strong>password</strong><br>
            • Username: <strong>student2</strong> | Password: <strong>password</strong><br>
            • Username: <strong>student3</strong> | Password: <strong>password</strong>
        </div>
        
        <a href="role_selection.php" class="back-btn">← Back to Role Selection</a>
    </div>
</body>
</html>