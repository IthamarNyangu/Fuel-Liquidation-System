<?php
// manage_vehicles.php
// Admin authentication - only admins and approvers can access
require_once 'admin_auth.php';
require_once 'db_connect.php';
require_once 'facility_auth.php';

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    die("Error: Your account is not assigned to a facility. Please contact your administrator.");
}

$message = '';
$messageType = '';

// Get success/error messages from session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    $messageType = 'success';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $message = $_SESSION['error_message'];
    $messageType = 'error';
    unset($_SESSION['error_message']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add') {
                $vehicleName = trim($_POST['vehicle_name']);
                $numberPlate = strtoupper(trim($_POST['number_plate']));
                $assetType = strtolower(trim((string) ($_POST['asset_type'] ?? 'car')));
                $fuelType = strtolower(trim((string) ($_POST['fuel_type'] ?? '')));
                $floatLimit = floatval($_POST['float_limit']);
                $initialBalance = floatval($_POST['initial_balance']);
                $initialMileageRaw = trim((string) ($_POST['initial_mileage'] ?? '0'));
                $initialMileage = (int) $initialMileageRaw;
                
                if (empty($vehicleName) || empty($numberPlate)) {
                    throw new Exception('Vehicle name and number plate are required');
                }

                if (!in_array($assetType, ['car', 'motorcycle'], true)) {
                    throw new Exception('Vehicle type must be car or motorcycle.');
                }

                if (!in_array($fuelType, ['petrol', 'diesel'], true)) {
                    throw new Exception('Fuel type is required and must be petrol or diesel.');
                }

                if (!preg_match('/^\d+$/', $initialMileageRaw)) {
                    throw new Exception('Initial mileage must be a whole number.');
                }
                
                // Set facility_id based on user type
                if ($is_super_admin) {
                    // Super admin can choose facility or leave null
                    $facility_id = !empty($_POST['facility_id']) ? intval($_POST['facility_id']) : null;
                    if (!$facility_id) {
                        throw new Exception('Facility is required when adding a vehicle.');
                    }
                } else {
                    // Regular users: auto-assign to their facility
                    $facility_id = $user_facility_id;
                }

                if (!is_numeric((string) ($_POST['float_limit'] ?? '')) || $floatLimit < 0) {
                    throw new Exception('Card limit is required and must be zero or higher.');
                }

                if (!is_numeric((string) ($_POST['initial_balance'] ?? '')) || $initialBalance < 0) {
                    throw new Exception('Opening balance is required and must be zero or higher.');
                }
                
                // Auto-generate float account name
                $floatAccountName = $vehicleName . ' (' . $numberPlate . ')';
                
                $stmt = $conn->prepare("
                    INSERT INTO vehicles 
                    (vehicle_name, number_plate, asset_type, fuel_type, float_account_name, float_balance, float_limit, current_mileage, facility_id) 
                    VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssdddi", $vehicleName, $numberPlate, $assetType, $fuelType, $floatAccountName, $initialBalance, $floatLimit, $initialMileage, $facility_id);
                $stmt->execute();
                
                $_SESSION['success_message'] = 'Vehicle added successfully with card account!';
                header("Location: manage_vehicles.php");
                exit();
            } 
            elseif ($_POST['action'] === 'edit') {
                $id = intval($_POST['vehicle_id']);
                $vehicleName = trim($_POST['vehicle_name']);
                $numberPlate = strtoupper(trim($_POST['number_plate']));
                $assetType = strtolower(trim((string) ($_POST['asset_type'] ?? 'car')));
                $fuelType = strtolower(trim((string) ($_POST['fuel_type'] ?? '')));
                $floatLimit = floatval($_POST['float_limit']);
                
                if (empty($vehicleName) || empty($numberPlate)) {
                    throw new Exception('Vehicle name and number plate are required');
                }

                if (!in_array($assetType, ['car', 'motorcycle'], true)) {
                    throw new Exception('Vehicle type must be car or motorcycle.');
                }

                if (!in_array($fuelType, ['petrol', 'diesel'], true)) {
                    throw new Exception('Fuel type is required and must be petrol or diesel.');
                }

                if (!is_numeric((string) ($_POST['float_limit'] ?? '')) || $floatLimit < 0) {
                    throw new Exception('Card limit is required and must be zero or higher.');
                }
                
                // Update card account name
                $floatAccountName = $vehicleName . ' (' . $numberPlate . ')';
                
                $stmt = $conn->prepare("
                    UPDATE vehicles 
                    SET vehicle_name = ?, number_plate = ?, asset_type = ?, fuel_type = NULLIF(?, ''), float_account_name = ?, float_limit = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssdi", $vehicleName, $numberPlate, $assetType, $fuelType, $floatAccountName, $floatLimit, $id);
                $stmt->execute();
                
                $_SESSION['success_message'] = 'Vehicle updated successfully!';
                header("Location: manage_vehicles.php");
                exit();
            } 
            elseif ($_POST['action'] === 'delete') {
                $id = intval($_POST['vehicle_id']);
                
                // Check if vehicle is used in requisitions or logbook
                $checkStmt = $conn->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM requisitions WHERE vehicle_id = ?) as req_count,
                        (SELECT COUNT(*) FROM logbook WHERE vehicle_id = ?) as log_count
                ");
                $checkStmt->bind_param("ii", $id, $id);
                $checkStmt->execute();
                $result = $checkStmt->get_result();
                $counts = $result->fetch_assoc();
                
                $totalCount = $counts['req_count'] + $counts['log_count'];
                
                if ($totalCount > 0) {
                    throw new Exception('Cannot delete vehicle. It has ' . $counts['req_count'] . ' requisition(s) and ' . $counts['log_count'] . ' logbook entries.');
                }
                
                $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                
                $_SESSION['success_message'] = 'Vehicle deleted successfully!';
                header("Location: manage_vehicles.php");
                exit();
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header("Location: manage_vehicles.php");
            exit();
        }
    }
}

