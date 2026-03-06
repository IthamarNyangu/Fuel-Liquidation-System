<?php
// Driver Authentication Check
// Include this file at the top of pages that drivers should access

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // User is not logged in, redirect to login page
    $_SESSION['error_message'] = "Please login to access this page";
    header("Location: index.php");
    exit();
}

// Check if user has driver or admin role (admins can access driver pages too)
$allowed_roles = ['staff', 'admin', 'approver'];
if (!in_array($_SESSION['user_role'], $allowed_roles)) {
    $_SESSION['error_message'] = "You don't have permission to access this page";
    header("Location: dashboard.php");
    exit();
}

// Optional: Check if session has expired (e.g., after 2 hours of inactivity)
$timeout_duration = 7200; // 2 hours in seconds

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // Session expired
    session_unset();
    session_destroy();
    $_SESSION['error_message'] = "Your session has expired. Please login again.";
    header("Location: index.php");
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();
?>