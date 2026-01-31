<!DOCTYPE html>
<html>
<head>
    <title>Campus Shuttle System</title>
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Campus Shuttle Scheduling System</h1>
        <p>Track shuttles, book seats, and manage campus transportation</p>
        
        <form action="role_selection.php" method="GET">
            <button type="submit" class="start-btn">Get Started →</button>
        </form>
    </div>
</body>
</html>