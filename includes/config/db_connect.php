<?php
// Database configuration
$db_host = '127.0.0.1';
$db_user = 'fmsapp';
$db_pass = 'RTCZ2025$';
$db_name = 'fuel';

// Create connection
$conn = new mysqli('127.0.0.1', 'fmsapp', 'RTCZ2025$', 'fuel', 3306);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// Define constants for compatibility
if (!defined('DB_HOST')) define('DB_HOST', $db_host);
if (!defined('DB_USER')) define('DB_USER', $db_user);
if (!defined('DB_PASS')) define('DB_PASS', $db_pass);
if (!defined('DB_NAME')) define('DB_NAME', $db_name);
?>