// Get search/filter parameters
$search = $_GET['search'] ?? '';
$assignmentStatus = $_GET['assignment_status'] ?? '';
$fuelTypeFilter = $_GET['fuel_type'] ?? '';
$facilityFilter = $_GET['facility_id'] ?? '';
$sortToken = $_GET['sort_token'] ?? '';
$sortMap = [
    'vehicle_name_asc' => ['vehicle_name', 'ASC'],
    'vehicle_name_desc' => ['vehicle_name', 'DESC'],
    'number_plate_asc' => ['number_plate', 'ASC'],
    'number_plate_desc' => ['number_plate', 'DESC'],
    'created_at_desc' => ['created_at', 'DESC'],
    'created_at_asc' => ['created_at', 'ASC'],
];

if ($sortToken && isset($sortMap[$sortToken])) {
    [$sortBy, $sortOrder] = $sortMap[$sortToken];
} else {
    $sortBy = $_GET['sort'] ?? 'vehicle_name';
    $sortOrder = $_GET['order'] ?? 'ASC';
    $sortToken = $sortBy . '_' . strtolower($sortOrder);
}

$assignmentStatus = in_array($assignmentStatus, ['assigned', 'unassigned'], true) ? $assignmentStatus : '';
$fuelTypeFilter = in_array($fuelTypeFilter, ['petrol', 'diesel', 'not_set'], true) ? $fuelTypeFilter : '';
$facilityFilter = ctype_digit((string) $facilityFilter) ? (int) $facilityFilter : 0;

// Build query with enhanced statistics
$query = "SELECT v.*, 
          (SELECT COUNT(*) FROM requisitions WHERE vehicle_id = v.id) as requisition_count,
          (SELECT COUNT(*) FROM logbook WHERE vehicle_id = v.id) as logbook_count,
          COALESCE(v.float_balance, 0) as float_balance,
          COALESCE(v.float_limit, 0) as float_limit,
          COALESCE(v.current_mileage, 0) as current_mileage,
          u.name as driver_name,
          f.facility_name
          FROM vehicles v
          LEFT JOIN users u ON v.current_driver_id = u.id
          LEFT JOIN facilities f ON v.facility_id = f.id
          WHERE 1=1";
$params = [];
$types = '';

// Add facility filter for non-super-admins
if (!$is_super_admin && $user_facility_id) {
    $query .= " AND v.facility_id = ?";
    $params[] = $user_facility_id;
    $types .= 'i';
} elseif ($is_super_admin && $facilityFilter > 0) {
    $query .= " AND v.facility_id = ?";
    $params[] = $facilityFilter;
    $types .= 'i';
}

if ($search) {
    $query .= " AND (v.vehicle_name LIKE ? OR v.number_plate LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'ss';
}

if ($assignmentStatus === 'assigned') {
    $query .= " AND v.current_driver_id IS NOT NULL";
} elseif ($assignmentStatus === 'unassigned') {
    $query .= " AND v.current_driver_id IS NULL";
}

if ($fuelTypeFilter === 'petrol' || $fuelTypeFilter === 'diesel') {
    $query .= " AND v.fuel_type = ?";
    $params[] = $fuelTypeFilter;
    $types .= 's';
} elseif ($fuelTypeFilter === 'not_set') {
    $query .= " AND (v.fuel_type IS NULL OR v.fuel_type = '')";
}

$allowedSort = ['vehicle_name', 'number_plate', 'created_at'];
if (in_array($sortBy, $allowedSort)) {
    $query .= " ORDER BY v.$sortBy " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC');
}

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$vehicles = $result->fetch_all(MYSQLI_ASSOC);

$totalVehicles = count($vehicles);
$assignedVehicles = 0;
foreach ($vehicles as $vehicle) {
    if (!empty($vehicle['driver_name'])) {
        $assignedVehicles++;
    }
}
$unassignedVehicles = $totalVehicles - $assignedVehicles;

// Fetch all users for assignment
$usersStmt = $conn->query("SELECT id, name, email FROM users ORDER BY name");
$users = $usersStmt->fetch_all(MYSQLI_ASSOC);

// Fetch all facilities (for super admin)
$facilitiesStmt = $conn->query("SELECT id, facility_name, facility_code FROM facilities WHERE is_active = 1 ORDER BY facility_name");
$facilities = $facilitiesStmt->fetch_all(MYSQLI_ASSOC);

