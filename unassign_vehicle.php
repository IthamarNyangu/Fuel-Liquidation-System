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

if (isset($_GET['vehicle_id'])) {
    $vehicle_id = intval($_GET['vehicle_id']);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Get vehicle name for success message
        $vehicle_query = "SELECT vehicle_name, number_plate FROM vehicles WHERE id = ?";
        $stmt = $conn->prepare($vehicle_query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $vehicle = $result->fetch_assoc();
        $stmt->close();
        
        // Deactivate all assignments for this vehicle
        $deactivate_query = "UPDATE vehicle_assignments 
                            SET is_active = 0 
                            WHERE vehicle_id = ? AND is_active = 1";
        $stmt = $conn->prepare($deactivate_query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $stmt->close();
        
        // Remove driver from vehicle
        $update_vehicle_query = "UPDATE vehicles SET current_driver_id = NULL WHERE id = ?";
        $stmt = $conn->prepare($update_vehicle_query);
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Vehicle " . htmlspecialchars($vehicle['vehicle_name']) . " (" . htmlspecialchars($vehicle['number_plate']) . ") unassigned successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error unassigning vehicle: " . $e->getMessage();
    }
    
} else {
    $_SESSION['error_message'] = "Invalid request";
}

$conn->close();
header("Location: manage_vehicles.php");
exit();
?>