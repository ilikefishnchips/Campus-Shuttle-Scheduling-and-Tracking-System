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
$users = [];
$user_details = null;
$edit_mode = false;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_user':
                // Create new user
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $email = trim($_POST['email']);
                $full_name = trim($_POST['full_name']);
                $role = $_POST['role'];
                
                // Check if username already exists
                $check_sql = "SELECT User_ID FROM user WHERE Username = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $username);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Username already exists!";
                    $message_type = "error";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Insert into user table
                        $user_sql = "INSERT INTO user (Username, Password, Email, Full_Name) VALUES (?, ?, ?, ?)";
                        $user_stmt = $conn->prepare($user_sql);
                        $user_stmt->bind_param("ssss", $username, $hashed_password, $email, $full_name);
                        $user_stmt->execute();
                        $user_id = $conn->insert_id;
                        
                        // Get role ID
                        $role_sql = "SELECT Role_ID FROM roles WHERE Role_name = ?";
                        $role_stmt = $conn->prepare($role_sql);
                        $role_stmt->bind_param("s", $role);
                        $role_stmt->execute();
                        $role_result = $role_stmt->get_result();
                        $role_data = $role_result->fetch_assoc();
                        $role_id = $role_data['Role_ID'];
                        
                        // Assign role
                        $user_role_sql = "INSERT INTO user_roles (User_ID, Role_ID) VALUES (?, ?)";
                        $user_role_stmt = $conn->prepare($user_role_sql);
                        $user_role_stmt->bind_param("ii", $user_id, $role_id);
                        $user_role_stmt->execute();
                        
                        // Create profile based on role
                        if ($role == 'Student' && isset($_POST['student_number'])) {
                            $student_sql = "INSERT INTO student_profile (User_ID, Student_Number, Phone, Emergency_contact, Faculty, Year_Of_Study) 
                                           VALUES (?, ?, ?, ?, ?, ?)";
                            $student_stmt = $conn->prepare($student_sql);
                            $student_stmt->bind_param("issssi", $user_id, $_POST['student_number'], $_POST['phone'], 
                                                     $_POST['emergency_contact'], $_POST['faculty'], $_POST['year_of_study']);
                            $student_stmt->execute();
                        } elseif ($role == 'Driver' && isset($_POST['license_number'])) {
                            $driver_sql = "INSERT INTO driver_profile (User_ID, License_Number, License_Expiry, Phone, Assigned_Vehicle) 
                                          VALUES (?, ?, ?, ?, ?)";
                            $driver_stmt = $conn->prepare($driver_sql);
                            $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
                            $assigned_vehicle = !empty($_POST['assigned_vehicle']) ? $_POST['assigned_vehicle'] : null;
                            $driver_stmt->bind_param("issss", $user_id, $_POST['license_number'], $license_expiry, 
                                                    $_POST['phone'], $assigned_vehicle);
                            $driver_stmt->execute();
                        }
                        
                        $conn->commit();
                        $message = "User created successfully!";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error creating user: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
                
            case 'edit_user':
                // Prepare user data for editing
                $user_id = $_POST['user_id'];
                $edit_mode = true;
                
                // Get user details
                $get_sql = "SELECT u.*, r.Role_name 
                           FROM user u 
                           JOIN user_roles ur ON u.User_ID = ur.User_ID 
                           JOIN roles r ON ur.Role_ID = r.Role_ID 
                           WHERE u.User_ID = ?";
                $get_stmt = $conn->prepare($get_sql);
                $get_stmt->bind_param("i", $user_id);
                $get_stmt->execute();
                $user_details = $get_stmt->get_result()->fetch_assoc();
                
                // Get profile details based on role
                if ($user_details['Role_name'] == 'Student') {
                    $profile_sql = "SELECT * FROM student_profile WHERE User_ID = ?";
                    $profile_stmt = $conn->prepare($profile_sql);
                    $profile_stmt->bind_param("i", $user_id);
                    $profile_stmt->execute();
                    $profile_result = $profile_stmt->get_result();
                    if ($profile_result->num_rows > 0) {
                        $user_details = array_merge($user_details, $profile_result->fetch_assoc());
                    }
                } elseif ($user_details['Role_name'] == 'Driver') {
                    $profile_sql = "SELECT * FROM driver_profile WHERE User_ID = ?";
                    $profile_stmt = $conn->prepare($profile_sql);
                    $profile_stmt->bind_param("i", $user_id);
                    $profile_stmt->execute();
                    $profile_result = $profile_stmt->get_result();
                    if ($profile_result->num_rows > 0) {
                        $user_details = array_merge($user_details, $profile_result->fetch_assoc());
                    }
                }
                break;
                
            case 'update_user':
                // Update existing user
                $user_id = $_POST['user_id'];
                $username = trim($_POST['username']);
                $email = trim($_POST['email']);
                $full_name = trim($_POST['full_name']);
                $role = $_POST['role'];
                
                // Check if username already exists (excluding current user)
                $check_sql = "SELECT User_ID FROM user WHERE Username = ? AND User_ID != ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("si", $username, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Username already exists!";
                    $message_type = "error";
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Update user table
                        $update_sql = "UPDATE user SET Username = ?, Email = ?, Full_Name = ? WHERE User_ID = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("sssi", $username, $email, $full_name, $user_id);
                        $update_stmt->execute();
                        
                        // Update password if provided
                        if (!empty($_POST['password'])) {
                            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            $pass_sql = "UPDATE user SET Password = ? WHERE User_ID = ?";
                            $pass_stmt = $conn->prepare($pass_sql);
                            $pass_stmt->bind_param("si", $hashed_password, $user_id);
                            $pass_stmt->execute();
                        }
                        
                        // Get current role
                        $current_role_sql = "SELECT r.Role_name FROM user_roles ur 
                                            JOIN roles r ON ur.Role_ID = r.Role_ID 
                                            WHERE ur.User_ID = ?";
                        $current_role_stmt = $conn->prepare($current_role_sql);
                        $current_role_stmt->bind_param("i", $user_id);
                        $current_role_stmt->execute();
                        $current_role_result = $current_role_stmt->get_result();
                        $current_role = $current_role_result->fetch_assoc()['Role_name'];
                        
                        // Update role if changed
                        if ($current_role != $role) {
                            // Remove old role
                            $delete_role_sql = "DELETE FROM user_roles WHERE User_ID = ?";
                            $delete_role_stmt = $conn->prepare($delete_role_sql);
                            $delete_role_stmt->bind_param("i", $user_id);
                            $delete_role_stmt->execute();
                            
                            // Add new role
                            $new_role_sql = "SELECT Role_ID FROM roles WHERE Role_name = ?";
                            $new_role_stmt = $conn->prepare($new_role_sql);
                            $new_role_stmt->bind_param("s", $role);
                            $new_role_stmt->execute();
                            $new_role_result = $new_role_stmt->get_result();
                            $new_role_data = $new_role_result->fetch_assoc();
                            $new_role_id = $new_role_data['Role_ID'];
                            
                            $insert_role_sql = "INSERT INTO user_roles (User_ID, Role_ID) VALUES (?, ?)";
                            $insert_role_stmt = $conn->prepare($insert_role_sql);
                            $insert_role_stmt->bind_param("ii", $user_id, $new_role_id);
                            $insert_role_stmt->execute();
                            
                            // Handle profile changes based on role
                            if ($current_role == 'Student' && $role != 'Student') {
                                // Remove student profile
                                $delete_profile_sql = "DELETE FROM student_profile WHERE User_ID = ?";
                                $delete_profile_stmt = $conn->prepare($delete_profile_sql);
                                $delete_profile_stmt->bind_param("i", $user_id);
                                $delete_profile_stmt->execute();
                            } elseif ($current_role == 'Driver' && $role != 'Driver') {
                                // Remove driver profile
                                $delete_profile_sql = "DELETE FROM driver_profile WHERE User_ID = ?";
                                $delete_profile_stmt = $conn->prepare($delete_profile_sql);
                                $delete_profile_stmt->bind_param("i", $user_id);
                                $delete_profile_stmt->execute();
                            }
                        }
                        
                        // Update or create profile based on role
                        if ($role == 'Student') {
                            // Check if profile exists
                            $check_profile_sql = "SELECT User_ID FROM student_profile WHERE User_ID = ?";
                            $check_profile_stmt = $conn->prepare($check_profile_sql);
                            $check_profile_stmt->bind_param("i", $user_id);
                            $check_profile_stmt->execute();
                            $check_profile_result = $check_profile_stmt->get_result();
                            
                            if ($check_profile_result->num_rows > 0) {
                                // Update existing profile
                                $update_profile_sql = "UPDATE student_profile SET 
                                                      Student_Number = ?, Phone = ?, Emergency_contact = ?, 
                                                      Faculty = ?, Year_Of_Study = ? 
                                                      WHERE User_ID = ?";
                                $update_profile_stmt = $conn->prepare($update_profile_sql);
                                $update_profile_stmt->bind_param("ssssii", $_POST['student_number'], $_POST['phone'], 
                                                                $_POST['emergency_contact'], $_POST['faculty'], 
                                                                $_POST['year_of_study'], $user_id);
                                $update_profile_stmt->execute();
                            } else {
                                // Create new profile
                                $insert_profile_sql = "INSERT INTO student_profile (User_ID, Student_Number, Phone, 
                                                      Emergency_contact, Faculty, Year_Of_Study) 
                                                      VALUES (?, ?, ?, ?, ?, ?)";
                                $insert_profile_stmt = $conn->prepare($insert_profile_sql);
                                $insert_profile_stmt->bind_param("issssi", $user_id, $_POST['student_number'], 
                                                                $_POST['phone'], $_POST['emergency_contact'], 
                                                                $_POST['faculty'], $_POST['year_of_study']);
                                $insert_profile_stmt->execute();
                            }
                        } elseif ($role == 'Driver') {
                            // Check if profile exists
                            $check_profile_sql = "SELECT User_ID FROM driver_profile WHERE User_ID = ?";
                            $check_profile_stmt = $conn->prepare($check_profile_sql);
                            $check_profile_stmt->bind_param("i", $user_id);
                            $check_profile_stmt->execute();
                            $check_profile_result = $check_profile_stmt->get_result();
                            
                            if ($check_profile_result->num_rows > 0) {
                                // Update existing profile
                                $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
                                $assigned_vehicle = !empty($_POST['assigned_vehicle']) ? $_POST['assigned_vehicle'] : null;
                                
                                $update_profile_sql = "UPDATE driver_profile SET 
                                                      License_Number = ?, License_Expiry = ?, Phone = ?, 
                                                      Assigned_Vehicle = ? 
                                                      WHERE User_ID = ?";
                                $update_profile_stmt = $conn->prepare($update_profile_sql);
                                $update_profile_stmt->bind_param("ssssi", $_POST['license_number'], $license_expiry, 
                                                                $_POST['phone'], $assigned_vehicle, $user_id);
                                $update_profile_stmt->execute();
                            } else {
                                // Create new profile
                                $license_expiry = !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null;
                                $assigned_vehicle = !empty($_POST['assigned_vehicle']) ? $_POST['assigned_vehicle'] : null;
                                
                                $insert_profile_sql = "INSERT INTO driver_profile (User_ID, License_Number, 
                                                      License_Expiry, Phone, Assigned_Vehicle) 
                                                      VALUES (?, ?, ?, ?, ?)";
                                $insert_profile_stmt = $conn->prepare($insert_profile_sql);
                                $insert_profile_stmt->bind_param("issss", $user_id, $_POST['license_number'], 
                                                                $license_expiry, $_POST['phone'], $assigned_vehicle);
                                $insert_profile_stmt->execute();
                            }
                        }
                        
                        $conn->commit();
                        $message = "User updated successfully!";
                        $message_type = "success";
                        $edit_mode = false;
                        $user_details = null;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error updating user: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
                break;
                
            case 'delete_user':
                // Delete user
                $user_id = $_POST['user_id'];
                
                try {
                    $delete_sql = "DELETE FROM user WHERE User_ID = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $user_id);
                    $delete_stmt->execute();
                    
                    $message = "User deleted successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error deleting user: " . $e->getMessage();
                    $message_type = "error";
                }
                break;
        }
    }
}

