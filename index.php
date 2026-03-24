<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

// Get error/success messages from session
$login_errors = isset($_SESSION['login_errors']) ? $_SESSION['login_errors'] : [];
$signup_errors = isset($_SESSION['signup_errors']) ? $_SESSION['signup_errors'] : [];
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';

// Get old input values
$old_username = isset($_SESSION['old_username']) ? $_SESSION['old_username'] : '';
$old_email = isset($_SESSION['old_email']) ? $_SESSION['old_email'] : '';
$old_facility_id = isset($_SESSION['old_facility_id']) ? $_SESSION['old_facility_id'] : '';

// Clear session messages
unset($_SESSION['login_errors']);
unset($_SESSION['signup_errors']);
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
unset($_SESSION['old_username']);
unset($_SESSION['old_email']);
unset($_SESSION['old_facility_id']);

// Fetch facilities for signup
require_once 'db_connect.php';
$facilities = [];
try {
    $facilities_query = "SELECT id, facility_name, facility_code FROM facilities WHERE is_active = 1 ORDER BY facility_name";
    $facilities_result = $conn->query($facilities_query);
    if ($facilities_result) {
        $facilities = $facilities_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    // Silent fail - form will show error if no facilities
}

// Check if we should show signup form (from URL hash)
$show_signup = isset($_GET['signup']) || strpos($_SERVER['HTTP_REFERER'] ?? '', '#signup') !== false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Fuel Management System</title>
  <link rel="stylesheet" href="styles.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/styles.css')); ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    .alert {
      padding: 12px 20px;
      margin-bottom: 20px;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    .alert ul {
      margin: 5px 0 0 20px;
      padding: 0;
    }
    
    .alert ul li {
      margin: 3px 0;
    }
    
    /* Form switching styles */
    .form-container {
      position: relative;
      min-height: 400px;
    }
    
    .form {
      display: none;
      width: 100%;
    }
    
    .form.active {
      display: block;
    }
    
    .create-account {
      margin-top: 20px;
      text-align: center;
      font-size: 14px;
      color: #666;
    }
    
    .create-account a {
      color: #dc2626;
      text-decoration: none;
      font-weight: 600;
    }
    
    .create-account a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="image-section">
      <img src="assets/Logo-Full.svg" alt="Fuel system branding" />
    </div>
    <div class="login-section">
      <div class="login-header">
        <div class="system-title">
          <i class="fas fa-gas-pump"></i>
          <h1 class="system-name">Fuel Management System</h1>
        </div>
        <h2 class="login-title">Welcome</h2>
      </div>

      <div class="form-container">
        <!-- Success Message -->
        <?php if (!empty($success_message)): ?>
          <div class="alert alert-success">
            <?php echo htmlspecialchars($success_message); ?>
          </div>
        <?php endif; ?>
        
        <!-- Error Message -->
        <?php if (!empty($error_message)): ?>
          <div class="alert alert-error">
            <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="login-form" class="form <?php echo !$show_signup && empty($signup_errors) ? 'active' : ''; ?>" action="login.php" method="POST">
          <?php if (!empty($login_errors)): ?>
            <div class="alert alert-error">
              <ul>
                <?php foreach ($login_errors as $error): ?>
                  <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          
          <div class="form-group">
            <input type="text" id="username" name="username" placeholder="Username or Email" 
                   value="<?php echo htmlspecialchars($old_username); ?>" required />
          </div>
          <div class="form-group">
            <input type="password" id="password" name="password" placeholder="Password" required />
          </div>
          <div class="forgot-password">
            <a href="#">Forgot your password?</a>
          </div>
          <button type="submit" class="submit-btn">Sign In</button>
          <div class="create-account">
            Don't have an account? <a href="#" id="signup-link">Sign Up</a>
          </div>
        </form>

        <!-- Signup Form -->
        <form id="signup-form" class="form <?php echo $show_signup || !empty($signup_errors) ? 'active' : ''; ?>" action="signup.php" method="POST">
          <?php if (!empty($signup_errors)): ?>
            <div class="alert alert-error">
              <ul>
                <?php foreach ($signup_errors as $error): ?>
                  <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          
          <div class="form-group">
            <input type="text" id="new-username" name="new-username" placeholder="Username" 
                   value="<?php echo htmlspecialchars($old_username); ?>" required />
          </div>
          <div class="form-group">
            <input type="email" id="email" name="email" placeholder="Email (@righttocare-zambia.org)" 
                   value="<?php echo htmlspecialchars($old_email); ?>" required />
            <small style="display: block; margin-top: 5px; color: #666; font-size: 12px;">
              Only @righttocare-zambia.org emails are allowed
            </small>
          </div>
          
          <div class="form-group">
            <select id="facility_id" name="facility_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
              <option value="">Select Your Facility *</option>
              <?php foreach ($facilities as $facility): ?>
                <option value="<?php echo $facility['id']; ?>" <?php echo ($old_facility_id == $facility['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($facility['facility_name']); ?>
                  <?php if ($facility['facility_code']): ?>
                    (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small style="display: block; margin-top: 5px; color: #666; font-size: 12px;">
              Select the facility where you work
            </small>
          </div>
          
          <div class="form-group">
            <input type="password" id="new-password" name="new-password" placeholder="Password (min. 6 characters)" required />
          </div>
          <button type="submit" class="submit-btn">Sign Up</button>
          <div class="create-account">
            Already have an account? <a href="#" id="login-link">Sign In</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="script.js"></script>
  <script>
    // Form switching functionality
    document.addEventListener('DOMContentLoaded', function() {
      const loginForm = document.getElementById('login-form');
      const signupForm = document.getElementById('signup-form');
      const signupLink = document.getElementById('signup-link');
      const loginLink = document.getElementById('login-link');
      
      // Switch to signup form
      if (signupLink) {
        signupLink.addEventListener('click', function(e) {
          e.preventDefault();
          loginForm.classList.remove('active');
          signupForm.classList.add('active');
          // Update URL hash
          window.location.hash = 'signup';
        });
      }
      
      // Switch to login form
      if (loginLink) {
        loginLink.addEventListener('click', function(e) {
          e.preventDefault();
          signupForm.classList.remove('active');
          loginForm.classList.add('active');
          // Clear URL hash
          window.location.hash = '';
        });
      }
      
      // Check URL hash on page load
      if (window.location.hash === '#signup') {
        loginForm.classList.remove('active');
        signupForm.classList.add('active');
      }
    });
  </script>
</body>
</html>
