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
$search_query = '';
$role_filter = '';

// Handle form actions for role assignment/removal
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_role_to_user':
                // Assign role to user
                $user_id = $_POST['user_id'];
                $role_id = $_POST['role_id'];
                
                // Check if already assigned
                $check_sql = "SELECT * FROM user_roles WHERE User_ID = ? AND Role_ID = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $user_id, $role_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = "Role already assigned to this user!";
                    $message_type = "warning";
                } else {
                    // Assign role
                    $assign_sql = "INSERT INTO user_roles (User_ID, Role_ID) VALUES (?, ?)";
                    $assign_stmt = $conn->prepare($assign_sql);
                    $assign_stmt->bind_param("ii", $user_id, $role_id);
                    
                    if ($assign_stmt->execute()) {
                        $message = "Role assigned successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error assigning role: " . $conn->error;
                        $message_type = "error";
                    }
                }
                break;
                
            case 'remove_role_from_user':
                // Remove role from user
                $user_id = $_POST['user_id'];
                $role_id = $_POST['role_id'];
                
                // Check if this is the only role for the user
                $check_count_sql = "SELECT COUNT(*) as role_count FROM user_roles WHERE User_ID = ?";
                $check_count_stmt = $conn->prepare($check_count_sql);
                $check_count_stmt->bind_param("i", $user_id);
                $check_count_stmt->execute();
                $check_count_result = $check_count_stmt->get_result()->fetch_assoc();
                
                if ($check_count_result['role_count'] <= 1) {
                    $message = "Cannot remove the only role from a user!";
                    $message_type = "error";
                } else {
                    // Remove role
                    $remove_sql = "DELETE FROM user_roles WHERE User_ID = ? AND Role_ID = ?";
                    $remove_stmt = $conn->prepare($remove_sql);
                    $remove_stmt->bind_param("ii", $user_id, $role_id);
                    
                    if ($remove_stmt->execute()) {
                        $message = "Role removed successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error removing role: " . $conn->error;
                        $message_type = "error";
                    }
                }
                break;
        }
    }
}

// Handle search
if (isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
}

if (isset($_GET['role_filter'])) {
    $role_filter = $_GET['role_filter'];
}

// Get all users based on search query and role filter
$users_sql = "SELECT u.User_ID, u.Username, u.Email, u.Full_Name, u.Created_At,
              GROUP_CONCAT(r.Role_name SEPARATOR ', ') as roles_list
              FROM user u
              LEFT JOIN user_roles ur ON u.User_ID = ur.User_ID
              LEFT JOIN roles r ON ur.Role_ID = r.Role_ID";

// Add WHERE clause based on search and filter
$where_clauses = [];
$params = [];
$types = "";

