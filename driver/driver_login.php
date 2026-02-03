<?php
session_start();
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = trim($_POST['driver_id']);
    $password  = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT u.User_ID, u.Password, u.Username
        FROM user u
        JOIN user_roles ur ON u.User_ID = ur.User_ID
        JOIN roles r ON ur.Role_ID = r.Role_ID
        WHERE u.Username = ? AND r.Role_Name = 'Driver'
    ");
    $stmt->bind_param("s", $driver_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['Password'])) {
        $_SESSION['user_id'] = $user['User_ID'];
        $_SESSION['username'] = $user['Username'];
        $_SESSION['role'] = 'Driver';

        header("Location: driver_dashboard.php");
        exit();
    } else {
        $error = "Invalid credentials";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Driver Login</title>
</head>
<body>
<h2>Driver Login</h2>
<form method="POST">
    <input type="text" name="driver_id" placeholder="Driver ID" required>
    <input type="password" name="password" placeholder="Password" required>
    <button>Login</button>
</form>
<?php if (!empty($error)) echo "<p>$error</p>"; ?>
</body>
</html>
