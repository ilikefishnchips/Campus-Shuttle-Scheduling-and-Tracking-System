// Update your JavaScript to remove AJAX and work with PHP

class LoginForm1 {
    constructor() {
        this.form = document.getElementById('loginForm');
        this.submitBtn = this.form.querySelector('.login-btn');
        this.passwordToggle = document.getElementById('passwordToggle');
        this.passwordInput = document.getElementById('password');
        this.isSubmitting = false;
        
        // Update validators for PHP
        this.validators = {
            role: this.validateRole.bind(this),
            username: this.validateUsername.bind(this),
            password: this.validatePassword.bind(this)
        };
        
        this.init();
    }
    
    init() {
        this.addEventListeners();
        this.setupFloatingLabels();
        this.addInputAnimations();
        FormUtils.setupPasswordToggle(this.passwordInput, this.passwordToggle);
        FormUtils.addSharedAnimations();
    }
    
    setupFloatingLabels() {
        const inputs = this.form.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], textarea, select');

        inputs.forEach(input => {
            const wrapper = input.closest('.input-wrapper');

            // Check if input has value on load
            if (input.value.trim()) {
                if (wrapper) wrapper.classList.add('has-value');
                else input.classList.add('has-value');
            }

            // Add event listeners
            input.addEventListener('input', function() {
                const has = this.value.trim();
                const wrap = this.closest('.input-wrapper');
                if (has) {
                    if (wrap) wrap.classList.add('has-value');
                    else this.classList.add('has-value');
                } else {
                    if (wrap) wrap.classList.remove('has-value');
                    else this.classList.remove('has-value');
                }
            });
        });
    }
    
    addEventListeners() {
        // Real-time validation
        Object.keys(this.validators).forEach(fieldName => {
            const field = document.getElementById(fieldName);
            if (field) {
                field.addEventListener('blur', () => this.validateField(fieldName));
                field.addEventListener('input', () => this.clearError(fieldName));
            }
        });
        
        // Password toggle
        if(this.passwordToggle) {
            this.passwordToggle.addEventListener('click', (e) => {
                e.preventDefault();
                const isPassword = this.passwordInput.type === 'password';
                this.passwordInput.type = isPassword ? 'text' : 'password';
                const eyeIcon = this.passwordToggle.querySelector('.eye-icon');
                if (eyeIcon) {
                    eyeIcon.classList.toggle('show-password');
                }
            });
        }
    }
    
    // New validators for PHP
    validateRole(value) {
        if (!value) return { valid: false, message: 'Role is required' };
        return { valid: true };
    }
    
    validateUsername(value) {
        if (!value.trim()) return { valid: false, message: 'Username is required' };
        return { valid: true };
    }
    
    validatePassword(value) {
        if (!value) return { valid: false, message: 'Password is required' };
        if (value.length < 6) return { valid: false, message: 'Password must be at least 6 characters' };
        return { valid: true };
    }
    
    clearError(fieldName) {
        const errorElement = document.getElementById(fieldName + 'Error');
        if (errorElement) {
            errorElement.classList.remove('show');
            errorElement.textContent = '';
        }
    }
    
    validateField(fieldName) {
        const field = document.getElementById(fieldName);
        const validator = this.validators[fieldName];
        
        if (!field || !validator) return true;
        
        const result = validator(field.value);
        
        if (result.valid) {
            this.clearError(fieldName);
        } else {
            this.showError(fieldName, result.message);
        }
        
        return result.valid;
    }
    
    showError(fieldName, message) {
        const errorElement = document.getElementById(fieldName + 'Error');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.add('show');
        }
    }
    
    // Remove the AJAX submitForm method
    // Let the form submit normally to login.php
    
    shakeForm() {
        const card = document.querySelector('.login-card');
        card.style.animation = 'shake 0.5s ease-in-out';
        setTimeout(() => {
            card.style.animation = '';
        }, 500);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new LoginForm1();
});