<!DOCTYPE html>
<html>
<head>
    <title>Select Staff Role</title>
    <style>
        body { font-family: Arial; text-align: center; padding: 50px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 40px; }
        .staff-options { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; }
        .staff-btn { 
            padding: 20px 40px; 
            background: white; 
            border: 2px solid; 
            border-radius: 10px; 
            font-size: 18px; 
            cursor: pointer;
            text-decoration: none;
            color: #333;
            width: 200px;
        }
        .driver-btn { border-color: #FF9800; }
        .coordinator-btn { border-color: #9C27B0; }
        .admin-btn { border-color: #F44336; }
        .staff-btn:hover { color: white; }
        .driver-btn:hover { background: #FF9800; }
        .coordinator-btn:hover { background: #9C27B0; }
        .admin-btn:hover { background: #F44336; }
        .back-btn { display: block; margin-top: 30px; color: #667eea; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Select Staff Role</h1>
        
        <div class="staff-options">
            <a href="driver_login.php" class="staff-btn driver-btn">Driver</a>
            <a href="coordinator_login.php" class="staff-btn coordinator-btn">Coordinator</a>
            <a href="admin_login.php" class="staff-btn admin-btn">Admin</a>
        </div>
        
        <a href="role_selection.php" class="back-btn">← Back to Role Selection</a>
    </div>
</body>
</html>