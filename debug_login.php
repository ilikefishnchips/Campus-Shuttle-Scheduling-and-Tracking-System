<?php
// debug_password.php - Debug password issues
session_start();
$conn = new mysqli('localhost', 'root', '', 'campus_shuttle_test');

echo "<h2>Password Debug Tool</h2>";

// Check admin user
$sql = "SELECT u.*, r.Role_name FROM user u 
        JOIN user_roles ur ON u.User_ID = ur.User_ID
        JOIN roles r ON ur.Role_ID = r.Role_ID
        WHERE u.Username = 'admin'";
$result = $conn->query($sql);

if($result->num_rows > 0) {
    $admin = $result->fetch_assoc();
    
    echo "<h3>Admin User Details:</h3>";
    echo "ID: {$admin['User_ID']}<br>";
    echo "Username: {$admin['Username']}<br>";
    echo "Role: {$admin['Role_name']}<br>";
    echo "Password in DB: <code>" . htmlspecialchars($admin['Password']) . "</code><br>";
    echo "Password Length: " . strlen($admin['Password']) . " characters<br>";
    
    echo "<hr>";
    
    echo "<h3>Testing Password Verification:</h3>";
    
    // Test with 'password123'
    $test_password = 'password123';
    echo "Testing password: <strong>$test_password</strong><br>";
    
    if(password_verify($test_password, $admin['Password'])) {
        echo "✅ <strong>password_verify() SUCCESS!</strong> Password matches<br>";
    } else {
        echo "❌ <strong>password_verify() FAILED!</strong><br>";
        
        // Check if it's plain text
        if($admin['Password'] === $test_password) {
            echo "⚠️ Password is stored as PLAIN TEXT (not hashed)<br>";
            echo "We need to hash it!<br>";
            
            // Hash the password
            $hashed_password = password_hash($test_password, PASSWORD_DEFAULT);
            echo "New hash: <code>" . $hashed_password . "</code><br>";
            
            // Update the database
            $update_sql = "UPDATE user SET Password = ? WHERE Username = 'admin'";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("s", $hashed_password);
            
            if($stmt->execute()) {
                echo "✅ Password updated to hashed version!<br>";
            } else {
                echo "❌ Failed to update password<br>";
            }
        } else {
            echo "⚠️ Password doesn't match plain text either<br>";
            echo "Possible issues:<br>";
            echo "1. Password in DB is different hash<br>";
            echo "2. You're using wrong password<br>";
            echo "3. Hash algorithm mismatch<br>";
        }
    }
} else {
    echo "❌ Admin user not found! Run the SQL setup script first.<br>";
}

echo "<hr>";

// Fix all passwords function
echo "<h3>Quick Fix: Reset ALL Passwords</h3>";
echo "<form method='POST'>";
echo "<input type='hidden' name='reset' value='1'>";
echo "<button type='submit' style='padding:10px; background:red; color:white; border:none;'>Reset ALL Passwords to 'password123'</button>";
echo "</form>";

if(isset($_POST['reset'])) {
    echo "<h4>Resetting passwords...</h4>";
    
    $users = ['admin', 'student1', 'driver1', 'coordinator1'];
    $new_password = 'password123';
    
    foreach($users as $username) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql = "UPDATE user SET Password = ? WHERE Username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $hashed_password, $username);
        
        if($stmt->execute()) {
            echo "✅ $username password reset<br>";
        } else {
            echo "❌ Failed to reset $username<br>";
        }
    }
    
    echo "<strong>✅ All passwords reset! Try logging in again.</strong><br>";
}

$conn->close();
?>