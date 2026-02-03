<?php
session_start();
// Define base path
define('BASE_URL', '/Campus-Shuttle-Scheduling-and-Tracking-System-/');

// Redirect if already logged in as admin
if(isset($_SESSION['user_id']) && $_SESSION['role'] == 'Admin') {
    header('Location: ' . BASE_URL . 'campus-shuttle-admin-html/adminDashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/css/admin/style.css">
    <style>
        .server-error {
            text-align: center;
            color: #F44336;
            margin-bottom: 15px;
            padding: 10px;
            background: #ffebee;
            border-radius: 5px;
            font-size: 14px;
        }
        .test-credentials {
            margin-top: 20px;
            padding: 15px;
            background: #ffebee;
            border-radius: 5px;
            font-size: 14px;
            text-align: center;
        }
        .back-btn {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .admin-badge {
            display: inline-block;
            background: #F44336;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 10px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="<?php echo BASE_URL; ?>assets/mmuShuttleLogo2.png" alt="MMU Shuttle Logo" class="logo">
                <h2>Login as Admin</h2>
                <div style="text-align: center; margin-top: 10px;">
                    <span class="admin-badge">SYSTEM ADMINISTRATOR</span>
                </div>
            </div>
            
            <?php if(isset($_GET['error'])): ?>
                <div class="server-error">
                    Invalid username or password!
                </div>
            <?php endif; ?>
            
            <form class="login-form" id="loginForm" action="<?php echo BASE_URL; ?>login_process.php" method="POST" novalidate>
                <input type="hidden" name="role" value="Admin">
                
                <div class="form-group">
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" required autocomplete="username">
                        <label for="username">Admin Username</label>
                        <span class="focus-border"></span>
                    </div>
                    <span class="error-message" id="usernameError"></span>
                </div>

                <div class="form-group">
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <label for="password">Password</label>
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Toggle password visibility">
                            <span class="eye-icon"></span>
                        </button>
                        <span class="focus-border"></span>
                    </div>
                    <span class="error-message" id="passwordError"></span>
                </div>

                <button type="submit" class="login-btn btn" id="submitBtn">
                    <span class="btn-text">Login to Admin Panel</span>
                    <span class="btn-loader"></span>
                </button>
            </form>

            <div class="test-credentials">
                <strong>Test Admin Account:</strong><br>
                Username: <strong>admin</strong><br>
                Password: <strong>password</strong>
            </div>
            
            <a href="<?php echo BASE_URL; ?>staff_selection.php" class="back-btn">← Back to Staff Selection</a>

            <div class="success-message" id="successMessage">
                <div class="success-icon">✓</div>
                <h3>Login Successful!</h3>
                <p>Redirecting to your dashboard...</p>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>shared/js/form-utils.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const successMessage = document.getElementById('successMessage');
        
        // Password toggle functionality
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        
        if (passwordToggle && passwordInput) {
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const eyeIcon = this.querySelector('.eye-icon');
                eyeIcon.classList.toggle('show-password');
            });
        }
        
        // Form validation
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            let isValid = true;
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const usernameError = document.getElementById('usernameError');
            const passwordError = document.getElementById('passwordError');
            
            // Reset errors
            usernameError.textContent = '';
            passwordError.textContent = '';
            
            // Validate username
            if (!username.value.trim()) {
                usernameError.textContent = 'Username is required';
                isValid = false;
            }
            
            // Validate password
            if (!password.value.trim()) {
                passwordError.textContent = 'Password is required';
                isValid = false;
            }
            
            if (isValid) {
                // Show loading state
                submitBtn.classList.add('loading');
                
                // Simulate processing delay
                setTimeout(() => {
                    // Show success message
                    successMessage.style.display = 'block';
                    
                    // Submit the form after showing success message
                    setTimeout(() => {
                        form.submit();
                    }, 1500);
                }, 1000);
            }
            
            return false;
        });
    });
    </script>
</body>
</html>