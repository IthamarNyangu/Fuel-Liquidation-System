<?php
// Province and role compatibility helpers.
// The database may still store legacy role/facility naming while the UI/business
// model now uses Fleet Manager / Provincial Admin / Driver and Province scope.

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function legacyRoleFromSession(): string
{
    return strtolower(trim((string) ($_SESSION['user_role'] ?? 'driver')));
}

function normalizeRole(?string $role = null): string
{
    $role = strtolower(trim((string) ($role ?? legacyRoleFromSession())));
    $sessionIsSuper = !empty($_SESSION['is_super_admin']);
    $sessionIsFacilityAdmin = !empty($_SESSION['is_facility_admin']);

    if ($role === '' && $sessionIsSuper) {
        return 'fleet_manager';
    }

    if ($role === '' && $sessionIsFacilityAdmin) {
        return 'provincial_admin';
    }

    switch ($role) {
        case 'fleet_manager':
        case 'super_admin':
            return 'fleet_manager';
        case 'provincial_admin':
        case 'facility_admin':
        case 'admin':
        case 'approver':
            return 'provincial_admin';
        case 'driver':
        case 'staff':
            return 'driver';
        default:
            if ($sessionIsSuper) {
                return 'fleet_manager';
            }

            if ($sessionIsFacilityAdmin) {
                return 'provincial_admin';
            }

            return 'driver';
    }
}

function rawRoleFromBusinessRole(string $role): string
{
    switch (normalizeRole($role)) {
        case 'fleet_manager':
            return 'super_admin';
        case 'provincial_admin':
            return 'admin';
        case 'driver':
        default:
            return 'staff';
    }
}

function getCurrentRole(): string
{
    return normalizeRole($_SESSION['user_role'] ?? null);
}

function isFleetManager(): bool
{
    return getCurrentRole() === 'fleet_manager';
}

function isProvincialAdmin(): bool
{
    return getCurrentRole() === 'provincial_admin';
}

function isDriver(): bool
{
    return getCurrentRole() === 'driver';
}

// Legacy wrappers retained for compatibility with existing pages.
function isSuperAdmin() {
    return isFleetManager();
}

function isAdmin() {
    return isProvincialAdmin();
}

function isStaff() {
    return isDriver();
}

function getUserProvinceId() {
    if (isFleetManager()) {
        return null;
    }

    if (isset($_SESSION['province_id']) && $_SESSION['province_id'] !== null && $_SESSION['province_id'] !== '') {
        return intval($_SESSION['province_id']);
    }

    return isset($_SESSION['facility_id']) ? intval($_SESSION['facility_id']) : null;
}

// Legacy wrapper retained for compatibility.
function getUserFacilityId() {
    return getUserProvinceId();
}

function getUserProvinceName($conn) {
    $province_id = getUserProvinceId();

    if (!$province_id) {
        return '';
    }

    $stmt = $conn->prepare("SELECT facility_name FROM facilities WHERE id = ?");
    $stmt->bind_param("i", $province_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return $row['facility_name'];
    }

    return '';
}

// Legacy wrapper retained for compatibility.
function getUserFacilityName($conn) {
    return getUserProvinceName($conn);
}

function hasAccessToProvince($province_id) {
    if (isFleetManager()) {
        return true;
    }

    $user_province_id = getUserProvinceId();
    return $user_province_id && $user_province_id == $province_id;
}

// Legacy wrapper retained for compatibility.
function hasAccessToFacility($facility_id) {
    return hasAccessToProvince($facility_id);
}

function getRoleDisplayName() {
    switch (getCurrentRole()) {
        case 'fleet_manager':
            return 'Fleet Manager';
        case 'provincial_admin':
            return 'Provincial Admin';
        case 'driver':
            return 'Driver';
        default:
            return 'Account';
    }
}

function canManageUsers() {
    return isFleetManager();
}

function canManageVehicles() {
    return isFleetManager() || isProvincialAdmin();
}

function canApproveRequisitions() {
    return isFleetManager() || isProvincialAdmin();
}

function canManageFacilities() {
    return isFleetManager();
}

function canManageProvinces() {
    return isFleetManager();
}

function canAdjustFloat() {
    return isFleetManager() || isProvincialAdmin();
}

function canManageFuelPrices() {
    return isFleetManager();
}

function canCreateAccounts(): bool
{
    return isFleetManager();
}

function canReviewReconciliations(): bool
{
    return isFleetManager() || isProvincialAdmin();
}

function provinceLabelSingular(): string
{
    return 'Province';
}

function provinceLabelPlural(): string
{
    return 'Provinces';
}

function scopeAllProvincesLabel(): string
{
    return 'All Provinces';
}
