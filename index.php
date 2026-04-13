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
$signup_active = $show_signup || !empty($signup_errors);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Fuel Management System</title>
  <link rel="stylesheet" href="styles.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/styles.css')); ?>" />
</head>
<body>
  <main class="auth-shell">
    <section class="auth-frame">
      <aside class="brand-panel" aria-hidden="true">
        <div class="brand-badge">Right to Care Zambia</div>
        <div class="brand-mark">
          <img src="assets/Logo-Full.svg" alt="Right to Care" />
        </div>
        <div class="brand-copy">
          <p class="brand-kicker">Fleet Operations</p>
          <h1>Fuel Management System</h1>
        </div>
      </aside>

      <section class="login-panel">
        <div class="login-panel-card">
          <div class="form-container">
        <!-- Success Message -->
        <?php if (!empty($success_message)): ?>
          <div class="alert alert-success" role="status">
            <?php echo htmlspecialchars($success_message); ?>
          </div>
        <?php endif; ?>
        
        <!-- Error Message -->
        <?php if (!empty($error_message)): ?>
          <div class="alert alert-error" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="login-form" class="form <?php echo !$signup_active ? 'active' : ''; ?>" action="login.php" method="POST">
          <?php if (!empty($login_errors)): ?>
            <div class="alert alert-error" role="alert">
              <ul>
                <?php foreach ($login_errors as $error): ?>
                  <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="login-header">
            <h2 class="system-name">Sign In</h2>
          </div>

          <div class="form-group">
            <label for="username">Username or Email</label>
            <input
              type="text"
              id="username"
              name="username"
              placeholder="Enter your username or email"
              value="<?php echo htmlspecialchars($old_username); ?>"
              autocomplete="username"
              required />
          </div>
          <div class="form-group">
            <label for="password">Password</label>
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Enter your password"
              autocomplete="current-password"
              required />
          </div>
          <div class="form-assist">
            Contact IT if you need help accessing your account.
          </div>
          <button type="submit" class="submit-btn">Sign In</button>
          <div class="create-account">
            Don't have an account? <button type="button" class="inline-link" id="signup-link">Create one</button>
          </div>
        </form>

        <!-- Signup Form -->
        <form id="signup-form" class="form signup-form <?php echo $signup_active ? 'active' : ''; ?>" action="signup.php" method="POST">
          <?php if (!empty($signup_errors)): ?>
            <div class="alert alert-error" role="alert">
              <ul>
                <?php foreach ($signup_errors as $error): ?>
                  <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="form-heading">
            <h3>Create Account</h3>
            <p>Use your Right to Care email and select your facility.</p>
          </div>
          <div class="signup-grid">
            <div class="form-group">
              <label for="new-username">Username</label>
              <input
                type="text"
                id="new-username"
                name="new-username"
                placeholder="Choose a username"
                value="<?php echo htmlspecialchars($old_username); ?>"
                autocomplete="username"
                required />
            </div>
            <div class="form-group">
              <label for="email">Work Email</label>
              <input
                type="email"
                id="email"
                name="email"
                placeholder="name@righttocare-zambia.org"
                value="<?php echo htmlspecialchars($old_email); ?>"
                autocomplete="email"
                required />
            </div>
            
            <div class="form-group">
              <label for="facility_id">Facility</label>
              <select id="facility_id" name="facility_id" required>
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
              <small class="field-hint">
                Select the facility where you work
              </small>
            </div>
            
            <div class="form-group">
              <label for="new-password">Password</label>
              <input
                type="password"
                id="new-password"
                name="new-password"
                placeholder="Minimum 6 characters"
                autocomplete="new-password"
                required />
            </div>
          </div>
          <button type="submit" class="submit-btn">Sign Up</button>
          <div class="create-account">
            Already have an account? <button type="button" class="inline-link" id="login-link">Sign in</button>
          </div>
        </form>
          </div>
        </div>
      </section>
    </section>
  </main>

  <script src="script.js"></script>
</body>
</html>
