<?php
// Administrative authentication check for Fleet Manager and Provincial Admin pages.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../core/facility_auth.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // User is not logged in, redirect to login page
    $_SESSION['error_message'] = "Please login to access this page";
    header("Location: index.php");
    exit();
}

if (!(isFleetManager() || isProvincialAdmin())) {
    $_SESSION['error_message'] = "Access denied. This page is only accessible to Fleet Managers and Provincial Admins.";
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
