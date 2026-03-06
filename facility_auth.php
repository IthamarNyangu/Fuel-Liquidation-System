<?php
// Facility Authentication Helper Functions
// Provides helper functions for facility-based access control

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the current user is a super admin
 * Super admins have access to all facilities
 * 
 * @return bool True if user is super_admin, false otherwise
 */
function isSuperAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
}

/**
 * Check if the current user is an admin (but not super admin)
 * Regular admins have access only to their assigned facility
 * 
 * @return bool True if user is admin (not super_admin), false otherwise
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Check if the current user is staff
 * Staff members have limited access to their facility
 * 
 * @return bool True if user is staff, false otherwise
 */
function isStaff() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'staff';
}

/**
 * Get the facility ID of the current user
 * Returns null for super admins (they see all facilities)
 * 
 * @return int|null The facility ID or null if super admin or not set
 */
function getUserFacilityId() {
    // Super admins don't have a facility restriction
    if (isSuperAdmin()) {
        return null;
    }
    
    // Return facility_id from session if set
    return isset($_SESSION['facility_id']) ? intval($_SESSION['facility_id']) : null;
}

/**
 * Get the facility name of the current user
 * 
 * @param mysqli $conn Database connection
 * @return string The facility name or empty string if not found
 */
function getUserFacilityName($conn) {
    $facility_id = getUserFacilityId();
    
    if (!$facility_id) {
        return '';
    }
    
    $stmt = $conn->prepare("SELECT facility_name FROM facilities WHERE id = ?");
    $stmt->bind_param("i", $facility_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['facility_name'];
    }
    
    return '';
}

/**
 * Check if user has access to a specific facility
 * Super admins have access to all facilities
 * 
 * @param int $facility_id The facility ID to check
 * @return bool True if user has access, false otherwise
 */
function hasAccessToFacility($facility_id) {
    // Super admins have access to all facilities
    if (isSuperAdmin()) {
        return true;
    }
    
    // Other users can only access their assigned facility
    $user_facility_id = getUserFacilityId();
    return $user_facility_id && $user_facility_id == $facility_id;
}

/**
 * Get role display name for UI
 * 
 * @return string Formatted role name
 */
function getRoleDisplayName() {
    $role = $_SESSION['user_role'] ?? 'guest';
    
    switch($role) {
        case 'super_admin':
            return 'Super Administrator';
        case 'admin':
            return 'Administrator';
        case 'staff':
            return 'Staff Member';
        default:
            return 'User';
    }
}

/**
 * Check if user can manage users (add, edit, delete users)
 * Only admins and super admins can manage users
 * 
 * @return bool True if user can manage users
 */
function canManageUsers() {
    return isSuperAdmin() || isAdmin();
}

/**
 * Check if user can manage vehicles
 * Only admins and super admins can manage vehicles
 * 
 * @return bool True if user can manage vehicles
 */
function canManageVehicles() {
    return isSuperAdmin() || isAdmin();
}

/**
 * Check if user can approve requisitions
 * Only admins and super admins can approve requisitions
 * 
 * @return bool True if user can approve
 */
function canApproveRequisitions() {
    return isSuperAdmin() || isAdmin();
}

/**
 * Check if user can manage facilities
 * Only super admins can manage facilities
 * 
 * @return bool True if user can manage facilities
 */
function canManageFacilities() {
    return isSuperAdmin();
}

/**
 * Check if user can adjust float/top-up
 * Only admins and super admins can adjust float
 * 
 * @return bool True if user can adjust float
 */
function canAdjustFloat() {
    return isSuperAdmin() || isAdmin();
}
?>