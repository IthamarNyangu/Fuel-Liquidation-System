<?php
session_start();
require_once __DIR__ . '/../../db_connect.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize inputs
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // Validation
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    // If no validation errors, check credentials
    if (empty($errors)) {
        // Query to get user by username or email with facility info
        $stmt = $conn->prepare("SELECT id, name, email, password, role, facility_id, is_super_admin, is_facility_admin, user_status FROM users WHERE name = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            if (($user['user_status'] ?? 'active') !== 'active') {
                $errors[] = "This account is inactive. Contact an administrator.";
            }
            
            // Verify password
            if (empty($errors) && password_verify($password, $user['password'])) {
                // Password is correct, create session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['facility_id'] = $user['facility_id'];
                $_SESSION['province_id'] = $user['facility_id'];
                $_SESSION['is_super_admin'] = $user['is_super_admin'];
                $_SESSION['is_facility_admin'] = $user['is_facility_admin'];
                $_SESSION['logged_in'] = true;
                $_SESSION['last_activity'] = time();
                
                $normalizedRole = strtolower((string) ($user['role'] ?? ''));
                $redirectTarget = $normalizedRole === 'driver' ? 'my_vehicle.php' : 'dashboard.php';

                header("Location: " . $redirectTarget);
                exit();
            } elseif (empty($errors)) {
                $errors[] = "Invalid username or password";
            }
        } else {
            $errors[] = "Invalid username or password";
        }
        $stmt->close();
    }
    
    // Store errors in session and redirect back
    if (!empty($errors)) {
        $_SESSION['login_errors'] = $errors;
        $_SESSION['old_username'] = $username;
        header("Location: index.php");
        exit();
    }
}

$conn->close();
?>
