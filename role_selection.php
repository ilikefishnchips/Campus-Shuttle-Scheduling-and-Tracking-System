<!DOCTYPE html>
<html>
<head>
    <title>Select Role</title>
    <style>
        body { font-family: Arial; text-align: center; padding: 50px; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 40px; }
        .role-options { display: flex; justify-content: center; gap: 20px; }
        .role-btn { 
            padding: 20px 40px; 
            background: white; 
            border: 2px solid #667eea; 
            border-radius: 10px; 
            font-size: 18px; 
            cursor: pointer;
            text-decoration: none;
            color: #333;
        }
        .role-btn:hover { background: #667eea; color: white; }
        .back-btn { display: block; margin-top: 30px; color: #667eea; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Select Your Role</h1>
        
        <div class="role-options">
            <a href="student_login.php" class="role-btn">Student</a>
            <a href="staff_selection.php" class="role-btn">Staff</a>
        </div>
        
        <a href="index.php" class="back-btn">← Back to Home</a>
    </div>
</body>
</html>