<!DOCTYPE html>
<html>
<head>
    <title>Campus Shuttle System</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Use relative paths from your current directory -->
    <link rel="stylesheet" href="index.css">
    <link rel="stylesheet" href="css/admin/dashboard.css">
    <style>
        body { font-family: Arial; text-align: center; padding: 50px; }
        .container { max-width: 500px; margin: 0 auto; }
        h1 { color: #333; }
        .start-btn { 
            background: #667eea; 
            color: white; 
            padding: 15px 40px; 
            border: none; 
            border-radius: 5px; 
            font-size: 18px; 
            cursor: pointer; 
            margin-top: 20px;
        }
        .start-btn:hover { background: #5a67d8; }
        .role-options { display: flex; justify-content: center; gap: 20px; }
        .role-btn { 
            padding: 5px 20px; 
            background: white; 
            border: 2px solid #000000; 
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
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-logo">
                <img src="assets/mmuShuttleLogo2.png" alt="Logo" class="logo-icon">
            </div>
        <div class="role-options">
            <a href="student_login.php" class="role-btn">Student</a>
            <a href="staff_selection.php" class="role-btn">Staff</a>
        </div>
        </div>
    </nav>

    <div class="container">
        <div class="title-bar">
            <h1>Campus Shuttle Scheduling System and Tracking System</h1>
        </div>
        
        <form action="role_selection.php" method="GET">
            <button type="submit" class="start-btn">Get Started →</button>
        </form>
    </div>
</body>
</html>