<?php
session_start();
require_once '../includes/config.php';

// 检查用户是否登录
if(!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// 获取用户通知
$user_id = $_SESSION['user_id'];
$notifications_sql = "SELECT * FROM notifications 
                      WHERE User_ID = ? OR User_ID IS NULL 
                      ORDER BY Created_At DESC LIMIT 50";
$notifications_stmt = $conn->prepare($notifications_sql);
$notifications_stmt->bind_param("i", $user_id);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();
$notifications = $notifications_result->fetch_all(MYSQLI_ASSOC);

// 标记为已读
if (isset($_GET['mark_as_read']) && is_numeric($_GET['mark_as_read'])) {
    $notification_id = intval($_GET['mark_as_read']);
    $update_sql = "UPDATE notifications SET Status = 'Read', Read_At = NOW() 
                   WHERE Notification_ID = ? AND User_ID = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $notification_id, $user_id);
    $update_stmt->execute();
    
    header('Location: notifications.php');
    exit();
}

// 删除通知
if (isset($_POST['delete_notification'])) {
    $notification_id = intval($_POST['notification_id']);
    $delete_sql = "UPDATE notifications SET Status = 'Deleted' 
                   WHERE Notification_ID = ? AND User_ID = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ii", $notification_id, $user_id);
    $delete_stmt->execute();
    
    header('Location: notifications.php');
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Notifications - Campus Shuttle</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notifications-container {
            max-width: 800px;
            margin: 80px auto 30px;
            padding: 20px;
        }
        
        .notification-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #4CAF50;
        }
        
        .notification-card.unread {
            border-left-color: #f44336;
            background: #fff5f5;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .notification-title {
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        
        .notification-time {
            font-size: 12px;
            color: #666;
        }
        
        .notification-message {
            color: #555;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        
        .btn-mark-read {
            background: #4CAF50;
            color: white;
        }
        
        .btn-delete {
            background: #f44336;
            color: white;
        }
        
        .no-notifications {
            text-align: center;
            padding: 50px;
            color: #666;
            font-style: italic;
        }
        
        .notification-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 10px;
        }
        
        .badge-urgent {
            background: #f44336;
            color: white;
        }
        
        .badge-high {
            background: #ff9800;
            color: white;
        }
        
        .badge-normal {
            background: #4CAF50;
            color: white;
        }
        
        .badge-low {
            background: #9e9e9e;
            color: white;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-logo">
                <img src="../assets/mmuShuttleLogo2.png" alt="Logo" class="logo-icon">
                <span class="logo-text">Campus Shuttle Notifications</span>
            </div>
            <div class="admin-profile">
                <button class="logout-btn" onclick="window.location.href='manageRoutePage.php'">
                    Back to Routes
                </button>
            </div>
        </div>
    </nav>
    
    <div class="notifications-container">
        <h1>Notifications</h1>
        
        <?php if (empty($notifications)): ?>
            <div class="no-notifications">
                <i class="fas fa-bell-slash fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                <p>No notifications found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-card <?php echo $notification['Status'] == 'Unread' ? 'unread' : ''; ?>">
                    <div class="notification-header">
                        <div>
                            <span class="notification-badge badge-<?php echo strtolower($notification['Priority']); ?>">
                                <?php echo $notification['Priority']; ?>
                            </span>
                            <h3 class="notification-title"><?php echo htmlspecialchars($notification['Title']); ?></h3>
                        </div>
                        <div class="notification-time">
                            <?php 
                            $created = new DateTime($notification['Created_At']);
                            echo $created->format('M d, Y H:i');
                            ?>
                        </div>
                    </div>
                    
                    <div class="notification-message">
                        <?php echo nl2br(htmlspecialchars($notification['Message'])); ?>
                    </div>
                    
                    <div class="notification-actions">
                        <?php if ($notification['Status'] == 'Unread'): ?>
                            <a href="?mark_as_read=<?php echo $notification['Notification_ID']; ?>" 
                               class="btn-small btn-mark-read">
                                <i class="fas fa-check"></i> Mark as Read
                            </a>
                        <?php endif; ?>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['Notification_ID']; ?>">
                            <button type="submit" name="delete_notification" class="btn-small btn-delete">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>