// Get all users with their roles
$users_sql = "SELECT u.User_ID, u.Username, u.Email, u.Full_Name, u.Created_At, 
              GROUP_CONCAT(r.Role_name SEPARATOR ', ') as Roles
              FROM user u
              LEFT JOIN user_roles ur ON u.User_ID = ur.User_ID
              LEFT JOIN roles r ON ur.Role_ID = r.Role_ID
              GROUP BY u.User_ID
              ORDER BY u.Created_At DESC";
$users_result = $conn->query($users_sql);
if ($users_result) {
    $users = $users_result->fetch_all(MYSQLI_ASSOC);
}

// Get all roles for dropdown
$roles_sql = "SELECT * FROM roles ORDER BY Role_name";
$roles_result = $conn->query($roles_sql);
$roles = $roles_result->fetch_all(MYSQLI_ASSOC);

// Get all vehicles for driver assignment
$vehicles_sql = "SELECT Vehicle_ID, Plate_number, Model, Capacity FROM vehicle WHERE Status = 'Active'";
$vehicles_result = $conn->query($vehicles_sql);
$vehicles = $vehicles_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <style>
        .manage-users-container {
            padding: 30px;
            max-width: 1400px;
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
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #F44336;
            box-shadow: 0 0 0 3px rgba(244, 67, 54, 0.1);
        }
        
        .btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #d32f2f;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .users-table th,
        .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .users-table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .users-table tr:hover {
            background: #f8f9fa;
        }
        
        .user-actions {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        
        .edit-btn {
            background: #ffc107;
            color: #212529;
        }
        
        .edit-btn:hover {
            background: #e0a800;
        }
        
        .delete-btn {
            background: #dc3545;
            color: white;
        }
        
        .delete-btn:hover {
            background: #c82333;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin: 2px;
        }
        
        .role-student {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .role-driver {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .role-coordinator {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .role-admin {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .profile-fields {
            display: none;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-top: 20px;
            border: 1px solid #e9ecef;
        }
        
        .profile-fields.active {
            display: block;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }
        
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .manage-users-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .users-table th,
            .users-table td {
                padding: 10px 5px;
            }
            
            .user-actions {
                flex-direction: column;
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
    <div class="manage-users-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><?php echo $edit_mode ? 'Edit User' : 'User Management'; ?></h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- User Form Section -->
        <div class="section">
            <div class="form-header">
                <h2 class="section-title"><?php echo $edit_mode ? 'Edit User' : 'Create New User'; ?></h2>
                <?php if ($edit_mode): ?>
                    <button class="btn btn-secondary" onclick="cancelEdit()">Cancel Edit</button>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="" id="userForm">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?php echo $user_details['User_ID']; ?>">
                <?php else: ?>
                    <input type="hidden" name="action" value="create_user">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="username">Username *</label>
                        <input type="text" id="username" name="username" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($user_details['Username']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password <?php echo $edit_mode ? '(leave blank to keep unchanged)' : '*'; ?></label>
                        <input type="password" id="password" name="password" class="form-control" 
                               <?php echo $edit_mode ? '' : 'required'; ?>>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="email">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($user_details['Email']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?php echo $edit_mode ? htmlspecialchars($user_details['Full_Name']) : ''; ?>" 
                               required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="role">Role *</label>
                    <select id="role" name="role" class="form-control" required onchange="toggleProfileFields()">
                        <option value="">Select Role</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role['Role_name']); ?>"
                                <?php if ($edit_mode && $user_details['Role_name'] == $role['Role_name']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($role['Role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Student Profile Fields -->
                <div id="studentFields" class="profile-fields">
                    <h3 style="margin-bottom: 15px; color: #1976d2;">Student Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="student_number">Student Number *</label>
                            <input type="text" id="student_number" name="student_number" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['Student_Number']) ? htmlspecialchars($user_details['Student_Number']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['Phone']) ? htmlspecialchars($user_details['Phone']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="emergency_contact">Emergency Contact *</label>
                            <input type="text" id="emergency_contact" name="emergency_contact" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['Emergency_contact']) ? htmlspecialchars($user_details['Emergency_contact']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="faculty">Faculty</label>
                            <input type="text" id="faculty" name="faculty" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['Faculty']) ? htmlspecialchars($user_details['Faculty']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="year_of_study">Year of Study</label>
                            <select id="year_of_study" name="year_of_study" class="form-control">
                                <option value="">Select Year</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>"
                                        <?php if ($edit_mode && isset($user_details['Year_Of_Study']) && $user_details['Year_Of_Study'] == $i) echo 'selected'; ?>>
                                        Year <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Driver Profile Fields -->
                <div id="driverFields" class="profile-fields">
                    <h3 style="margin-bottom: 15px; color: #388e3c;">Driver Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="license_number">License Number *</label>
                            <input type="text" id="license_number" name="license_number" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['License_Number']) ? htmlspecialchars($user_details['License_Number']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="license_expiry">License Expiry Date</label>
                            <input type="date" id="license_expiry" name="license_expiry" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['License_Expiry']) ? htmlspecialchars($user_details['License_Expiry']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   value="<?php echo $edit_mode && isset($user_details['Phone']) ? htmlspecialchars($user_details['Phone']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="assigned_vehicle">Assigned Vehicle</label>
                        <select id="assigned_vehicle" name="assigned_vehicle" class="form-control">
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo htmlspecialchars($vehicle['Plate_number']); ?>"
                                    <?php if ($edit_mode && isset($user_details['Assigned_Vehicle']) && $user_details['Assigned_Vehicle'] == $vehicle['Plate_number']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($vehicle['Plate_number']); ?> (<?php echo htmlspecialchars($vehicle['Model']); ?>, Capacity: <?php echo $vehicle['Capacity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn">
                        <?php echo $edit_mode ? 'Update User' : 'Create User'; ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Users List Section -->
        <div class="section">
            <h2 class="section-title">Existing Users</h2>
            
            <?php if (empty($users)): ?>
                <div class="no-data">No users found in the system.</div>
            <?php else: ?>
                <div class="table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roles</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['User_ID']); ?></td>
                                    <td><?php echo htmlspecialchars($user['Username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['Full_Name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['Email']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($user['Roles'])) {
                                            $role_list = explode(', ', $user['Roles']);
                                            foreach ($role_list as $role) {
                                                $role_class = strtolower(str_replace(' ', '-', $role));
                                                echo '<span class="role-badge role-' . $role_class . '">' . htmlspecialchars($role) . '</span> ';
                                            }
                                        } else {
                                            echo '<span style="color: #6c757d; font-style: italic;">No roles assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($user['Created_At'])); ?></td>
                                    <td>
                                        <div class="user-actions">
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="edit_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['User_ID']; ?>">
                                                <button type="submit" class="action-btn edit-btn">Edit</button>
                                            </form>
                                            
                                            <form method="POST" action="" style="display: inline;" 
                                                  onsubmit="return confirmDelete(<?php echo $user['User_ID']; ?>, '<?php echo htmlspecialchars(addslashes($user['Username'])); ?>')">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['User_ID']; ?>">
                                                <button type="submit" class="action-btn delete-btn">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle profile fields based on selected role
        function toggleProfileFields() {
            const roleSelect = document.getElementById('role');
            const studentFields = document.getElementById('studentFields');
            const driverFields = document.getElementById('driverFields');
            const selectedRole = roleSelect.value;
            
            // Hide all profile fields first
            studentFields.classList.remove('active');
            driverFields.classList.remove('active');
            
            // Show relevant fields based on role
            if (selectedRole === 'Student') {
                studentFields.classList.add('active');
            } else if (selectedRole === 'Driver') {
                driverFields.classList.add('active');
            }
            
            // Make fields required based on role
            const studentRequired = ['student_number', 'emergency_contact'];
            const driverRequired = ['license_number'];
            
            if (selectedRole === 'Student') {
                studentRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = true;
                });
                driverRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
            } else if (selectedRole === 'Driver') {
                studentRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
                driverRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = true;
                });
            } else {
                // Clear requirements for non-profile roles
                studentRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
                driverRequired.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
            }
        }
        
        // Cancel edit mode
        function cancelEdit() {
            window.location.href = 'manage_users.php';
        }
        
        // Confirm delete action
        function confirmDelete(userId, username) {
            return confirm(`Are you sure you want to delete user "${username}" (ID: ${userId})?\n\nThis action cannot be undone and will delete all associated data.`);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Show/hide profile fields based on current role (for edit mode)
            <?php if ($edit_mode): ?>
                toggleProfileFields();
            <?php endif; ?>
            
            // Form validation
            const form = document.getElementById('userForm');
            form.addEventListener('submit', function(e) {
                const role = document.getElementById('role').value;
                
                if (!role) {
                    e.preventDefault();
                    alert('Please select a role.');
                    return false;
                }
                
                // Validate student fields if student role is selected
                if (role === 'Student') {
                    const studentNumber = document.getElementById('student_number').value;
                    const emergencyContact = document.getElementById('emergency_contact').value;
                    
                    if (!studentNumber || !emergencyContact) {
                        e.preventDefault();
                        alert('Please fill in all required student fields.');
                        return false;
                    }
                }
                
                // Validate driver fields if driver role is selected
                if (role === 'Driver') {
                    const licenseNumber = document.getElementById('license_number').value;
                    
                    if (!licenseNumber) {
                        e.preventDefault();
                        alert('Please fill in all required driver fields.');
                        return false;
                    }
                }
                
                return true;
            });
            
            // Auto-hide messages after 5 seconds
            const alert = document.querySelector('.alert');
            if (alert) {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            }
        });
        
        // Function to view user details (could be expanded for modal view)
        function viewUserDetails(userId) {
            // Could implement a modal or separate page to show detailed user information
            console.log('View details for user ID:', userId);
        }
    </script>
</body>
</html>