// Get facility display name
if ($is_super_admin) {
    $facility_display = '';
} else {
    $facility_query = "SELECT facility_name FROM facilities WHERE id = ?";
    $facility_stmt = $conn->prepare($facility_query);
    $facility_stmt->bind_param("i", $user_facility_id);
    $facility_stmt->execute();
    $facility_result = $facility_stmt->get_result();
    $facility_row = $facility_result->fetch_assoc();
    $facility_display = $facility_row ? $facility_row['facility_name'] : 'Your Facility';
    $facility_stmt->close();
}

// Fetch recent assignments
$assignmentsQuery = "SELECT va.*, v.vehicle_name, v.number_plate, 
                             u.name as driver_name, au.name as assigned_by_name
                      FROM vehicle_assignments va
                      JOIN vehicles v ON va.vehicle_id = v.id
                      JOIN users u ON va.user_id = u.id
                      LEFT JOIN users au ON va.assigned_by = au.id
                      WHERE va.is_active = 1
                      ORDER BY va.created_at DESC";
$assignmentsResult = $conn->query($assignmentsQuery);
$assignments = $assignmentsResult ? $assignmentsResult->fetch_all(MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management - Fuel Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-red: #35627c;
            --dark-red: #29485d;
            --light-red: #e9f1f5;
            --gray-50: #f3f6f8;
            --gray-100: #eef3f6;
            --gray-200: #dde4ea;
            --gray-300: #cad3dc;
            --gray-400: #8b98a6;
            --gray-500: #6a7786;
            --gray-600: #43515f;
            --gray-700: #26323d;
            --gray-800: #1f3443;
            --gray-900: #16202a;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top right, rgba(76, 127, 153, 0.12), transparent 24%), linear-gradient(145deg, #f7f7f5 0%, #f6f8fa 46%, #eef3f6 100%);
            background-attachment: fixed;
            min-height: 100vh;
            padding: 30px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Facility Badge */
        .facility-badge {
            background: rgba(53, 98, 124, 0.14);
            color: #35627c;
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(53, 98, 124, 0.22);
        }

        .facility-badge.super-admin {
            background: linear-gradient(135deg, #29485d, #35627c);
            color: white;
            border-color: transparent;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--gray-900);
            font-size: 32px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: white;
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        /* Tabs */
        .tabs-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            padding: 15px;
            margin-bottom: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
        }

        .tabs {
            display: flex;
            gap: 10px;
        }

        .tab {
            flex: 1;
            padding: 15px 25px;
            background: transparent;
            border: 2px solid transparent;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            color: var(--gray-600);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .tab:hover {
            background: var(--gray-50);
            color: var(--gray-800);
        }

        .tab.active {
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border-color: transparent;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Stats Card */
        .stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 30px;
            max-width: 400px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .stat-card h3 {
            color: var(--gray-600);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--gray-900);
        }

        .stat-icon {
            position: absolute;
            right: 25px;
            top: 25px;
            font-size: 48px;
            color: rgba(220, 38, 38, 0.1);
        }

        /* Search & Filter */
        .controls {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 45px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .clear-search {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 16px;
        }

        .clear-search:hover {
            color: var(--primary-red);
        }

        .sort-select {
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .sort-select:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        /* Vehicle Grid */
        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .vehicle-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .vehicle-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 18px 36px rgba(22, 32, 42, 0.12);
        }

        .vehicle-icon-bg {
            width: 70px;
            height: 70px;
            background: var(--light-red);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .vehicle-icon-bg i {
            font-size: 32px;
            color: var(--primary-red);
        }

        .vehicle-name {
            font-size: 20px;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .vehicle-plate {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-red);
            background: var(--light-red);
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-block;
            margin-bottom: 15px;
            border: 1px solid rgba(53, 98, 124, 0.18);
        }

        .vehicle-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }

        .info-label {
            color: var(--gray-600);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-value {
            color: var(--gray-900);
            font-weight: 700;
        }

        .driver-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dcfce7;
            color: #166534;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .no-driver-badge {
            background: #fef3c7;
            color: #92400e;
        }

        .vehicle-facility {
            font-size: 12px;
            font-weight: 600;
            color: #35627c;
            background: rgba(139, 92, 246, 0.1);
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 15px;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .vehicle-actions {
            display: flex;
            gap: 8px;
        }

        .btn-small {
            flex: 1;
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-view {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-edit {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--primary-red), #b91c1c);
            color: white;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(53, 98, 124, 0.24);
        }

        .btn-assign {
            background: linear-gradient(135deg, #29485d, #35627c);
            color: white;
        }

        .btn-assign:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        /* Assignment Table */
        .assignment-table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        table th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table tr:hover {
            background: var(--gray-50);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s;
            overflow-y: auto;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.3);
            border-radius: 24px;
            padding: 35px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content-large {
            max-width: 900px;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            color: var(--gray-900);
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .close-modal-btn {
            background: var(--gray-200);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .close-modal-btn:hover {
            background: var(--primary-red);
            color: white;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .form-group input[readonly] {
            background-color: var(--gray-100);
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 5px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn-cancel {
            flex: 1;
            padding: 14px;
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }

        .btn-submit {
            flex: 1;
            padding: 14px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 2px solid #86efac;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 2px solid #fca5a5;
        }

        .alert i {
            font-size: 20px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
        }

        .empty-state i {
            font-size: 80px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 16px;
            color: var(--gray-600);
            margin-bottom: 25px;
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .tabs {
                flex-direction: column;
            }

            .vehicles-grid {
                grid-template-columns: 1fr;
            }

            .controls {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .vehicle-actions {
                flex-wrap: wrap;
            }

            .btn-small {
                flex: 1 1 45%;
            }
        }
    </style>
    <link rel="stylesheet" type="text/css" href="manage_vehicles.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/manage_vehicles.css')); ?>">
</head>
<body>
    <div class="container">
        <div class="vm-page">
            <header class="vm-header">
                <div class="vm-header-main">
                    <div class="vm-title-block">
                        <p class="vm-kicker">Fleet Administration</p>
                        <h1>Vehicle Management</h1>
                        <p class="vm-subtitle">Maintain vehicle records, facility ownership, and driver assignment readiness in one place.</p>
                    </div>
                    <?php if (!$is_super_admin): ?>
                        <span class="vm-scope-chip">
                            <?php echo 'Facility: ' . htmlspecialchars($facility_display); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="vm-header-actions">
                    <a href="dashboard.php" class="vm-btn vm-btn-secondary">Back to Dashboard</a>
                    <button type="button" onclick="openAddModal()" class="vm-btn vm-btn-primary">Add Vehicle</button>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="vm-alert vm-alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <section class="vm-stats">
                <article class="vm-stat-tile">
                    <div class="vm-stat-label">Total Vehicles</div>
                    <div class="vm-stat-value"><?php echo number_format($totalVehicles); ?></div>
                    <div class="vm-stat-caption">Current list after filters</div>
                </article>
                <article class="vm-stat-tile">
                    <div class="vm-stat-label">Assigned</div>
                    <div class="vm-stat-value"><?php echo number_format($assignedVehicles); ?></div>
                    <div class="vm-stat-caption">Vehicles with an active driver</div>
                </article>
                <article class="vm-stat-tile">
                    <div class="vm-stat-label">Unassigned</div>
                    <div class="vm-stat-value"><?php echo number_format($unassignedVehicles); ?></div>
                    <div class="vm-stat-caption">Ready to be assigned</div>
                </article>
            </section>

            <section class="vm-panel">
                <div class="vm-panel-head">
                    <div>
                        <h2>Vehicle Register</h2>
                        <p>Compact table view for scanning vehicles, setup status, and assignments quickly.</p>
                    </div>
                </div>

                <form method="get" class="vm-toolbar<?php echo $is_super_admin ? ' is-global' : ''; ?>">
                    <div class="vm-search-field">
                        <label for="searchInput">Search</label>
                        <input
                            type="text"
                            id="searchInput"
                            name="search"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search by vehicle name or plate">
                    </div>

                    <div class="vm-filter-field">
                        <label for="assignmentStatus">Assignment</label>
                        <select id="assignmentStatus" name="assignment_status">
                            <option value="">All Vehicles</option>
                            <option value="assigned" <?php echo $assignmentStatus === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                            <option value="unassigned" <?php echo $assignmentStatus === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                        </select>
                    </div>

                    <div class="vm-filter-field">
                        <label for="fuelTypeFilter">Fuel Type</label>
                        <select id="fuelTypeFilter" name="fuel_type">
                            <option value="">All Fuel Types</option>
                            <option value="petrol" <?php echo $fuelTypeFilter === 'petrol' ? 'selected' : ''; ?>>Petrol</option>
                            <option value="diesel" <?php echo $fuelTypeFilter === 'diesel' ? 'selected' : ''; ?>>Diesel</option>
                            <option value="not_set" <?php echo $fuelTypeFilter === 'not_set' ? 'selected' : ''; ?>>Not Set</option>
                        </select>
                    </div>

                    <?php if ($is_super_admin): ?>
                        <div class="vm-filter-field">
                            <label for="facilityFilter">Facility</label>
                            <select id="facilityFilter" name="facility_id">
                                <option value="">All Facilities</option>
                                <?php foreach ($facilities as $facility): ?>
                                    <option value="<?php echo (int) $facility['id']; ?>" <?php echo $facilityFilter === (int) $facility['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($facility['facility_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="vm-filter-field">
                        <label for="sortToken">Sort</label>
                        <select id="sortToken" name="sort_token">
                            <option value="vehicle_name_asc" <?php echo $sortToken === 'vehicle_name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="vehicle_name_desc" <?php echo $sortToken === 'vehicle_name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                            <option value="number_plate_asc" <?php echo $sortToken === 'number_plate_asc' ? 'selected' : ''; ?>>Plate A-Z</option>
                            <option value="number_plate_desc" <?php echo $sortToken === 'number_plate_desc' ? 'selected' : ''; ?>>Plate Z-A</option>
                            <option value="created_at_desc" <?php echo $sortToken === 'created_at_desc' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="created_at_asc" <?php echo $sortToken === 'created_at_asc' ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                    </div>

                    <div class="vm-toolbar-actions">
                        <button type="submit" class="vm-btn vm-btn-primary">Apply</button>
                        <a href="manage_vehicles.php" class="vm-btn vm-btn-ghost">Reset</a>
                    </div>
                </form>

                <?php if (count($vehicles) > 0): ?>
                    <div class="vm-table-wrap">
                        <table class="vm-table">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Plate</th>
                                    <th>Fuel Type</th>
                                    <th>Facility</th>
                                    <th>Assigned Driver</th>
                                    <th class="is-numeric">Mileage</th>
                                    <th class="is-numeric">Card Balance</th>
                                    <th>Status</th>
                                    <th class="is-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <?php
                                    $facilityName = $vehicle['facility_name'] ?: ($is_super_admin ? 'No Facility' : $facility_display);
                                    $driverName = $vehicle['driver_name'] ?: 'Unassigned';
                                    $assetTypeLabel = ucfirst((string) ($vehicle['asset_type'] ?: 'car'));
                                    $rowStatusLabel = empty($vehicle['fuel_type'])
                                        ? 'Needs Fuel Type'
                                        : (!empty($vehicle['driver_name']) ? 'Assigned' : 'Unassigned');
                                    $rowStatusClass = empty($vehicle['fuel_type'])
                                        ? 'is-warning'
                                        : (!empty($vehicle['driver_name']) ? 'is-positive' : 'is-pending');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="vm-vehicle-primary">
                                                <div class="vm-vehicle-name"><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></div>
                                                <div class="vm-vehicle-meta"><?php echo htmlspecialchars($assetTypeLabel); ?></div>
                                            </div>
                                        </td>
                                        <td><span class="vm-plate-chip"><?php echo htmlspecialchars($vehicle['number_plate']); ?></span></td>
                                        <td>
                                            <?php if ($vehicle['fuel_type']): ?>
                                                <?php echo htmlspecialchars(ucfirst($vehicle['fuel_type'])); ?>
                                            <?php else: ?>
                                                <span class="vm-muted-text">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($facilityName); ?></td>
                                        <td>
                                            <span class="<?php echo $vehicle['driver_name'] ? 'vm-driver-name' : 'vm-muted-text'; ?>">
                                                <?php echo htmlspecialchars($driverName); ?>
                                            </span>
                                        </td>
                                        <td class="is-numeric"><?php echo number_format((int) round((float) $vehicle['current_mileage'])); ?> km</td>
                                        <td class="is-numeric">K <?php echo number_format((float) $vehicle['float_balance'], 2); ?></td>
                                        <td><span class="vm-status-chip <?php echo $rowStatusClass; ?>"><?php echo htmlspecialchars($rowStatusLabel); ?></span></td>
                                        <td class="is-actions">
                                            <details class="vm-row-menu">
                                                <summary class="vm-menu-trigger" aria-label="Open vehicle actions">
                                                    <span></span><span></span><span></span>
                                                </summary>
                                                <div class="vm-row-menu-popover">
                                                    <button type="button" onclick='openViewModal(<?php echo json_encode($vehicle); ?>)'>View Details</button>
                                                    <button type="button" onclick='openEditModal(<?php echo json_encode($vehicle); ?>)'>Edit Vehicle</button>
                                                    <button type="button" onclick='openAssignModal(<?php echo json_encode($vehicle); ?>)'><?php echo $vehicle['driver_name'] ? 'Reassign Driver' : 'Assign Driver'; ?></button>
                                                    <button type="button" class="danger" onclick="confirmDelete(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_name'], ENT_QUOTES); ?>')">Delete Vehicle</button>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="vm-empty-state">
                        <h3>No vehicles found</h3>
                        <p><?php echo $search || $assignmentStatus || $fuelTypeFilter || $facilityFilter ? 'Try clearing a filter or changing the search term.' : 'Add the first vehicle to start building the fleet register.'; ?></p>
                        <div class="vm-empty-actions">
                            <?php if ($search || $assignmentStatus || $fuelTypeFilter || $facilityFilter): ?>
                                <a href="manage_vehicles.php" class="vm-btn vm-btn-ghost">Clear Filters</a>
                            <?php endif; ?>
                            <button type="button" onclick="openAddModal()" class="vm-btn vm-btn-primary">Add Vehicle</button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="vm-panel vm-panel-secondary">
                <div class="vm-panel-head">
                    <div>
                        <h2>Active Assignments</h2>
                        <p>Current driver-to-vehicle assignments. Use this section when you need to review or remove an active assignment.</p>
                    </div>
                </div>

                <?php if (count($assignments) > 0): ?>
                    <div class="vm-table-wrap">
                        <table class="vm-table vm-table-secondary">
                            <thead>
                                <tr>
                                    <th>Assigned Date</th>
                                    <th>Vehicle</th>
                                    <th>Plate</th>
                                    <th>Driver</th>
                                    <th>Assigned By</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th class="is-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($assignment['assigned_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['vehicle_name']); ?></td>
                                        <td><span class="vm-plate-chip"><?php echo htmlspecialchars($assignment['number_plate']); ?></span></td>
                                        <td><?php echo htmlspecialchars($assignment['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['assigned_by_name'] ?? 'System'); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['notes'] ?: 'No notes'); ?></td>
                                        <td><span class="vm-status-chip is-positive">Active</span></td>
                                        <td class="is-actions">
                                            <button type="button" class="vm-inline-danger" onclick="unassignVehicle(<?php echo $assignment['vehicle_id']; ?>, '<?php echo htmlspecialchars($assignment['vehicle_name'], ENT_QUOTES); ?>')">Unassign</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="vm-empty-state vm-empty-state-compact">
                        <h3>No active assignments</h3>
                        <p>Assign a driver from the vehicle list when you are ready.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>


    <!-- View Vehicle Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content vm-modal-content vm-modal-content-view">
            <div class="modal-header vm-modal-header">
                <div>
                    <div class="vm-modal-kicker">Vehicle Record</div>
                    <h2 id="viewModalTitle">Vehicle Details</h2>
                </div>
                <button class="close-modal-btn" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="vm-readonly-shell">
                <section class="vm-readonly-hero">
                    <div class="vm-readonly-identity">
                        <div class="vm-readonly-kicker">Registered Vehicle</div>
                        <h3 id="viewVehicleName">-</h3>
                        <div class="vm-readonly-meta">
                            <span class="vm-plate-chip" id="viewNumberPlate">-</span>
                            <span class="vm-muted-pill" id="viewAssetType">Car</span>
                            <span class="vm-muted-pill" id="viewFuelType">Not set</span>
                        </div>
                    </div>

                    <div class="vm-readonly-stat-grid">
                        <div class="vm-readonly-stat">
                            <span class="vm-readonly-stat-label">Current Mileage</span>
                            <strong id="viewMileage">0 km</strong>
                        </div>
                        <div class="vm-readonly-stat">
                            <span class="vm-readonly-stat-label">Card Balance</span>
                            <strong id="viewBalance">K 0.00</strong>
                        </div>
                    </div>
                </section>

                <section class="vm-readonly-section">
                    <div class="vm-readonly-section-head">
                        <h3>Overview</h3>
                        <p>Core assignment and registration details for this vehicle.</p>
                    </div>
                    <div class="vm-readonly-grid">
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Facility</span>
                            <span class="vm-readonly-value" id="viewFacility">-</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Assigned Driver</span>
                            <span class="vm-readonly-value" id="viewAssignedDriver">Unassigned</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Date Added</span>
                            <span class="vm-readonly-value" id="viewDateAdded">-</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Card Account</span>
                            <span class="vm-readonly-value" id="viewFloatAccount">-</span>
                        </div>
                    </div>
                </section>

                <section class="vm-readonly-section">
                    <div class="vm-readonly-section-head">
                        <h3>Usage</h3>
                        <p>Operational activity recorded against the vehicle.</p>
                    </div>
                    <div class="vm-readonly-grid">
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Total Trips</span>
                            <span class="vm-readonly-value" id="viewTotalTrips">0</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Requisitions</span>
                            <span class="vm-readonly-value" id="viewRequisitions">0</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Logbook Entries</span>
                            <span class="vm-readonly-value" id="viewLogbook">0</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Current Mileage</span>
                            <span class="vm-readonly-value" id="viewMileageDetail">0 km</span>
                        </div>
                    </div>
                </section>

                <section class="vm-readonly-section">
                    <div class="vm-readonly-section-head">
                        <h3>Card &amp; Limits</h3>
                        <p>Balance and configured spending threshold.</p>
                    </div>
                    <div class="vm-readonly-grid">
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Card Balance</span>
                            <span class="vm-readonly-value" id="viewBalanceDetail">K 0.00</span>
                        </div>
                        <div class="vm-readonly-item">
                            <span class="vm-readonly-label">Card Limit</span>
                            <span class="vm-readonly-value" id="viewLimit">K 0.00</span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="vm-modal-actions">
                <button type="button" class="btn-cancel" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Vehicle Modal -->
    <div class="modal" id="vehicleModal">
        <div class="modal-content vm-modal-content vm-modal-content-form">
            <div class="modal-header vm-modal-header">
                <div>
                    <div class="vm-modal-kicker">Vehicle Record</div>
                    <h2 id="modalTitle">Add Vehicle</h2>
                </div>
                <button class="close-modal-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="vehicleForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="vehicle_id" id="vehicleId">

                <div class="vm-form-section">
                    <div class="vm-form-section-head">
                        <h3>Basic Information</h3>
                        <p>Core identity for the vehicle record.</p>
                    </div>
                    <div class="vm-form-grid">
                        <div class="vm-form-field">
                            <label for="vehicleName">Vehicle Name</label>
                            <input
                                type="text"
                                id="vehicleName"
                                name="vehicle_name"
                                placeholder="e.g. Toyota Hilux"
                                required>
                        </div>

                        <div class="vm-form-field">
                            <label for="numberPlate">Plate Number</label>
                            <input
                                type="text"
                                id="numberPlate"
                                name="number_plate"
                                placeholder="e.g. ABC 123X"
                                style="text-transform: uppercase;"
                                required>
                        </div>

                        <div class="vm-form-field">
                            <label for="assetType">Vehicle Type</label>
                            <select id="assetType" name="asset_type" required>
                                <option value="car">Car</option>
                                <option value="motorcycle">Motorcycle</option>
                            </select>
                        </div>

                        <div class="vm-form-field">
                            <label for="fuelType">Fuel Type</label>
                            <select id="fuelType" name="fuel_type" required>
                                <option value="">Select Fuel Type</option>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="vm-form-section">
                    <div class="vm-form-section-head">
                        <h3>Assignment</h3>
                        <p>Facility ownership and current driver context.</p>
                    </div>
                    <div class="vm-form-grid">
                        <?php if ($is_super_admin): ?>
                            <div class="vm-form-field" id="facilityGroup">
                                <label for="facilitySelect">Facility</label>
                                <select id="facilitySelect" name="facility_id" required>
                                    <option value="">Select Facility</option>
                                    <?php foreach ($facilities as $facility): ?>
                                        <option value="<?php echo $facility['id']; ?>">
                                            <?php echo htmlspecialchars($facility['facility_name']); ?> (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="vm-form-field">
                                <label for="facilityDisplay">Facility</label>
                                <input type="text" id="facilityDisplay" value="<?php echo htmlspecialchars($facility_display); ?>" readonly>
                            </div>
                        <?php endif; ?>

                        <div class="vm-form-field">
                            <label for="assignedDriverPreview">Assigned Driver</label>
                            <input type="text" id="assignedDriverPreview" value="Assign after saving" readonly>
                            <div class="vm-field-note">Driver assignment stays in the dedicated Assign action.</div>
                        </div>
                    </div>
                </div>

                <div class="vm-form-section">
                    <div class="vm-form-section-head">
                        <h3>Odometer &amp; Card</h3>
                        <p>Starting values for mileage and card setup.</p>
                    </div>
                    <div class="vm-form-grid">
                        <div class="vm-form-field" id="initialMileageGroup">
                            <label for="initialMileage">Initial Mileage</label>
                            <div class="vm-input-shell has-suffix">
                                <input
                                    type="number"
                                    id="initialMileage"
                                    name="initial_mileage"
                                    placeholder="e.g. 12000"
                                    step="1"
                                    min="0"
                                    value="0"
                                    required>
                                <span class="vm-input-affix is-suffix">km</span>
                            </div>
                        </div>

                        <div class="vm-form-field vm-form-field-amount">
                            <label for="floatLimit">Card Limit</label>
                            <div class="vm-amount-stack">
                                <div class="vm-input-shell has-prefix">
                                    <span class="vm-input-affix is-prefix">K</span>
                                    <input
                                        type="number"
                                        id="floatLimit"
                                        class="vm-amount-input"
                                        name="float_limit"
                                        placeholder="10000"
                                        inputmode="numeric"
                                        step="1"
                                        min="0"
                                        value="10000"
                                        required>
                                </div>
                                <div class="vm-quick-amounts" aria-label="Quick card limit amounts">
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('floatLimit', 1000)">+K 1,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('floatLimit', 5000)">+K 5,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('floatLimit', 10000)">+K 10,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="setAmount('floatLimit', 20000)">Set K 20,000</button>
                                </div>
                            </div>
                        </div>

                        <div class="vm-form-field vm-form-field-amount" id="initialBalanceGroup">
                            <label for="initialBalance">Opening Balance</label>
                            <div class="vm-amount-stack">
                                <div class="vm-input-shell has-prefix">
                                    <span class="vm-input-affix is-prefix">K</span>
                                    <input
                                        type="number"
                                        id="initialBalance"
                                        class="vm-amount-input"
                                        name="initial_balance"
                                        placeholder="0"
                                        inputmode="numeric"
                                        step="1"
                                        min="0"
                                        value="0"
                                        required>
                                </div>
                                <div class="vm-quick-amounts" aria-label="Quick opening balance amounts">
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('initialBalance', 1000)">+K 1,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('initialBalance', 5000)">+K 5,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="adjustAmount('initialBalance', 10000)">+K 10,000</button>
                                    <button type="button" class="vm-quick-amount" onclick="setAmount('initialBalance', 0)">Reset</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-actions vm-modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-submit"><span id="submitText">Add Vehicle</span></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Vehicle Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-check"></i> Assign Vehicle to Driver</h2>
                <button class="close-modal-btn" onclick="closeAssignModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="assign_vehicle.php" method="POST">
                <input type="hidden" name="vehicle_id" id="assign_vehicle_id">
                
                <div class="form-group">
                    <label>Vehicle</label>
                    <input type="text" id="assign_vehicle_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>
                        <i class="fas fa-user"></i> Select Driver <span style="color: var(--primary-red);">*</span>
                    </label>
                    <select name="user_id" required>
                        <option value="">-- Select a driver --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['name']); ?> 
                                (<?php echo htmlspecialchars($user['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <i class="fas fa-calendar"></i> Assignment Date <span style="color: var(--primary-red);">*</span>
                    </label>
                    <input type="date" name="assigned_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>
                        <i class="fas fa-sticky-note"></i> Notes (Optional)
                    </label>
                    <textarea name="notes" rows="3" placeholder="Any additional notes about this assignment..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAssignModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check"></i> Assign Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle" style="color: var(--primary-red);"></i> Confirm Delete</h2>
                <button class="close-modal-btn" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p style="color: var(--gray-700); margin-bottom: 25px; font-size: 16px;">
                Are you sure you want to delete <strong id="deleteVehicleName"></strong>?
                This action cannot be undone.
            </p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="vehicle_id" id="deleteVehicleId">
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, var(--primary-red), #b91c1c);">
                        <i class="fas fa-trash"></i> Delete Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openViewModal(vehicle) {
            const totalTrips = Number(vehicle.requisition_count || 0) + Number(vehicle.logbook_count || 0);
            const formatCurrency = (value) => 'K ' + Number(value || 0).toFixed(2);
            const formatWholeCurrency = (value) => 'K ' + Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
            const formatMileage = (value) => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' km';
            const formatTitle = (value, fallback) => value ? value.charAt(0).toUpperCase() + value.slice(1) : fallback;
            const createdAt = vehicle.created_at ? new Date(vehicle.created_at) : null;
            const createdDateLabel = createdAt && !Number.isNaN(createdAt.getTime())
                ? createdAt.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                })
                : '-';

            document.getElementById('viewVehicleName').textContent = vehicle.vehicle_name;
            document.getElementById('viewNumberPlate').textContent = vehicle.number_plate;
            document.getElementById('viewAssetType').textContent = formatTitle(vehicle.asset_type, 'Car');
            document.getElementById('viewFuelType').textContent = formatTitle(vehicle.fuel_type, 'Not set');
            document.getElementById('viewFacility').textContent = vehicle.facility_name || 'No Facility';
            document.getElementById('viewAssignedDriver').textContent = vehicle.driver_name || 'Unassigned';
            document.getElementById('viewTotalTrips').textContent = totalTrips;
            document.getElementById('viewRequisitions').textContent = vehicle.requisition_count;
            document.getElementById('viewLogbook').textContent = vehicle.logbook_count;
            document.getElementById('viewMileage').textContent = formatMileage(vehicle.current_mileage);
            document.getElementById('viewMileageDetail').textContent = formatMileage(vehicle.current_mileage);
            document.getElementById('viewBalance').textContent = formatCurrency(vehicle.float_balance);
            document.getElementById('viewBalanceDetail').textContent = formatCurrency(vehicle.float_balance);
            document.getElementById('viewLimit').textContent = formatWholeCurrency(vehicle.float_limit);
            document.getElementById('viewFloatAccount').textContent = vehicle.float_account_name || '-';
            document.getElementById('viewDateAdded').textContent = createdDateLabel;
            
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Vehicle';
            document.getElementById('formAction').value = 'add';
            document.getElementById('submitText').textContent = 'Add Vehicle';
            document.getElementById('vehicleForm').reset();
            document.getElementById('assetType').value = 'car';
            document.getElementById('floatLimit').value = '10000';
            document.getElementById('initialBalance').value = '0';
            document.getElementById('initialMileage').value = '0';
            document.getElementById('fuelType').value = '';
            document.getElementById('assignedDriverPreview').value = 'Assign after saving';
            const facilitySelect = document.getElementById('facilitySelect');
            if (facilitySelect) {
                facilitySelect.value = '';
            }
            
            document.getElementById('initialBalanceGroup').style.display = 'block';
            document.getElementById('initialMileageGroup').style.display = 'block';
            
            document.getElementById('vehicleModal').classList.add('active');
        }

        function openEditModal(vehicle) {
            document.getElementById('modalTitle').textContent = 'Edit Vehicle';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('submitText').textContent = 'Save Changes';
            document.getElementById('vehicleId').value = vehicle.id;
            document.getElementById('vehicleName').value = vehicle.vehicle_name;
            document.getElementById('numberPlate').value = vehicle.number_plate;
            document.getElementById('assetType').value = vehicle.asset_type || 'car';
            document.getElementById('fuelType').value = vehicle.fuel_type || '';
            document.getElementById('floatLimit').value = Number(vehicle.float_limit || 0).toFixed(0);
            document.getElementById('assignedDriverPreview').value = vehicle.driver_name || 'Not assigned';
            const facilitySelect = document.getElementById('facilitySelect');
            if (facilitySelect) {
                facilitySelect.value = vehicle.facility_id || '';
            }
            
            document.getElementById('initialBalanceGroup').style.display = 'none';
            document.getElementById('initialMileageGroup').style.display = 'none';
            
            document.getElementById('vehicleModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('vehicleModal').classList.remove('active');
        }

        function openAssignModal(vehicle) {
            document.getElementById('assign_vehicle_id').value = vehicle.id;
            document.getElementById('assign_vehicle_name').value = vehicle.vehicle_name + ' (' + vehicle.number_plate + ')';
            document.getElementById('assignModal').classList.add('active');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
        }

        function adjustAmount(fieldId, amount) {
            const field = document.getElementById(fieldId);
            if (!field) {
                return;
            }

            const currentValue = Number(field.value || 0);
            const nextValue = Math.max(0, currentValue + Number(amount || 0));
            field.value = nextValue.toFixed(0);
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function setAmount(fieldId, amount) {
            const field = document.getElementById(fieldId);
            if (!field) {
                return;
            }

            field.value = Math.max(0, Number(amount || 0)).toFixed(0);
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteVehicleName').textContent = name;
            document.getElementById('deleteVehicleId').value = id;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        function unassignVehicle(vehicleId, vehicleName) {
            if (confirm('Are you sure you want to unassign ' + vehicleName + ' from its current driver?')) {
                window.location.href = 'unassign_vehicle.php?vehicle_id=' + vehicleId;
            }
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Auto-hide alert after 5 seconds
        <?php if ($message): ?>
            setTimeout(() => {
                const alert = document.querySelector('.vm-alert, .alert');
                if (alert) {
                    alert.style.animation = 'slideDown 0.3s reverse';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
