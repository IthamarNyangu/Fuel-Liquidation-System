<?php
session_start();
require_once 'db_connect.php';

$errors = [];
$success = '';

$_SESSION['error_message'] = 'Account self-registration is disabled. Contact the Fleet Manager to create an account for you.';
header("Location: index.php");
exit();

// Fetch active facilities for dropdown
$facilities = [];
try {
    $facilities_query = "SELECT id, facility_name, facility_code, location FROM facilities WHERE is_active = 1 ORDER BY facility_name";
    $facilities_result = $conn->query($facilities_query);
    if ($facilities_result) {
        $facilities = $facilities_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $errors[] = "Error loading facilities";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate inputs
    $username = trim($_POST['new-username']);
    $email = trim($_POST['email']);
    $password = $_POST['new-password'];
    $facility_id = !empty($_POST['facility_id']) ? intval($_POST['facility_id']) : null;
    
    // Validation
    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long";
    }
    
    // Email validation - must end with @righttocare-zambia.org
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } elseif (!preg_match('/@righttocare-zambia\.org$/i', $email)) {
        $errors[] = "Only @righttocare-zambia.org email addresses are allowed";
    }
    
    // Password validation
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    // Facility validation
    if ($facility_id === null) {
        $errors[] = "Please select a facility";
    } else {
        // Verify facility exists and is active
        $facility_check = $conn->prepare("SELECT id FROM facilities WHERE id = ? AND is_active = 1");
        $facility_check->bind_param("i", $facility_id);
        $facility_check->execute();
        $facility_result = $facility_check->get_result();
        
        if ($facility_result->num_rows == 0) {
            $errors[] = "Invalid facility selected";
        }
        $facility_check->close();
    }
    
    // Check if email already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Email already registered";
        }
        $stmt->close();
    }
    
    // Check if username already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Username already taken";
        }
        $stmt->close();
    }
    
    // If no errors, create account
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user (including password and facility)
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, facility_id) VALUES (?, ?, ?, 'staff', ?)");
        $stmt->bind_param("sssi", $username, $email, $hashed_password, $facility_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Account created successfully! Please login.";
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "Error creating account. Please try again.";
        }
        $stmt->close();
    }
    
    // Store errors in session and redirect back
    if (!empty($errors)) {
        $_SESSION['signup_errors'] = $errors;
        $_SESSION['old_username'] = $username;
        $_SESSION['old_email'] = $email;
        $_SESSION['old_facility_id'] = $facility_id;
        header("Location: index.php#signup");
        exit();
    }
}

$conn->close();
?>
