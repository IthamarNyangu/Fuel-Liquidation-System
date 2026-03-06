<?php
session_start();
require_once 'db_connect.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'approver') {
    $_SESSION['error_message'] = "You don't have permission to perform this action";
    header("Location: manage_vehicles.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicle_id = intval($_POST['vehicle_id']);
    $user_id = intval($_POST['user_id']);
    $assigned_date = $_POST['assigned_date'];
    $notes = trim($_POST['notes']);
    $assigned_by = $_SESSION['user_id'];
    
    // Validate inputs
    if (empty($vehicle_id) || empty($user_id) || empty($assigned_date)) {
        $_SESSION['error_message'] = "All required fields must be filled";
        header("Location: manage_vehicles.php");
        exit();
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Deactivate any existing assignments for this vehicle
        $deactivate_query = "UPDATE vehicle_assignments 
                            SET is_active = 0 
                            WHERE vehicle_id = ? AND is_active = 1";
        $stmt = $conn->prepare($deactivate_query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $stmt->close();
        
        // Create new assignment
        $insert_query = "INSERT INTO vehicle_assignments 
                        (vehicle_id, user_id, assigned_date, assigned_by, notes, is_active) 
                        VALUES (?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("iisis", $vehicle_id, $user_id, $assigned_date, $assigned_by, $notes);
        $stmt->execute();
        $stmt->close();
        
        // Update vehicle's current_driver_id
        $update_vehicle_query = "UPDATE vehicles SET current_driver_id = ? WHERE id = ?";
        $stmt = $conn->prepare($update_vehicle_query);
        $stmt->bind_param("ii", $user_id, $vehicle_id);
        $stmt->execute();
        $stmt->close();
        
        // Get vehicle and user names for success message
        $vehicle_query = "SELECT vehicle_name, number_plate FROM vehicles WHERE id = ?";
        $stmt = $conn->prepare($vehicle_query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $vehicle = $result->fetch_assoc();
        $stmt->close();
        
        $user_query = "SELECT name FROM users WHERE id = ?";
        $stmt = $conn->prepare($user_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Vehicle " . htmlspecialchars($vehicle['vehicle_name']) . " (" . htmlspecialchars($vehicle['number_plate']) . ") assigned to " . htmlspecialchars($user['name']) . " successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error assigning vehicle: " . $e->getMessage();
    }
    
} else {
    $_SESSION['error_message'] = "Invalid request method";
}

$conn->close();
header("Location: manage_vehicles.php");
exit();
?>