<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in as Admin
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = '';
$settings = [];
$categories = [];

// Load all system settings
function loadSettings() {
    global $conn, $settings, $categories;
    
    // Create system_settings table if it doesn't exist
    $table_sql = "CREATE TABLE IF NOT EXISTS system_settings (
        setting_id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type VARCHAR(50),
        category VARCHAR(50),
        description TEXT,
        options TEXT,
        is_required BOOLEAN DEFAULT FALSE,
        sort_order INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($table_sql);
    
    // Insert default settings if table is empty
    $check_sql = "SELECT COUNT(*) as count FROM system_settings";
    $result = $conn->query($check_sql);
    $row = $result->fetch_assoc();
    
    if ($row['count'] == 0) {
        insertDefaultSettings();
    }
    
    // Load all settings grouped by category
    $settings_sql = "SELECT * FROM system_settings ORDER BY category, sort_order, setting_key";
    $result = $conn->query($settings_sql);
    
    while ($row = $result->fetch_assoc()) {
        $category = $row['category'];
        if (!isset($settings[$category])) {
            $settings[$category] = [];
        }
        $settings[$category][] = $row;
        
        if (!in_array($category, $categories)) {
            $categories[] = $category;
        }
    }
}

// Insert default system settings
function insertDefaultSettings() {
    global $conn;
    
    $default_settings = [
        // General Settings
        ['system_name', 'Campus Shuttle System', 'text', 'General', 'Name of the system displayed to users', NULL, true, 1],
        ['system_email', 'shuttle@mmu.edu.my', 'email', 'General', 'Default system email address', NULL, true, 2],
        ['timezone', 'Asia/Kuala_Lumpur', 'select', 'General', 'System timezone', 'Asia/Kuala_Lumpur|UTC|America/New_York|Europe/London', true, 3],
        ['date_format', 'Y-m-d', 'select', 'General', 'Date display format', 'Y-m-d|d/m/Y|m/d/Y|d-M-Y', true, 4],
        ['time_format', 'H:i', 'select', 'General', 'Time display format', 'H:i|h:i A|h:i:s A', true, 5],
        ['maintenance_mode', '0', 'boolean', 'General', 'Enable maintenance mode (system unavailable)', NULL, false, 6],
        
        // Booking Settings
        ['max_bookings_per_student', '3', 'number', 'Booking', 'Maximum active bookings per student', NULL, true, 1],
        ['booking_timeout_minutes', '15', 'number', 'Booking', 'Minutes before unconfirmed booking expires', NULL, true, 2],
        ['cancellation_deadline_minutes', '30', 'number', 'Booking', 'Minutes before departure when cancellation is allowed', NULL, true, 3],
        ['seat_selection_enabled', '1', 'boolean', 'Booking', 'Allow students to select specific seats', NULL, false, 4],
        ['overbooking_allowed', '0', 'boolean', 'Booking', 'Allow overbooking (more than capacity)', NULL, false, 5],
        
        // Notification Settings
        ['email_notifications', '1', 'boolean', 'Notifications', 'Enable email notifications', NULL, false, 1],
        ['sms_notifications', '0', 'boolean', 'Notifications', 'Enable SMS notifications', NULL, false, 2],
        ['push_notifications', '1', 'boolean', 'Notifications', 'Enable push notifications', NULL, false, 3],
        ['notification_delay_minutes', '10', 'number', 'Notifications', 'Minutes before departure to send reminder', NULL, false, 4],
        ['arrival_notification_minutes', '5', 'number', 'Notifications', 'Minutes before arrival to send notification', NULL, false, 5],
        
        // Security Settings
        ['login_attempts', '5', 'number', 'Security', 'Maximum failed login attempts before lockout', NULL, true, 1],
        ['lockout_minutes', '15', 'number', 'Security', 'Minutes of account lockout after failed attempts', NULL, true, 2],
        ['session_timeout', '30', 'number', 'Security', 'Session timeout in minutes', NULL, true, 3],
        ['password_min_length', '8', 'number', 'Security', 'Minimum password length', NULL, true, 4],
        ['password_require_special', '1', 'boolean', 'Security', 'Require special characters in passwords', NULL, false, 5],
        
        // Shuttle Settings
        ['default_shuttle_capacity', '30', 'number', 'Shuttle', 'Default capacity for new shuttles', NULL, true, 1],
        ['gps_update_interval', '30', 'number', 'Shuttle', 'GPS location update interval (seconds)', NULL, true, 2],
        ['auto_complete_trips', '1', 'boolean', 'Shuttle', 'Automatically mark trips as completed after end time', NULL, false, 3],
        ['driver_checkin_required', '1', 'boolean', 'Shuttle', 'Require driver to check in before starting trip', NULL, false, 4],
        
        // Display Settings
        ['map_provider', 'google', 'select', 'Display', 'Map provider for tracking', 'google|openstreetmap|mapbox', true, 1],
        ['default_language', 'en', 'select', 'Display', 'Default system language', 'en|ms|zh', true, 2],
        ['theme_color', '#4CAF50', 'color', 'Display', 'Primary theme color', NULL, false, 3],
        ['results_per_page', '20', 'number', 'Display', 'Number of items per page in lists', NULL, true, 4],
        
        // API Settings
        ['google_maps_api_key', '', 'password', 'API', 'Google Maps API key', NULL, false, 1],
        ['sms_api_key', '', 'password', 'API', 'SMS Gateway API key', NULL, false, 2],
        ['email_smtp_host', 'smtp.gmail.com', 'text', 'API', 'SMTP host for emails', NULL, false, 3],
        ['email_smtp_port', '587', 'number', 'API', 'SMTP port for emails', NULL, false, 4],
    ];
    
    $stmt = $conn->prepare("INSERT INTO system_settings 
        (setting_key, setting_value, setting_type, category, description, options, is_required, sort_order) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($default_settings as $setting) {
        $stmt->bind_param("ssssssii", ...$setting);
        $stmt->execute();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_settings':
                $conn->begin_transaction();
                $success = true;
                
                try {
                    foreach ($_POST['settings'] as $key => $value) {
                        // Clean the value
                        $value = trim($value);
                        
                        // Update the setting
                        $update_sql = "UPDATE system_settings SET setting_value = ?, updated_at = NOW() 
                                      WHERE setting_key = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("ss", $value, $key);
                        
                        if (!$update_stmt->execute()) {
                            $success = false;
                            break;
                        }
                    }
                    
                    if ($success) {
                        $conn->commit();
                        $message = "Settings saved successfully!";
                        $message_type = "success";
                        
                        // Clear settings cache
                        $settings = [];
                        $categories = [];
                        loadSettings();
                    } else {
                        $conn->rollback();
                        $message = "Error saving settings. Please try again.";
                        $message_type = "error";
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error: " . $e->getMessage();
                    $message_type = "error";
                }
                break;
                
            case 'reset_defaults':
                if (isset($_POST['category'])) {
                    $category = $_POST['category'];
                    
                    // Get default values for this category
                    $defaults_sql = "SELECT setting_key, setting_value FROM system_settings 
                                    WHERE category = ? AND setting_value != ''";
                    $defaults_stmt = $conn->prepare($defaults_sql);
                    $defaults_stmt->bind_param("s", $category);
                    $defaults_stmt->execute();
                    $defaults_result = $defaults_stmt->get_result();
                    
                    $conn->begin_transaction();
                    $success = true;
                    
                    try {
                        while ($row = $defaults_result->fetch_assoc()) {
                            $update_sql = "UPDATE system_settings SET setting_value = ?, updated_at = NOW() 
                                          WHERE setting_key = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param("ss", $row['setting_value'], $row['setting_key']);
                            
                            if (!$update_stmt->execute()) {
                                $success = false;
                                break;
                            }
                        }
                        
                        if ($success) {
                            $conn->commit();
                            $message = "Settings for '$category' reset to defaults!";
                            $message_type = "success";
                            
                            // Reload settings
                            $settings = [];
                            $categories = [];
                            loadSettings();
                        } else {
                            $conn->rollback();
                            $message = "Error resetting settings.";
                            $message_type = "error";
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
        }
    }
}

// Load settings
loadSettings();
?>

<!DOCTYPE html>
<html>
<head>
    <title>System Settings - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
        <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .settings-container {
            padding: 30px;
            max-width: 1200px;
            margin: 80px auto 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .page-title {
            color: #333;
            font-size: 28px;
        }
        
        .back-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .tab-btn {
            padding: 10px 20px;
            background: #f8f9fa;
            border: none;
            border-radius: 5px 5px 0 0;
            cursor: pointer;
            font-weight: 500;
            color: #495057;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab-btn:hover {
            background: #e9ecef;
        }
        
        .tab-btn.active {
            background: #4CAF50;
            color: white;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .category-title {
            color: #333;
            font-size: 22px;
            margin: 0;
        }
        
        .reset-btn {
            background: #ffc107;
            color: #212529;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .reset-btn:hover {
            background: #e0a800;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
        }
        
        .setting-group {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .setting-label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-weight: 500;
            font-size: 15px;
        }
        
        .setting-description {
            display: block;
            margin-bottom: 12px;
            color: #666;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .setting-required {
            color: #dc3545;
            font-size: 12px;
            margin-left: 5px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        select.form-control {
            height: 42px;
        }
        
        input[type="color"].form-control {
            height: 42px;
            padding: 5px;
            width: 100px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-label {
            font-weight: 500;
            color: #333;
            cursor: pointer;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            justify-content: flex-end;
        }
        
        .btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background: #388E3C;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .system-status {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .status-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        
        .status-label {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .status-value {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .no-settings {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .setting-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .settings-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .settings-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .tab-btn {
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-logo">
                <img src="../assets/mmuShuttleLogo2.png" alt="Logo" class="logo-icon">
                <span class="logo-text">Campus Shuttle Admin</span>
            </div>
            <div class="admin-profile">
                <img src="../assets/mmuShuttleLogo2.png" alt="Admin" class="profile-pic">
                <div class="user-badge">
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </div>
                <div class="profile-menu">
                    <button class="logout-btn" onclick="window.location.href='../logout.php'">Logout</button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="settings-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">System Settings</h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- System Status -->
        <div class="system-status">
            <h3 style="margin-bottom: 15px; color: #333;">System Status</h3>
            <div class="status-grid">
                <div class="status-item">
                    <div class="status-label">System Status</div>
                    <div class="status-value">
                        <?php 
                        $maintenance = false;
                        foreach ($settings as $category_settings) {
                            foreach ($category_settings as $setting) {
                                if ($setting['setting_key'] == 'maintenance_mode' && $setting['setting_value'] == '1') {
                                    $maintenance = true;
                                    break 2;
                                }
                            }
                        }
                        echo $maintenance ? '<span style="color: #dc3545;">Maintenance Mode</span>' : '<span style="color: #28a745;">Operational</span>';
                        ?>
                    </div>
                </div>
                <div class="status-item">
                    <div class="status-label">Total Settings</div>
                    <div class="status-value">
                        <?php 
                        $total = 0;
                        foreach ($settings as $category_settings) {
                            $total += count($category_settings);
                        }
                        echo $total;
                        ?>
                    </div>
                </div>
                <div class="status-item">
                    <div class="status-label">Last Updated</div>
                    <div class="status-value">
                        <?php
                        $last_update = '-';
                        foreach ($settings as $category_settings) {
                            foreach ($category_settings as $setting) {
                                if (!empty($setting['updated_at']) && $setting['updated_at'] > $last_update) {
                                    $last_update = $setting['updated_at'];
                                }
                            }
                        }
                        echo date('Y-m-d H:i', strtotime($last_update));
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Settings Tabs -->
        <div class="settings-tabs" id="settingsTabs">
            <?php foreach ($categories as $index => $category): ?>
                <button class="tab-btn <?php echo $index == 0 ? 'active' : ''; ?>" 
                        onclick="switchTab('<?php echo strtolower(str_replace(' ', '-', $category)); ?>')"
                        data-category="<?php echo $category; ?>">
                    <?php 
                    $icons = [
                        'General' => 'fas fa-cog',
                        'Booking' => 'fas fa-calendar-check',
                        'Notifications' => 'fas fa-bell',
                        'Security' => 'fas fa-shield-alt',
                        'Shuttle' => 'fas fa-bus',
                        'Display' => 'fas fa-palette',
                        'API' => 'fas fa-code'
                    ];
                    $icon = $icons[$category] ?? 'fas fa-sliders-h';
                    ?>
                    <i class="<?php echo $icon; ?>"></i>
                    <?php echo htmlspecialchars($category); ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Settings Forms -->
        <form method="POST" action="" id="settingsForm">
            <input type="hidden" name="action" value="save_settings">
            
            <?php foreach ($categories as $index => $category): ?>
                <div id="tab-<?php echo strtolower(str_replace(' ', '-', $category)); ?>" 
                     class="tab-content <?php echo $index == 0 ? 'active' : ''; ?>">
                    
                    <div class="category-header">
                        <h3 class="category-title">
                            <?php echo htmlspecialchars($category); ?> Settings
                        </h3>
                        <button type="button" class="reset-btn" onclick="resetCategory('<?php echo $category; ?>')">
                            <i class="fas fa-undo"></i> Reset to Defaults
                        </button>
                    </div>
                    
                    <?php if (isset($settings[$category])): ?>
                        <div class="settings-grid">
                            <?php foreach ($settings[$category] as $setting): ?>
                                <div class="setting-group">
                                    <label class="setting-label">
                                        <?php echo ucfirst(str_replace('_', ' ', $setting['setting_key'])); ?>
                                        <?php if ($setting['is_required']): ?>
                                            <span class="setting-required">*</span>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if (!empty($setting['description'])): ?>
                                        <span class="setting-description">
                                            <?php echo htmlspecialchars($setting['description']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php switch ($setting['setting_type']): 
                                        case 'text': ?>
                                            <input type="text" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                   <?php echo $setting['is_required'] ? 'required' : ''; ?>>
                                            <?php break; ?>
                                        
                                        <?php case 'email': ?>
                                            <input type="email" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                   <?php echo $setting['is_required'] ? 'required' : ''; ?>>
                                            <?php break; ?>
                                        
                                        <?php case 'number': ?>
                                            <input type="number" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                   min="0"
                                                   <?php echo $setting['is_required'] ? 'required' : ''; ?>>
                                            <?php break; ?>
                                        
                                        <?php case 'password': ?>
                                            <input type="password" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                   placeholder="Enter API key..."
                                                   autocomplete="new-password">
                                            <div class="setting-info">Leave empty to keep current value</div>
                                            <?php break; ?>
                                        
                                        <?php case 'select': ?>
                                            <select name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                    class="form-control"
                                                    <?php echo $setting['is_required'] ? 'required' : ''; ?>>
                                                <?php 
                                                $options = explode('|', $setting['options']);
                                                foreach ($options as $option):
                                                    $selected = ($option == $setting['setting_value']) ? 'selected' : '';
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selected; ?>>
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php break; ?>
                                        
                                        <?php case 'boolean': ?>
                                            <div class="checkbox-wrapper">
                                                <input type="checkbox" 
                                                       name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                       value="1"
                                                       id="setting_<?php echo $setting['setting_key']; ?>"
                                                       <?php echo $setting['setting_value'] == '1' ? 'checked' : ''; ?>>
                                                <label for="setting_<?php echo $setting['setting_key']; ?>" class="checkbox-label">
                                                    <?php echo $setting['setting_value'] == '1' ? 'Enabled' : 'Disabled'; ?>
                                                </label>
                                            </div>
                                            <?php break; ?>
                                        
                                        <?php case 'color': ?>
                                            <input type="color" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                            <?php break; ?>
                                        
                                        <?php case 'textarea': ?>
                                            <textarea name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                      class="form-control"
                                                      rows="4"
                                                      <?php echo $setting['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                                            <?php break; ?>
                                        
                                        <?php default: ?>
                                            <input type="text" 
                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    <?php endswitch; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-settings">
                            <i class="fas fa-sliders-h fa-3x" style="color: #6c757d;"></i>
                            <p style="margin-top: 15px;">No settings found for this category.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='adminDashboard.php'">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> Save All Settings
                </button>
                <button type="button" class="btn btn-danger" onclick="resetAllSettings()">
                    <i class="fas fa-trash-restore"></i> Reset All to Defaults
                </button>
            </div>
        </form>
        
        <!-- Reset Category Form -->
        <form method="POST" action="" id="resetForm" style="display: none;">
            <input type="hidden" name="action" value="reset_defaults">
            <input type="hidden" name="category" id="resetCategory">
        </form>
    </div>
    
    <script>
        // Tab switching
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // Activate selected tab button
            document.querySelector(`.tab-btn[data-category="${tabId.replace(/-/g, ' ')}"]`).classList.add('active');
        }
        
        // Reset category to defaults
        function resetCategory(category) {
            if (confirm(`Are you sure you want to reset all ${category} settings to their default values?\n\nThis action cannot be undone.`)) {
                document.getElementById('resetCategory').value = category;
                document.getElementById('resetForm').submit();
            }
        }
        
        // Reset all settings to defaults
        function resetAllSettings() {
            if (confirm('Are you sure you want to reset ALL system settings to their default values?\n\nThis will affect all configuration categories and cannot be undone.')) {
                // This would need a separate endpoint or modification
                alert('Resetting all settings... This would connect to backend.');
                // In production, you'd make an AJAX call or submit a form
            }
        }
        
        // Initialize checkboxes to submit as 1/0
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('settingsForm');
            
            // Handle checkbox values
            form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                // Set hidden input for unchecked state
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = checkbox.name;
                hiddenInput.value = '0';
                checkbox.parentNode.insertBefore(hiddenInput, checkbox);
                
                // Update hidden input when checkbox changes
                checkbox.addEventListener('change', function() {
                    hiddenInput.value = this.checked ? '1' : '0';
                });
            });
            
            // Form validation
            form.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredFields = form.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = '#dc3545';
                        field.focus();
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields marked with *.');
                    return false;
                }
                
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;
                
                // Auto-hide success message after 5 seconds
                const alertMsg = document.querySelector('.alert');
                if (alertMsg) {
                    setTimeout(() => {
                        alertMsg.style.transition = 'opacity 0.5s';
                        alertMsg.style.opacity = '0';
                        setTimeout(() => {
                            alertMsg.style.display = 'none';
                        }, 500);
                    }, 5000);
                }
                
                return true;
            });
            
            // Color picker preview
            const colorPickers = form.querySelectorAll('input[type="color"]');
            colorPickers.forEach(picker => {
                const preview = document.createElement('div');
                preview.style.cssText = `
                    width: 30px;
                    height: 30px;
                    border-radius: 4px;
                    border: 1px solid #ddd;
                    display: inline-block;
                    margin-left: 10px;
                    vertical-align: middle;
                `;
                preview.style.backgroundColor = picker.value;
                picker.parentNode.appendChild(preview);
                
                picker.addEventListener('input', function() {
                    preview.style.backgroundColor = this.value;
                });
            });
            
            // Make first tab active on page load
            if (window.location.hash) {
                const tabId = window.location.hash.substring(1);
                switchTab(tabId);
            }
        });
    </script>
</body>
</html>