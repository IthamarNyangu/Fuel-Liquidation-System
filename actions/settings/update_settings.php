<?php
session_start();
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../facility_auth.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

if (!canManageFuelPrices()) {
    $_SESSION['error_message'] = "You don't have permission to perform this action";
    header("Location: settings.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fuel_price = floatval($_POST['fuel_price']);
    $available_fuel = floatval($_POST['available_fuel']);
    
    // Validate inputs
    if ($fuel_price < 0 || $available_fuel < 0) {
        $_SESSION['error_message'] = "Values cannot be negative";
        header("Location: settings.php");
        exit();
    }
    
    // Check if settings exist
    $check_query = "SELECT id FROM settings LIMIT 1";
    $result = $conn->query($check_query);
    
    if ($result->num_rows > 0) {
        // Update existing settings
        $update_query = "UPDATE settings SET fuel_price = ?, available_fuel = ? WHERE id = 1";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("dd", $fuel_price, $available_fuel);
    } else {
        // Insert new settings
        $insert_query = "INSERT INTO settings (fuel_price, available_fuel) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("dd", $fuel_price, $available_fuel);
    }
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Settings updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating settings";
    }
    
    $stmt->close();
}

$conn->close();
header("Location: settings.php");
exit();
?>
