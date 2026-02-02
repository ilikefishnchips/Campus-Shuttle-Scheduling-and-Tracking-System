<?php
session_start();
require_once '../includes/config.php';

// 检查用户是否以交通协调员身份登录
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Transport Coordinator') {
    header('Location: ../coordinator_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// 获取可用数据
$routes = $conn->query("SELECT * FROM route WHERE Status = 'Active' ORDER BY Route_Name")->fetch_all(MYSQLI_ASSOC);
$vehicles = $conn->query("SELECT * FROM vehicle WHERE Status = 'Active' ORDER BY Plate_number")->fetch_all(MYSQLI_ASSOC);
$drivers = $conn->query("
    SELECT u.* FROM user u 
    JOIN user_roles ur ON u.User_ID = ur.User_ID 
    JOIN roles r ON ur.Role_ID = r.Role_ID 
    WHERE r.Role_name = 'Driver' 
    AND u.User_ID IN (SELECT User_ID FROM driver_profile)
    ORDER BY u.Full_Name
")->fetch_all(MYSQLI_ASSOC);

// 处理表单提交
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicle_id = $_POST['vehicle_id'];
    $route_id = $_POST['route_id'];
    $driver_id = $_POST['driver_id'];
    $departure_time = $_POST['departure_time'];
    $recurring = isset($_POST['recurring']) ? 1 : 0;
    $recurring_pattern = $_POST['recurring_pattern'] ?? '';
    $end_date = $_POST['end_date'] ?? null;
    
    // 验证输入
    if(empty($vehicle_id) || empty($route_id) || empty($driver_id) || empty($departure_time)) {
        $error = '请填写所有必填字段';
    } else {
        try {
            // 获取车辆容量
            $vehicle_capacity = $conn->query("SELECT Capacity FROM vehicle WHERE Vehicle_ID = $vehicle_id")->fetch_assoc()['Capacity'];
            
            // 检查时间冲突
            $conflict_check = $conn->query("
                SELECT COUNT(*) as count FROM shuttle_schedule 
                WHERE Vehicle_ID = $vehicle_id 
                AND Status IN ('Scheduled', 'In Progress')
                AND Departure_time BETWEEN DATE_SUB('$departure_time', INTERVAL 30 MINUTE) 
                AND DATE_ADD('$departure_time', INTERVAL 30 MINUTE)
            ")->fetch_assoc();
            
            if($conflict_check['count'] > 0) {
                $error = '该车辆在选定时间已有其他行程安排';
            } else {
                // 插入主日程
                $sql = "INSERT INTO shuttle_schedule (
                    Vehicle_ID, Route_ID, Driver_ID, Departure_time, 
                    Status, Available_Seats
                ) VALUES (?, ?, ?, ?, 'Scheduled', ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiisi", 
                    $vehicle_id, 
                    $route_id, 
                    $driver_id, 
                    $departure_time,
                    $vehicle_capacity
                );
                
                if($stmt->execute()) {
                    $schedule_id = $stmt->insert_id;
                    
                    // 如果是重复日程，创建后续日程
                    if($recurring && $end_date) {
                        $start_date = date('Y-m-d', strtotime($departure_time));
                        $current_date = date('Y-m-d', strtotime("$start_date +1 day"));
                        
                        switch($recurring_pattern) {
                            case 'daily':
                                $interval = '+1 day';
                                break;
                            case 'weekdays':
                                $interval = '+1 weekday';
                                break;
                            case 'weekly':
                                $interval = '+1 week';
                                break;
                            default:
                                $interval = '+1 day';
                        }
                        
                        while(strtotime($current_date) <= strtotime($end_date)) {
                            $next_departure = date('Y-m-d H:i:s', 
                                strtotime(date('H:i:s', strtotime($departure_time)) . " $current_date")
                            );
                            
                            $sql = "INSERT INTO shuttle_schedule (
                                Vehicle_ID, Route_ID, Driver_ID, Departure_time, 
                                Status, Available_Seats, Parent_Schedule_ID
                            ) VALUES (?, ?, ?, ?, 'Scheduled', ?, ?)";
                            
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("iiisii", 
                                $vehicle_id, 
                                $route_id, 
                                $driver_id, 
                                $next_departure,
                                $vehicle_capacity,
                                $schedule_id
                            );
                            $stmt->execute();
                            
                            // 更新下一个日期
                            $current_date = date('Y-m-d', strtotime("$current_date $interval"));
                        }
                    }
                    
                    $success = '日程创建成功！';
                    // 重置表单
                    $_POST = array();
                } else {
                    $error = '创建日程时出错：' . $conn->error;
                }
            }
        } catch(Exception $e) {
            $error = '错误：' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>创建班车日程 - 交通协调员</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #764ba2;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-icon {
            font-size: 28px;
        }
        
        .nav-controls {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .nav-btn {
            padding: 8px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .back-btn {
            background: #f8f9fa;
            color: #495057;
            border: 2px solid #e9ecef;
        }
        
        .back-btn:hover {
            background: #e9ecef;
            border-color: #dee2e6;
        }
        
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            cursor: pointer;
        }
        
        .logout-btn:hover {
            background: #c82333;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-title {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .page-title h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .page-title p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
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
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #495057;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #dc3545;
            margin-left: 3px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #764ba2;
            box-shadow: 0 0 0 3px rgba(118, 75, 162, 0.1);
        }
        
        .form-control.select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23495057' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 40px;
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .radio-label:hover {
            border-color: #764ba2;
            background: rgba(118, 75, 162, 0.05);
        }
        
        .radio-label input[type="radio"] {
            display: none;
        }
        
        .radio-label input[type="radio"]:checked + .radio-custom {
            background: #764ba2;
            border-color: #764ba2;
        }
        
        .radio-label input[type="radio"]:checked + .radio-custom::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
        }
        
        .radio-custom {
            width: 18px;
            height: 18px;
            border: 2px solid #adb5bd;
            border-radius: 50%;
            position: relative;
            transition: all 0.3s;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 10px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .checkbox-label:hover {
            background: rgba(118, 75, 162, 0.05);
        }
        
        .checkbox-label input[type="checkbox"] {
            display: none;
        }
        
        .checkbox-label input[type="checkbox"]:checked + .checkbox-custom {
            background: #764ba2;
            border-color: #764ba2;
        }
        
        .checkbox-label input[type="checkbox"]:checked + .checkbox-custom::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .checkbox-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #adb5bd;
            border-radius: 5px;
            position: relative;
            transition: all 0.3s;
        }
        
        .recurring-options {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 15px;
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .section-title {
            font-size: 20px;
            color: #495057;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .preview-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-top: 20px;
            display: none;
        }
        
        .preview-title {
            font-size: 18px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .preview-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .preview-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        
        .preview-label {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .preview-value {
            font-size: 16px;
            font-weight: 600;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }
        
        .btn {
            padding: 12px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            min-width: 120px;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(118, 75, 162, 0.3);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(118, 75, 162, 0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .info-tip {
            display: inline-block;
            background: #e7f3ff;
            color: #0066cc;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .form-container {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-controls {
                flex-direction: column;
                gap: 10px;
            }
            
            .page-title h1 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar">
        <div class="logo">
            <span class="logo-icon">🚌</span>
            <span>校园班车系统 - 协调员</span>
        </div>
        <div class="nav-controls">
            <a href="../coordinator/coordinator_dashboard.php" class="nav-btn back-btn">← 返回仪表板</a>
            <button onclick="logout()" class="nav-btn logout-btn">登出</button>
        </div>
    </nav>
    
    <!-- 主内容 -->
    <div class="dashboard-container">
        <div class="page-title">
            <h1>创建新的班车日程</h1>
            <p>为新班车行程安排车辆、路线和时间</p>
        </div>
        
        <div class="form-container">
            <?php if($success): ?>
                <div class="alert alert-success">
                    ✅ <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-error">
                    ❌ <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="scheduleForm">
                <div class="form-grid">
                    <!-- 左侧列 -->
                    <div>
                        <div class="section-title">基本设置</div>
                        
                        <div class="form-group">
                            <label>选择路线 <span class="required">*</span></label>
                            <select name="route_id" class="form-control select" required onchange="updatePreview()">
                                <option value="">-- 请选择路线 --</option>
                                <?php foreach($routes as $route): ?>
                                    <option value="<?php echo $route['Route_ID']; ?>" 
                                        <?php echo ($_POST['route_id'] ?? '') == $route['Route_ID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route['Route_Name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>选择车辆 <span class="required">*</span></label>
                            <select name="vehicle_id" class="form-control select" required onchange="updatePreview()">
                                <option value="">-- 请选择车辆 --</option>
                                <?php foreach($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['Vehicle_ID']; ?>"
                                        data-capacity="<?php echo $vehicle['Capacity']; ?>"
                                        data-model="<?php echo htmlspecialchars($vehicle['Model']); ?>"
                                        <?php echo ($_POST['vehicle_id'] ?? '') == $vehicle['Vehicle_ID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['Plate_number']) . ' - ' . 
                                               htmlspecialchars($vehicle['Model']) . ' (' . $vehicle['Capacity'] . '座)'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>分配司机 <span class="required">*</span></label>
                            <select name="driver_id" class="form-control select" required onchange="updatePreview()">
                                <option value="">-- 请选择司机 --</option>
                                <?php foreach($drivers as $driver): ?>
                                    <option value="<?php echo $driver['User_ID']; ?>"
                                        <?php echo ($_POST['driver_id'] ?? '') == $driver['User_ID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($driver['Full_Name']) . ' (' . $driver['Username'] . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- 右侧列 -->
                    <div>
                        <div class="section-title">时间设置</div>
                        
                        <div class="form-group">
                            <label>出发时间 <span class="required">*</span></label>
                            <input type="datetime-local" name="departure_time" 
                                   class="form-control" required
                                   value="<?php echo $_POST['departure_time'] ?? ''; ?>"
                                   min="<?php echo date('Y-m-d\TH:i'); ?>"
                                   onchange="updatePreview()">
                            <div class="info-tip">请选择未来的时间</div>
                        </div>
                        
                        <div class="form-group">
                            <label>重复模式</label>
                            <div class="checkbox-label">
                                <input type="checkbox" name="recurring" id="recurring" 
                                       onchange="toggleRecurringOptions()">
                                <span class="checkbox-custom"></span>
                                <span>设置为重复行程</span>
                            </div>
                        </div>
                        
                        <!-- 重复选项 -->
                        <div id="recurringOptions" class="recurring-options">
                            <div class="form-group">
                                <label>重复频率</label>
                                <div class="radio-group">
                                    <label class="radio-label">
                                        <input type="radio" name="recurring_pattern" value="daily" checked onchange="updatePreview()">
                                        <span class="radio-custom"></span>
                                        <span>每天</span>
                                    </label>
                                    <label class="radio-label">
                                        <input type="radio" name="recurring_pattern" value="weekdays" onchange="updatePreview()">
                                        <span class="radio-custom"></span>
                                        <span>工作日</span>
                                    </label>
                                    <label class="radio-label">
                                        <input type="radio" name="recurring_pattern" value="weekly" onchange="updatePreview()">
                                        <span class="radio-custom"></span>
                                        <span>每周</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>结束日期</label>
                                <input type="date" name="end_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       onchange="updatePreview()">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 预览 -->
                <div id="preview" class="preview-card">
                    <div class="preview-title">
                        <span>📋 行程预览</span>
                    </div>
                    <div class="preview-content" id="previewContent">
                        <!-- 预览内容将通过JavaScript动态生成 -->
                    </div>
                </div>
                
                <!-- 表单操作 -->
                <div class="form-actions">
                    <button type="reset" class="btn btn-reset" onclick="resetForm()">重置</button>
                    <button type="submit" class="btn btn-submit">创建日程</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // 切换重复选项显示
        function toggleRecurringOptions() {
            const recurringCheckbox = document.getElementById('recurring');
            const recurringOptions = document.getElementById('recurringOptions');
            
            if(recurringCheckbox.checked) {
                recurringOptions.style.display = 'block';
            } else {
                recurringOptions.style.display = 'none';
            }
            updatePreview();
        }
        
        // 更新预览
        function updatePreview() {
            const preview = document.getElementById('preview');
            const previewContent = document.getElementById('previewContent');
            
            // 获取表单值
            const routeSelect = document.querySelector('select[name="route_id"]');
            const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
            const driverSelect = document.querySelector('select[name="driver_id"]');
            const departureInput = document.querySelector('input[name="departure_time"]');
            const recurringCheckbox = document.getElementById('recurring');
            
            const routeText = routeSelect.selectedOptions[0]?.text || '未选择';
            const vehicleText = vehicleSelect.selectedOptions[0]?.text || '未选择';
            const driverText = driverSelect.selectedOptions[0]?.text || '未选择';
            const departureTime = departureInput.value || '未选择';
            const isRecurring = recurringCheckbox.checked;
            const recurringPattern = document.querySelector('input[name="recurring_pattern"]:checked')?.value || 'daily';
            const endDate = document.querySelector('input[name="end_date"]')?.value;
            
            // 如果有任何信息被填写，显示预览
            if(routeText !== '未选择' || vehicleText !== '未选择' || driverText !== '未选择' || departureTime !== '未选择') {
                preview.style.display = 'block';
                
                let previewHTML = `
                    <div class="preview-item">
                        <div class="preview-label">路线</div>
                        <div class="preview-value">${routeText}</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">车辆</div>
                        <div class="preview-value">${vehicleText}</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">司机</div>
                        <div class="preview-value">${driverText}</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">出发时间</div>
                        <div class="preview-value">${departureTime ? formatDateTime(departureTime) : '未选择'}</div>
                    </div>
                `;
                
                if(isRecurring) {
                    const patternMap = {
                        'daily': '每天',
                        'weekdays': '工作日',
                        'weekly': '每周'
                    };
                    previewHTML += `
                        <div class="preview-item">
                            <div class="preview-label">重复模式</div>
                            <div class="preview-value">${patternMap[recurringPattern] || '每天'}</div>
                        </div>
                        ${endDate ? `
                        <div class="preview-item">
                            <div class="preview-label">结束日期</div>
                            <div class="preview-value">${endDate}</div>
                        </div>
                        ` : ''}
                    `;
                }
                
                previewContent.innerHTML = previewHTML;
            } else {
                preview.style.display = 'none';
            }
        }
        
        // 格式化日期时间
        function formatDateTime(datetimeStr) {
            if(!datetimeStr) return '';
            const date = new Date(datetimeStr);
            return date.toLocaleString('zh-CN', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // 重置表单
        function resetForm() {
            document.getElementById('preview').style.display = 'none';
            setTimeout(() => {
                updatePreview();
            }, 100);
        }
        
        // 登出功能
        function logout() {
            if(confirm('确定要登出吗？')) {
                window.location.href = '../logout.php';
            }
        }
        
        // 页面加载时初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 设置最小时间（当前时间）
            const now = new Date();
            const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            document.querySelector('input[name="departure_time"]').min = localDateTime;
            
            // 设置默认结束日期为7天后
            const endDate = new Date();
            endDate.setDate(endDate.getDate() + 7);
            document.querySelector('input[name="end_date"]').valueAsDate = endDate;
            
            // 初始化预览
            updatePreview();
            
            // 表单提交验证
            document.getElementById('scheduleForm').addEventListener('submit', function(e) {
                const departureInput = document.querySelector('input[name="departure_time"]');
                const departureTime = new Date(departureInput.value);
                const now = new Date();
                
                if(departureTime <= now) {
                    alert('出发时间必须是未来的时间！');
                    e.preventDefault();
                    return false;
                }
                
                if(document.getElementById('recurring').checked) {
                    const endDateInput = document.querySelector('input[name="end_date"]');
                    if(!endDateInput.value) {
                        alert('请设置重复行程的结束日期！');
                        e.preventDefault();
                        return false;
                    }
                }
                
                // 显示加载状态
                const submitBtn = document.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '创建中...';
            });
        });
        
        // 实时更新预览
        document.querySelectorAll('select, input, textarea').forEach(element => {
            element.addEventListener('input', updatePreview);
            element.addEventListener('change', updatePreview);
        });
    </script>
</body>
</html>