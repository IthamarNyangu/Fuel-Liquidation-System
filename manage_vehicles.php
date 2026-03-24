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
                $fuelType = strtolower(trim((string) ($_POST['fuel_type'] ?? '')));
                $floatLimit = floatval($_POST['float_limit']);
                $initialBalance = floatval($_POST['initial_balance']);
                $initialMileage = floatval($_POST['initial_mileage']);
                
                if (empty($vehicleName) || empty($numberPlate)) {
                    throw new Exception('Vehicle name and number plate are required');
                }

                if ($fuelType !== '' && !in_array($fuelType, ['petrol', 'diesel'], true)) {
                    throw new Exception('Fuel type must be petrol or diesel.');
                }
                
                // Set facility_id based on user type
                if ($is_super_admin) {
                    // Super admin can choose facility or leave null
                    $facility_id = !empty($_POST['facility_id']) ? intval($_POST['facility_id']) : null;
                } else {
                    // Regular users: auto-assign to their facility
                    $facility_id = $user_facility_id;
                }
                
                // Auto-generate float account name
                $floatAccountName = $vehicleName . ' (' . $numberPlate . ')';
                
                $stmt = $conn->prepare("
                    INSERT INTO vehicles 
                    (vehicle_name, number_plate, fuel_type, float_account_name, float_balance, float_limit, current_mileage, facility_id) 
                    VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssdddi", $vehicleName, $numberPlate, $fuelType, $floatAccountName, $initialBalance, $floatLimit, $initialMileage, $facility_id);
                $stmt->execute();
                
                $_SESSION['success_message'] = 'Vehicle added successfully with float account!';
                header("Location: manage_vehicles.php");
                exit();
            } 
            elseif ($_POST['action'] === 'edit') {
                $id = intval($_POST['vehicle_id']);
                $vehicleName = trim($_POST['vehicle_name']);
                $numberPlate = strtoupper(trim($_POST['number_plate']));
                $fuelType = strtolower(trim((string) ($_POST['fuel_type'] ?? '')));
                $floatLimit = floatval($_POST['float_limit']);
                
                if (empty($vehicleName) || empty($numberPlate)) {
                    throw new Exception('Vehicle name and number plate are required');
                }

                if ($fuelType !== '' && !in_array($fuelType, ['petrol', 'diesel'], true)) {
                    throw new Exception('Fuel type must be petrol or diesel.');
                }
                
                // Update float account name
                $floatAccountName = $vehicleName . ' (' . $numberPlate . ')';
                
                $stmt = $conn->prepare("
                    UPDATE vehicles 
                    SET vehicle_name = ?, number_plate = ?, fuel_type = NULLIF(?, ''), float_account_name = ?, float_limit = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ssssdi", $vehicleName, $numberPlate, $fuelType, $floatAccountName, $floatLimit, $id);
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
$sortBy = $_GET['sort'] ?? 'vehicle_name';
$sortOrder = $_GET['order'] ?? 'ASC';

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
}

if ($search) {
    $query .= " AND (v.vehicle_name LIKE ? OR v.number_plate LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'ss';
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

// Get total count with facility filter
$countQuery = "SELECT COUNT(*) as count FROM vehicles WHERE 1=1";
if (!$is_super_admin && $user_facility_id) {
    $countQuery .= " AND facility_id = ?";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param("i", $user_facility_id);
    $countStmt->execute();
    $totalRow = $countStmt->get_result()->fetch_assoc();
} else {
    $totalRow = $conn->query($countQuery)->fetch_assoc();
}
$totalVehicles = $totalRow['count'];

// Fetch all users for assignment
$usersStmt = $conn->query("SELECT id, name, email FROM users ORDER BY name");
$users = $usersStmt->fetch_all(MYSQLI_ASSOC);

// Fetch all facilities (for super admin)
$facilitiesStmt = $conn->query("SELECT id, facility_name, facility_code FROM facilities WHERE is_active = 1 ORDER BY facility_name");
$facilities = $facilitiesStmt->fetch_all(MYSQLI_ASSOC);

// Get facility display name
if ($is_super_admin) {
    $facility_display = "All Facilities";
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

        /* Vehicle Details View */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .detail-card {
            background: var(--gray-50);
            padding: 20px;
            border-radius: 16px;
            border: 2px solid var(--gray-200);
        }

        .detail-card h4 {
            color: var(--gray-600);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-card .value {
            font-size: 24px;
            font-weight: 800;
            color: var(--gray-900);
        }

        .detail-card.highlight .value {
            color: var(--primary-red);
        }

        .section-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--gray-900);
            margin: 25px 0 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 10px;
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

            .details-grid {
                grid-template-columns: 1fr;
            }

            .vehicle-actions {
                flex-wrap: wrap;
            }

            .btn-small {
                flex: 1 1 45%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-car"></i> Vehicle Management
                    <span class="facility-badge <?php echo $is_super_admin ? 'super-admin' : ''; ?>">
                        <i class="fas fa-<?php echo $is_super_admin ? 'crown' : 'building'; ?>"></i> 
                        <?php echo htmlspecialchars($facility_display); ?>
                    </span>
                </h1>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button onclick="openAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Add New Vehicle
                    </button>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('vehicles')">
                    <i class="fas fa-car"></i> Vehicles
                </button>
                <button class="tab" onclick="switchTab('assignments')">
                    <i class="fas fa-user-check"></i> Vehicle Assignments
                </button>
            </div>
        </div>

        <!-- Vehicles Tab Content -->
        <div id="vehicles-tab" class="tab-content active">
            <!-- Stats -->
            <div class="stats">
                <div class="stat-card">
                    <i class="fas fa-car stat-icon"></i>
                    <h3><i class="fas fa-list"></i> Total Vehicles</h3>
                    <div class="stat-value"><?php echo $totalVehicles; ?></div>
                </div>
            </div>

            <!-- Controls -->
            <div class="controls">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input 
                        type="text" 
                        id="searchInput" 
                        placeholder="Search vehicles by name or plate number..." 
                        value="<?php echo htmlspecialchars($search); ?>"
                        onkeyup="handleSearch(event)">
                    <?php if ($search): ?>
                        <button class="clear-search" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <select class="sort-select" onchange="handleSort(this.value)">
                    <option value="">Sort by...</option>
                    <option value="vehicle_name_asc" <?php echo ($sortBy === 'vehicle_name' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Name (A-Z)</option>
                    <option value="vehicle_name_desc" <?php echo ($sortBy === 'vehicle_name' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Name (Z-A)</option>
                    <option value="number_plate_asc" <?php echo ($sortBy === 'number_plate' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Plate (A-Z)</option>
                    <option value="number_plate_desc" <?php echo ($sortBy === 'number_plate' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Plate (Z-A)</option>
                    <option value="created_at_desc" <?php echo ($sortBy === 'created_at' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_at_asc" <?php echo ($sortBy === 'created_at' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Oldest First</option>
                </select>
            </div>

            <!-- Vehicles Grid -->
            <?php if (count($vehicles) > 0): ?>
                <div class="vehicles-grid">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <?php $totalTrips = $vehicle['requisition_count'] + $vehicle['logbook_count']; ?>
                        <div class="vehicle-card" onclick='openViewModal(<?php echo json_encode($vehicle); ?>)'>
                            <div class="vehicle-icon-bg">
                                <i class="fas fa-car"></i>
                            </div>
                            
                            <?php if ($vehicle['driver_name']): ?>
                                <div class="driver-badge">
                                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($vehicle['driver_name']); ?>
                                </div>
                            <?php else: ?>
                                <div class="driver-badge no-driver-badge">
                                    <i class="fas fa-user-times"></i> Unassigned
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_super_admin && $vehicle['facility_name']): ?>
                                <div class="vehicle-facility">
                                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($vehicle['facility_name']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="vehicle-name"><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></div>
                            <div class="vehicle-plate">
                                <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($vehicle['number_plate']); ?>
                            </div>
                            
                            <div class="vehicle-info">
                                <div class="info-row">
                                    <span class="info-label">
                                        <i class="fas fa-gas-pump"></i> Fuel Type
                                    </span>
                                    <span class="info-value"><?php echo $vehicle['fuel_type'] ? htmlspecialchars(ucfirst($vehicle['fuel_type'])) : 'Not set'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">
                                        <i class="fas fa-road"></i> Total Trips
                                    </span>
                                    <span class="info-value"><?php echo $totalTrips; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">
                                        <i class="fas fa-tachometer-alt"></i> Mileage
                                    </span>
                                    <span class="info-value"><?php echo number_format($vehicle['current_mileage'], 2); ?> km</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">
                                        <i class="fas fa-wallet"></i> Float Balance
                                    </span>
                                    <span class="info-value">K <?php echo number_format($vehicle['float_balance'], 2); ?></span>
                                </div>
                            </div>

                            <div class="vehicle-actions" onclick="event.stopPropagation()">
                                <button onclick='openViewModal(<?php echo json_encode($vehicle); ?>)' class="btn-small btn-view">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick='openAssignModal(<?php echo json_encode($vehicle); ?>)' class="btn-small btn-assign">
                                    <i class="fas fa-user-check"></i> <?php echo $vehicle['driver_name'] ? 'Reassign' : 'Assign'; ?>
                                </button>
                                <button onclick='openEditModal(<?php echo json_encode($vehicle); ?>)' class="btn-small btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button onclick="confirmDelete(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_name'], ENT_QUOTES); ?>')" class="btn-small btn-delete">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-car"></i>
                    <h3>No Vehicles Found</h3>
                    <p>
                        <?php if ($search): ?>
                            No vehicles match your search criteria.
                        <?php else: ?>
                            Get started by adding your first vehicle to the system.
                        <?php endif; ?>
                    </p>
                    <?php if (!$search): ?>
                        <button onclick="openAddModal()" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Add Your First Vehicle
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assignments Tab Content -->
        <div id="assignments-tab" class="tab-content">
            <div class="assignment-table-container">
                <h2 style="margin-bottom: 20px; color: var(--gray-900); font-size: 24px; font-weight: 800;">
                    <i class="fas fa-user-check"></i> Active Vehicle Assignments
                </h2>
                
                <p style="color: var(--gray-600); margin-bottom: 25px; font-size: 14px;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Note:</strong> Drivers can be assigned to multiple vehicles. Each vehicle can only have one driver at a time.
                </p>
                
                <?php if (count($assignments) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Assigned Date</th>
                                <th>Vehicle</th>
                                <th>Number Plate</th>
                                <th>Driver</th>
                                <th>Assigned By</th>
                                <th>Notes</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($assignment['vehicle_name']); ?></strong></td>
                                    <td>
                                        <div style="background: var(--light-red); color: var(--primary-red); padding: 4px 10px; border-radius: 6px; display: inline-block; font-weight: 700; font-size: 12px;">
                                            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($assignment['number_plate']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 32px; height: 32px; background: #3b82f6; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;">
                                                <?php echo strtoupper(substr($assignment['driver_name'], 0, 1)); ?>
                                            </div>
                                            <strong><?php echo htmlspecialchars($assignment['driver_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($assignment['assigned_by_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['notes'] ?? '-'); ?></td>
                                    <td><span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span></td>
                                    <td>
                                        <button onclick="unassignVehicle(<?php echo $assignment['vehicle_id']; ?>, '<?php echo htmlspecialchars($assignment['vehicle_name'], ENT_QUOTES); ?>')" class="btn-small btn-delete" style="width: auto; padding: 8px 14px;">
                                            <i class="fas fa-user-times"></i> Unassign
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Summary Section -->
                    <div style="margin-top: 30px; padding: 20px; background: var(--gray-50); border-radius: 16px; border: 2px solid var(--gray-200);">
                        <h3 style="color: var(--gray-900); font-size: 18px; font-weight: 800; margin-bottom: 15px;">
                            <i class="fas fa-chart-bar"></i> Assignment Summary
                        </h3>
                        <?php 
                        // Group assignments by driver
                        $driverAssignments = [];
                        foreach ($assignments as $assignment) {
                            if (!isset($driverAssignments[$assignment['driver_name']])) {
                                $driverAssignments[$assignment['driver_name']] = [];
                            }
                            $driverAssignments[$assignment['driver_name']][] = $assignment['vehicle_name'] . ' (' . $assignment['number_plate'] . ')';
                        }
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            <?php foreach ($driverAssignments as $driverName => $vehicles): ?>
                                <div style="padding: 15px; background: white; border-radius: 12px; border: 2px solid var(--gray-200);">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                        <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--dark-red), var(--primary-red)); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px;">
                                            <?php echo strtoupper(substr($driverName, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 800; color: var(--gray-900);"><?php echo htmlspecialchars($driverName); ?></div>
                                            <div style="font-size: 12px; color: var(--gray-600); font-weight: 600;">
                                                <?php echo count($vehicles); ?> vehicle<?php echo count($vehicles) > 1 ? 's' : ''; ?> assigned
                                            </div>
                                        </div>
                                    </div>
                                    <div style="font-size: 13px; color: var(--gray-700); line-height: 1.6;">
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <div style="padding: 5px 0; border-top: 1px solid var(--gray-200); margin-top: 5px;">
                                                <i class="fas fa-car" style="color: var(--primary-red); margin-right: 6px;"></i>
                                                <?php echo htmlspecialchars($vehicle); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-times"></i>
                        <h3>No Active Assignments</h3>
                        <p>No vehicles are currently assigned to drivers.</p>
                        <button onclick="switchTab('vehicles')" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Go to Vehicles
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Vehicle Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h2 id="viewModalTitle"><i class="fas fa-car"></i> Vehicle Details</h2>
                <button class="close-modal-btn" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="details-grid">
                <div class="detail-card">
                    <h4><i class="fas fa-car"></i> Vehicle Name</h4>
                    <div class="value" id="viewVehicleName">-</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-id-card"></i> Number Plate</h4>
                    <div class="value" id="viewNumberPlate">-</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-gas-pump"></i> Fuel Type</h4>
                    <div class="value" id="viewFuelType">-</div>
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-road"></i> Trip Statistics
            </div>

            <div class="details-grid">
                <div class="detail-card highlight">
                    <h4><i class="fas fa-route"></i> Total Trips</h4>
                    <div class="value" id="viewTotalTrips">0</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-file-alt"></i> Requisitions</h4>
                    <div class="value" id="viewRequisitions">0</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-book"></i> Logbook Entries</h4>
                    <div class="value" id="viewLogbook">0</div>
                </div>
                <div class="detail-card highlight">
                    <h4><i class="fas fa-tachometer-alt"></i> Current Mileage</h4>
                    <div class="value" id="viewMileage">0 km</div>
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-wallet"></i> Float Account
            </div>

            <div class="details-grid">
                <div class="detail-card highlight">
                    <h4><i class="fas fa-money-bill-wave"></i> Current Balance</h4>
                    <div class="value" id="viewBalance">K 0.00</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-chart-line"></i> Float Limit</h4>
                    <div class="value" id="viewLimit">K 0.00</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-wallet"></i> Float Account Name</h4>
                    <div class="value" style="font-size: 16px;" id="viewFloatAccount">-</div>
                </div>
                <div class="detail-card">
                    <h4><i class="fas fa-calendar"></i> Date Added</h4>
                    <div class="value" style="font-size: 16px;" id="viewDateAdded">-</div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Vehicle Modal -->
    <div class="modal" id="vehicleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><i class="fas fa-car"></i> Add New Vehicle</h2>
                <button class="close-modal-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="vehicleForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="vehicle_id" id="vehicleId">
                
                <div class="form-group">
                    <label for="vehicleName">
                        <i class="fas fa-car"></i> Vehicle Name <span style="color: var(--primary-red);">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="vehicleName" 
                        name="vehicle_name" 
                        placeholder="e.g., Toyota Hilux, Ford Ranger"
                        required>
                </div>

                <div class="form-group">
                    <label for="numberPlate">
                        <i class="fas fa-id-card"></i> Number Plate <span style="color: var(--primary-red);">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="numberPlate" 
                        name="number_plate" 
                        placeholder="e.g., ABC 123X"
                        style="text-transform: uppercase;"
                        required>
                </div>

                <div class="form-group">
                    <label for="fuelType">
                        <i class="fas fa-gas-pump"></i> Fuel Type
                    </label>
                    <select id="fuelType" name="fuel_type">
                        <option value="">-- Select Fuel Type --</option>
                        <option value="petrol">Petrol</option>
                        <option value="diesel">Diesel</option>
                    </select>
                    <div class="form-hint">Used to validate fuel purchases and show the correct fuel type to drivers.</div>
                </div>

                <?php if ($is_super_admin): ?>
                <div class="form-group" id="facilityGroup">
                    <label for="facilitySelect">
                        <i class="fas fa-building"></i> Assign to Facility
                    </label>
                    <select 
                        id="facilitySelect" 
                        name="facility_id">
                        <option value="">-- No Facility (Unassigned) --</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>">
                                <?php echo htmlspecialchars($facility['facility_name']); ?> 
                                (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Super admins can assign vehicles to specific facilities or leave unassigned</div>
                </div>
                <?php endif; ?>

                <div class="form-group" id="initialMileageGroup">
                    <label for="initialMileage">
                        <i class="fas fa-tachometer-alt"></i> Initial Mileage (km)
                    </label>
                    <input 
                        type="number" 
                        id="initialMileage" 
                        name="initial_mileage" 
                        placeholder="0.00"
                        step="0.01"
                        min="0"
                        value="0">
                    <div class="form-hint">Starting odometer reading for this vehicle</div>
                </div>

                <div class="form-group">
                    <label for="floatLimit">
                        <i class="fas fa-chart-line"></i> Float Limit (K)
                    </label>
                    <input 
                        type="number" 
                        id="floatLimit" 
                        name="float_limit" 
                        placeholder="10000.00"
                        step="0.01"
                        min="0"
                        value="10000.00">
                    <div class="form-hint">Maximum float balance allowed</div>
                </div>

                <div class="form-group" id="initialBalanceGroup">
                    <label for="initialBalance">
                        <i class="fas fa-money-bill-wave"></i> Initial Balance (K)
                    </label>
                    <input 
                        type="number" 
                        id="initialBalance" 
                        name="initial_balance" 
                        placeholder="0.00"
                        step="0.01"
                        min="0"
                        value="0">
                    <div class="form-hint">Starting float balance (optional)</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> <span id="submitText">Add Vehicle</span>
                    </button>
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
        // Tab switching
        function switchTab(tabName) {
            // Remove active from all tabs and content
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            // Add active to selected tab and content
            event.target.classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
        }

        function openViewModal(vehicle) {
            const totalTrips = vehicle.requisition_count + vehicle.logbook_count;
            
            document.getElementById('viewVehicleName').textContent = vehicle.vehicle_name;
            document.getElementById('viewNumberPlate').textContent = vehicle.number_plate;
            document.getElementById('viewFuelType').textContent = vehicle.fuel_type ? vehicle.fuel_type.charAt(0).toUpperCase() + vehicle.fuel_type.slice(1) : '-';
            document.getElementById('viewTotalTrips').textContent = totalTrips;
            document.getElementById('viewRequisitions').textContent = vehicle.requisition_count;
            document.getElementById('viewLogbook').textContent = vehicle.logbook_count;
            document.getElementById('viewMileage').textContent = parseFloat(vehicle.current_mileage).toFixed(2) + ' km';
            document.getElementById('viewBalance').textContent = 'K ' + parseFloat(vehicle.float_balance).toFixed(2);
            document.getElementById('viewLimit').textContent = 'K ' + parseFloat(vehicle.float_limit).toFixed(2);
            document.getElementById('viewFloatAccount').textContent = vehicle.float_account_name || '-';
            document.getElementById('viewDateAdded').textContent = new Date(vehicle.created_at).toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Vehicle';
            document.getElementById('formAction').value = 'add';
            document.getElementById('submitText').textContent = 'Add Vehicle';
            document.getElementById('vehicleForm').reset();
            document.getElementById('floatLimit').value = '10000.00';
            document.getElementById('initialBalance').value = '0';
            document.getElementById('initialMileage').value = '0';
            document.getElementById('fuelType').value = '';
            
            document.getElementById('initialBalanceGroup').style.display = 'block';
            document.getElementById('initialMileageGroup').style.display = 'block';
            
            document.getElementById('vehicleModal').classList.add('active');
        }

        function openEditModal(vehicle) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Vehicle';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('submitText').textContent = 'Update Vehicle';
            document.getElementById('vehicleId').value = vehicle.id;
            document.getElementById('vehicleName').value = vehicle.vehicle_name;
            document.getElementById('numberPlate').value = vehicle.number_plate;
            document.getElementById('fuelType').value = vehicle.fuel_type || '';
            document.getElementById('floatLimit').value = parseFloat(vehicle.float_limit).toFixed(2);
            
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

        function handleSearch(event) {
            if (event.key === 'Enter' || event.type === 'click') {
                const search = document.getElementById('searchInput').value;
                const url = new URL(window.location.href);
                if (search) {
                    url.searchParams.set('search', search);
                } else {
                    url.searchParams.delete('search');
                }
                window.location.href = url.toString();
            }
        }

        function clearSearch() {
            const url = new URL(window.location.href);
            url.searchParams.delete('search');
            window.location.href = url.toString();
        }

        function handleSort(value) {
            if (!value) return;
            
            const [sortBy, order] = value.split('_');
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortBy);
            url.searchParams.set('order', order.toUpperCase());
            window.location.href = url.toString();
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
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.style.animation = 'slideDown 0.3s reverse';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