if (!empty($search_query)) {
    $where_clauses[] = "(u.Username LIKE ? OR u.Email LIKE ? OR u.Full_Name LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

if (!empty($role_filter)) {
    $where_clauses[] = "r.Role_name = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if (count($where_clauses) > 0) {
    $users_sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$users_sql .= " GROUP BY u.User_ID ORDER BY u.Created_At DESC";

// Prepare and execute query
$users_stmt = $conn->prepare($users_sql);
if (!empty($params)) {
    $users_stmt->bind_param($types, ...$params);
}
$users_stmt->execute();
$users_result = $users_stmt->get_result();
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Get all roles for role filter dropdown and assignment
$roles_sql = "SELECT * FROM roles ORDER BY Role_name";
$roles_result = $conn->query($roles_sql);
$roles = $roles_result->fetch_all(MYSQLI_ASSOC);

// Get role assignments for each user
$role_assignments = [];
foreach ($users as $user) {
    $user_id = $user['User_ID'];
    $assignment_sql = "SELECT r.Role_ID, r.Role_name 
                       FROM user_roles ur
                       JOIN roles r ON ur.Role_ID = r.Role_ID
                       WHERE ur.User_ID = ?";
    $assignment_stmt = $conn->prepare($assignment_sql);
    $assignment_stmt->bind_param("i", $user_id);
    $assignment_stmt->execute();
    $assignment_result = $assignment_stmt->get_result();
    $role_assignments[$user_id] = $assignment_result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User List - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <style>
        .user-list-container {
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
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
        
        .search-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 250px;
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
        
        .form-actions {
            padding-top: 30px;
            display: flex;
            gap: 10px;
            margin-bottom: 80px;
        }
        
        .search-btn {
            background: #F44336;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            height: 40px;
            white-space: nowrap;
        }
        
        .search-btn:hover {
            background: #d32f2f;
        }
        
        .reset-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            height: 40px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            line-height: 1;
        }
        
        .reset-btn:hover {
            background: #5a6268;
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
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        
        .assign-btn {
            background: #28a745;
            color: white;
        }
        
        .assign-btn:hover {
            background: #218838;
        }
        
        .remove-btn {
            background: #6c757d;
            color: white;
        }
        
        .remove-btn:hover {
            background: #5a6268;
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
        
        .role-transport-coordinator {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .role-admin {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }
        
        .user-details {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .user-roles-section {
            margin-top: 10px;
        }
        
        .role-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            margin-bottom: 5px;
        }
        
        .role-assign-form {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            align-items: center;
        }
        
        .role-select {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 12px;
        }
        
        .search-info {
            margin-bottom: 20px;
            padding: 10px 15px;
            background: #e7f3ff;
            border-radius: 5px;
            font-size: 14px;
            color: #0c5460;
        }
        
        .search-info strong {
            color: #155724;
        }
        
        .results-count {
            margin: 15px 0;
            color: #6c757d;
            font-size: 14px;
            font-style: italic;
        }
        
        .user-email {
            color: #666;
            font-size: 12px;
            margin-top: 2px;
        }

        @media (max-width: 768px) {
            .user-list-container {
                padding: 15px;
                margin-top: 60px;
            }
            
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .form-actions {
                margin-top: 10px;
                width: 100%;
            }
            
            .form-actions .search-btn,
            .form-actions .reset-btn {
                flex: 1;
            }
            
            .users-table th,
            .users-table td {
                padding: 10px 5px;
                font-size: 12px;
            }
            
            .user-actions {
                flex-direction: column;
            }
            
            .role-assign-form {
                flex-direction: column;
                align-items: stretch;
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
    <div class="user-list-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">User Management</h1>
            <button class="back-btn" onclick="window.location.href='adminDashboard.php'">← Back to Dashboard</button>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Search Section -->
        <div class="search-section">
            <h2 class="section-title">Search Users</h2>
            <form method="GET" action="" class="search-form">
                <div class="form-group">
                    <label class="form-label" for="search">Search by Username, Email, or Name</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Enter search term...">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="role_filter">Filter by Role</label>
                    <select id="role_filter" name="role_filter" class="form-control">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role['Role_name']); ?>"
                                <?php if ($role_filter == $role['Role_name']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($role['Role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="search-btn">Search</button>
                    <a href="manage_users.php" class="reset-btn">Reset</a>
                </div>
            </form>
            
            <?php if (!empty($search_query) || !empty($role_filter)): ?>
                <div class="search-info">
                    <?php 
                    $search_info = [];
                    if (!empty($search_query)) {
                        $search_info[] = "Search: <strong>" . htmlspecialchars($search_query) . "</strong>";
                    }
                    if (!empty($role_filter)) {
                        $search_info[] = "Role: <strong>" . htmlspecialchars($role_filter) . "</strong>";
                    }
                    echo "Showing results for: " . implode(", ", $search_info);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Users List Section -->
        <div class="section">
            <h2 class="section-title">User List</h2>
            
            <?php if (empty($users)): ?>
                <div class="no-data">
                    <?php if (!empty($search_query) || !empty($role_filter)): ?>
                        No users found matching your search criteria.
                    <?php else: ?>
                        No users found in the system.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="results-count">
                    Found <?php echo count($users); ?> user(s)
                </div>
                
                <div class="table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roles</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['User_ID']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['Username']); ?></strong>
                                        <div class="user-email">
                                            <?php echo htmlspecialchars($user['Email']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['Full_Name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['Email']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($user['roles_list'])) {
                                            $role_list = explode(', ', $user['roles_list']);
                                            foreach ($role_list as $role) {
                                                $role_class = strtolower(str_replace(' ', '-', trim($role)));
                                                echo '<span class="role-badge role-' . $role_class . '">' . htmlspecialchars($role) . '</span> ';
                                            }
                                        } else {
                                            echo '<span style="color: #6c757d; font-style: italic;">No roles assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($user['Created_At'])); ?></td>
                                    <td>
                                        <div class="user-actions">
                                            <button type="button" class="action-btn assign-btn" 
                                                    onclick="toggleRoleAssignment(<?php echo $user['User_ID']; ?>)">
                                                Manage Roles
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Role Management Section (Hidden by default) -->
                                <tr id="role-management-<?php echo $user['User_ID']; ?>" style="display: none; background: #f8f9fa;">
                                    <td colspan="7">
                                        <div style="padding: 15px;">
                                            <h4 style="margin-bottom: 10px; color: #333;">Manage Roles for <?php echo htmlspecialchars($user['Username']); ?></h4>
                                            
                                            <!-- Current Roles -->
                                            <?php if (isset($role_assignments[$user['User_ID']]) && !empty($role_assignments[$user['User_ID']])): ?>
                                                <div class="user-roles-section">
                                                    <strong style="display: block; margin-bottom: 8px; color: #555;">Current Roles:</strong>
                                                    <?php foreach ($role_assignments[$user['User_ID']] as $assignment): ?>
                                                        <div class="role-item">
                                                            <span class="role-badge role-<?php echo strtolower(str_replace(' ', '-', $assignment['Role_name'])); ?>">
                                                                <?php echo htmlspecialchars($assignment['Role_name']); ?>
                                                            </span>
                                                            <form method="POST" action="" style="display: inline;" 
                                                                  onsubmit="return confirmRemoveRole('<?php echo htmlspecialchars(addslashes($assignment['Role_name'])); ?>', '<?php echo htmlspecialchars(addslashes($user['Username'])); ?>')">
                                                                <input type="hidden" name="action" value="remove_role_from_user">
                                                                <input type="hidden" name="user_id" value="<?php echo $user['User_ID']; ?>">
                                                                <input type="hidden" name="role_id" value="<?php echo $assignment['Role_ID']; ?>">
                                                                <button type="submit" class="action-btn remove-btn" 
                                                                        <?php echo count($role_assignments[$user['User_ID']]) <= 1 ? 'disabled title="Cannot remove the only role"' : ''; ?>>
                                                                    Remove
                                                                </button>
                                                            </form>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="color: #6c757d; font-style: italic; margin-bottom: 15px;">
                                                    No roles assigned to this user.
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Assign New Role -->
                                            <div class="role-assign-form">
                                                <select name="role_id" class="role-select" id="role-select-<?php echo $user['User_ID']; ?>">
                                                    <option value="">Select a role to assign</option>
                                                    <?php 
                                                    $available_roles = $roles;
                                                    if (isset($role_assignments[$user['User_ID']])) {
                                                        // Filter out roles already assigned
                                                        $assigned_role_ids = array_column($role_assignments[$user['User_ID']], 'Role_ID');
                                                        $available_roles = array_filter($roles, function($role) use ($assigned_role_ids) {
                                                            return !in_array($role['Role_ID'], $assigned_role_ids);
                                                        });
                                                    }
                                                    
                                                    if (empty($available_roles)): ?>
                                                        <option value="" disabled>All roles already assigned</option>
                                                    <?php else: ?>
                                                        <?php foreach ($available_roles as $role): ?>
                                                            <option value="<?php echo $role['Role_ID']; ?>">
                                                                <?php echo htmlspecialchars($role['Role_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                                
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="assign_role_to_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['User_ID']; ?>">
                                                    <input type="hidden" name="role_id" id="selected-role-<?php echo $user['User_ID']; ?>" value="">
                                                    <button type="submit" class="action-btn assign-btn" 
                                                            onclick="setSelectedRole(<?php echo $user['User_ID']; ?>)">
                                                        Assign Role
                                                    </button>
                                                </form>
                                            </div>
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
        // Toggle role assignment section
        function toggleRoleAssignment(userId) {
            const row = document.getElementById('role-management-' + userId);
            if (row.style.display === 'none') {
                row.style.display = 'table-row';
                // Close any other open role management sections
                document.querySelectorAll('[id^="role-management-"]').forEach(otherRow => {
                    if (otherRow.id !== 'role-management-' + userId && otherRow.style.display !== 'none') {
                        otherRow.style.display = 'none';
                    }
                });
            } else {
                row.style.display = 'none';
            }
        }
        
        // Set selected role value before form submission
        function setSelectedRole(userId) {
            const select = document.getElementById('role-select-' + userId);
            const hiddenInput = document.getElementById('selected-role-' + userId);
            if (select && hiddenInput) {
                hiddenInput.value = select.value;
                if (!hiddenInput.value) {
                    alert('Please select a role first!');
                    return false;
                }
                return true;
            }
            return false;
        }
        
        // Confirm remove role
        function confirmRemoveRole(roleName, username) {
            return confirm(`Are you sure you want to remove role "${roleName}" from user "${username}"?`);
        }
        
        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Initialize tooltips for disabled buttons
            document.querySelectorAll('button[disabled][title]').forEach(button => {
                button.addEventListener('mouseover', function() {
                    const title = this.getAttribute('title');
                    if (title) {
                        // Create and show tooltip
                        const tooltip = document.createElement('div');
                        tooltip.className = 'tooltip';
                        tooltip.textContent = title;
                        tooltip.style.cssText = `
                            position: absolute;
                            background: #333;
                            color: white;
                            padding: 5px 10px;
                            border-radius: 4px;
                            font-size: 12px;
                            z-index: 1000;
                            pointer-events: none;
                        `;
                        document.body.appendChild(tooltip);
                        
                        const rect = this.getBoundingClientRect();
                        tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
                        tooltip.style.left = (rect.left + rect.width/2 - tooltip.offsetWidth/2) + 'px';
                        
                        this.tooltip = tooltip;
                    }
                });
                
                button.addEventListener('mouseout', function() {
                    if (this.tooltip) {
                        this.tooltip.remove();
                        this.tooltip = null;
                    }
                });
            });
            
            // Close role management sections when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('[id^="role-management-"]') && 
                    !event.target.closest('.assign-btn')) {
                    document.querySelectorAll('[id^="role-management-"]').forEach(row => {
                        row.style.display = 'none';
                    });
                }
            });
        });
        
        // Search form validation
        const searchForm = document.querySelector('.search-form');
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                const searchInput = document.getElementById('search');
                const roleFilter = document.getElementById('role_filter');
                
                // If both search and filter are empty, prevent submission
                if (!searchInput.value.trim() && !roleFilter.value) {
                    e.preventDefault();
                    // Just refresh the page to show all users
                    window.location.href = 'manage_users.php';
                    return false;
                }
                
                return true;
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus on search input
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('search');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Escape to close all role management sections
            if (e.key === 'Escape') {
                document.querySelectorAll('[id^="role-management-"]').forEach(row => {
                    row.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>