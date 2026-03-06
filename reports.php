<?php
// reports.php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'staff';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
$isFacilityAdmin = $_SESSION['is_facility_admin'] ?? false;

// Get user's facility
$facilityStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
$facilityStmt->execute([$userId]);
$userFacility = $facilityStmt->fetchColumn();

// Check if user is a facility admin for specific facilities
$facilityAdminFor = [];
if ($isFacilityAdmin) {
    $faStmt = $pdo->prepare("SELECT facility_id FROM facility_admins WHERE user_id = ? AND is_active = 1");
    $faStmt->execute([$userId]);
    $facilityAdminFor = $faStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Determine accessible facilities
$accessibleFacilities = [];
if ($isSuperAdmin) {
    // Super admin can see all facilities
    $facilitiesStmt = $pdo->query("SELECT id, facility_name, facility_code FROM facilities WHERE is_active = 1 ORDER BY facility_name");
    $accessibleFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
    // Facility admin can see their assigned facilities
    $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
    $facilitiesStmt = $pdo->prepare("SELECT id, facility_name, facility_code FROM facilities WHERE id IN ($placeholders) AND is_active = 1 ORDER BY facility_name");
    $facilitiesStmt->execute($facilityAdminFor);
    $accessibleFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($userFacility) {
    // Regular user can only see their own facility
    $facilitiesStmt = $pdo->prepare("SELECT id, facility_name, facility_code FROM facilities WHERE id = ? AND is_active = 1");
    $facilitiesStmt->execute([$userFacility]);
    $accessibleFacilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get filter parameters
$reportType = $_GET['type'] ?? 'price_history';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedUser = $_GET['user_id'] ?? '';
$selectedVehicle = $_GET['vehicle_id'] ?? '';
$selectedAccount = $_GET['account'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$selectedFacility = $_GET['facility_id'] ?? '';

// If user is not super admin and no facility selected, default to their facility
if (!$isSuperAdmin && empty($selectedFacility)) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        $selectedFacility = $facilityAdminFor[0]; // Default to first assigned facility
    } elseif ($userFacility) {
        $selectedFacility = $userFacility;
    }
}

// Build facility filter for queries
$facilityFilter = '';
$facilityParam = null;
if (!$isSuperAdmin) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        if ($selectedFacility && in_array($selectedFacility, $facilityAdminFor)) {
            $facilityFilter = ' AND facility_id = ?';
            $facilityParam = $selectedFacility;
        } else {
            $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
            $facilityFilter = " AND facility_id IN ($placeholders)";
        }
    } elseif ($userFacility) {
        $facilityFilter = ' AND facility_id = ?';
        $facilityParam = $userFacility;
    }
}

// Fetch users for filter (filtered by facility)
$userQuery = "SELECT id, name FROM users WHERE 1=1";
$userParams = [];
if (!$isSuperAdmin) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        if ($selectedFacility) {
            $userQuery .= " AND facility_id = ?";
            $userParams[] = $selectedFacility;
        } else {
            $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
            $userQuery .= " AND facility_id IN ($placeholders)";
            $userParams = $facilityAdminFor;
        }
    } elseif ($userFacility) {
        $userQuery .= " AND facility_id = ?";
        $userParams[] = $userFacility;
    }
}
$userQuery .= " ORDER BY name";
$usersStmt = $pdo->prepare($userQuery);
$usersStmt->execute($userParams);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch vehicles for filter (filtered by facility)
$vehicleQuery = "SELECT id, vehicle_name, number_plate FROM vehicles WHERE 1=1";
$vehicleParams = [];
if (!$isSuperAdmin) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        if ($selectedFacility) {
            $vehicleQuery .= " AND facility_id = ?";
            $vehicleParams[] = $selectedFacility;
        } else {
            $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
            $vehicleQuery .= " AND facility_id IN ($placeholders)";
            $vehicleParams = $facilityAdminFor;
        }
    } elseif ($userFacility) {
        $vehicleQuery .= " AND facility_id = ?";
        $vehicleParams[] = $userFacility;
    }
}
$vehicleQuery .= " ORDER BY vehicle_name";
$vehiclesStmt = $pdo->prepare($vehicleQuery);
$vehiclesStmt->execute($vehicleParams);
$vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch float accounts from vehicles (filtered by facility)
$accountQuery = "SELECT DISTINCT float_account_name FROM vehicles WHERE float_account_name IS NOT NULL";
$accountParams = [];
if (!$isSuperAdmin) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        if ($selectedFacility) {
            $accountQuery .= " AND facility_id = ?";
            $accountParams[] = $selectedFacility;
        } else {
            $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
            $accountQuery .= " AND facility_id IN ($placeholders)";
            $accountParams = $facilityAdminFor;
        }
    } elseif ($userFacility) {
        $accountQuery .= " AND facility_id = ?";
        $accountParams[] = $userFacility;
    }
}
$accountQuery .= " ORDER BY float_account_name";
$accountsStmt = $pdo->prepare($accountQuery);
$accountsStmt->execute($accountParams);
$floatAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

// Generate report based on type
$reportData = [];
$reportStats = [];

switch ($reportType) {
    case 'price_history':
        // Price history - super admin sees all, others see global data (no facility filter)
        $reportTitle = 'Price Change History';
        $stmt = $pdo->prepare("
            SELECT * FROM fuel_price_history 
            WHERE DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_changes' => count($reportData),
            'avg_price' => count($reportData) > 0 ? array_sum(array_column($reportData, 'new_price')) / count($reportData) : 0,
            'highest_price' => count($reportData) > 0 ? max(array_column($reportData, 'new_price')) : 0,
            'lowest_price' => count($reportData) > 0 ? min(array_column($reportData, 'new_price')) : 0
        ];
        break;
        
    case 'float_adjustments':
        // Float adjustments - filter by facility for non-super admins
        $reportTitle = 'Float Adjustment History';
        
        // Get vehicles from accessible facilities to filter float accounts
        $facilityVehicles = [];
        if (!$isSuperAdmin) {
            $vQuery = "SELECT DISTINCT float_account_name FROM vehicles WHERE float_account_name IS NOT NULL";
            $vParams = [];
            
            if ($isFacilityAdmin && !empty($facilityAdminFor)) {
                if ($selectedFacility && in_array($selectedFacility, $facilityAdminFor)) {
                    $vQuery .= " AND facility_id = ?";
                    $vParams[] = $selectedFacility;
                } else {
                    $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
                    $vQuery .= " AND facility_id IN ($placeholders)";
                    $vParams = $facilityAdminFor;
                }
            } elseif ($userFacility) {
                $vQuery .= " AND facility_id = ?";
                $vParams[] = $userFacility;
            }
            
            $vStmt = $pdo->prepare($vQuery);
            $vStmt->execute($vParams);
            $facilityVehicles = $vStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $query = "
            SELECT * FROM float_transactions 
            WHERE transaction_type IN ('addition', 'deduction', 'adjustment')
            AND DATE(created_at) BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        // Filter by facility vehicles if not super admin
        if (!$isSuperAdmin && !empty($facilityVehicles)) {
            $placeholders = str_repeat('?,', count($facilityVehicles) - 1) . '?';
            $query .= " AND float_account IN ($placeholders)";
            $params = array_merge($params, $facilityVehicles);
        }
        
        if ($selectedAccount) {
            $query .= " AND float_account = ?";
            $params[] = $selectedAccount;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalAdditions = array_sum(array_map(function($r) {
            return $r['transaction_type'] === 'addition' ? $r['amount'] : 0;
        }, $reportData));
        
        $totalDeductions = array_sum(array_map(function($r) {
            return $r['transaction_type'] === 'deduction' ? $r['amount'] : 0;
        }, $reportData));
        
        $reportStats = [
            'total_transactions' => count($reportData),
            'total_additions' => $totalAdditions,
            'total_deductions' => $totalDeductions,
            'net_change' => $totalAdditions - $totalDeductions
        ];
        break;
        
    case 'vehicle_activity':
        // MERGED REPORT: Requisitions + Logbook
        $query = "
            SELECT 
                'requisition' as activity_type,
                r.id,
                r.request_date as activity_date,
                r.request_time as activity_time,
                u.name as staff_name,
                v.vehicle_name,
                v.number_plate,
                f.facility_name,
                r.requested_amount as fuel_amount,
                (r.requested_amount * r.fuel_price_per_liter) as total_cost,
                r.activity_name,
                r.filling_station,
                r.status,
                NULL as purpose,
                NULL as route,
                NULL as distance,
                NULL as approver_name
            FROM requisitions r
            JOIN users u ON r.staff_id = u.id
            JOIN vehicles v ON r.vehicle_id = v.id
            LEFT JOIN facilities f ON r.facility_id = f.id
            WHERE DATE(r.request_date) BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        // Add facility filter for requisitions
        if ($facilityFilter) {
            $query = str_replace('WHERE DATE(r.request_date)', 'WHERE r.facility_id IS NOT NULL ' . str_replace('facility_id', 'r.facility_id', $facilityFilter) . ' AND DATE(r.request_date)', $query);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedUser) {
            $query .= " AND r.staff_id = ?";
            $params[] = $selectedUser;
        }
        
        if ($selectedVehicle) {
            $query .= " AND r.vehicle_id = ?";
            $params[] = $selectedVehicle;
        }
        
        if ($selectedStatus) {
            $query .= " AND r.status = ?";
            $params[] = $selectedStatus;
        }
        
        if ($selectedFacility) {
            $query .= " AND r.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= "
            UNION ALL
            
            SELECT 
                'logbook' as activity_type,
                l.id,
                l.log_date as activity_date,
                l.time_out as activity_time,
                d.name as staff_name,
                v.vehicle_name,
                v.number_plate,
                f.facility_name,
                NULL as fuel_amount,
                NULL as total_cost,
                NULL as activity_name,
                NULL as filling_station,
                NULL as status,
                l.purpose,
                CONCAT(l.location_from, ' → ', l.location_to) as route,
                l.total_kms as distance,
                a.name as approver_name
            FROM logbook l
            JOIN vehicles v ON l.vehicle_id = v.id
            JOIN users d ON l.driver_id = d.id
            LEFT JOIN users a ON l.approver_id = a.id
            LEFT JOIN facilities f ON l.facility_id = f.id
            WHERE DATE(l.log_date) BETWEEN ? AND ?
        ";
        
        $params[] = $startDate;
        $params[] = $endDate;
        
        // Add facility filter for logbook
        if ($facilityFilter) {
            $query .= ' AND l.facility_id IS NOT NULL ' . str_replace('facility_id', 'l.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedUser) {
            $query .= " AND l.driver_id = ?";
            $params[] = $selectedUser;
        }
        
        if ($selectedVehicle) {
            $query .= " AND l.vehicle_id = ?";
            $params[] = $selectedVehicle;
        }
        
        if ($selectedFacility) {
            $query .= " AND l.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " ORDER BY activity_date DESC, activity_time DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate stats
        $requisitions = array_filter($reportData, fn($r) => $r['activity_type'] === 'requisition');
        $logbookEntries = array_filter($reportData, fn($r) => $r['activity_type'] === 'logbook');
        
        $reportStats = [
            'total_activities' => count($reportData),
            'total_requisitions' => count($requisitions),
            'total_logbook_entries' => count($logbookEntries),
            'total_fuel' => array_sum(array_column($requisitions, 'fuel_amount')),
            'total_cost' => array_sum(array_column($requisitions, 'total_cost')),
            'total_distance' => array_sum(array_column($logbookEntries, 'distance')),
            'approved' => count(array_filter($requisitions, fn($r) => $r['status'] === 'approved')),
            'pending' => count(array_filter($requisitions, fn($r) => $r['status'] === 'pending')),
            'rejected' => count(array_filter($requisitions, fn($r) => $r['status'] === 'rejected'))
        ];
        break;
        
    case 'vehicle_consumption':
        $query = "
            SELECT v.vehicle_name, v.number_plate, f.facility_name,
                   COUNT(r.id) as trip_count,
                   COALESCE(SUM(r.requested_amount), 0) as total_liters,
                   COALESCE(SUM(r.requested_amount * r.fuel_price_per_liter), 0) as total_cost,
                   COALESCE(AVG(r.requested_amount), 0) as avg_liters
            FROM vehicles v
            LEFT JOIN facilities f ON v.facility_id = f.id
            LEFT JOIN requisitions r ON v.id = r.vehicle_id 
                AND r.status = 'approved'
                AND DATE(r.request_date) BETWEEN ? AND ?
            WHERE 1=1
        ";
        
        $params = [$startDate, $endDate];
        
        // Add facility filter
        if ($facilityFilter) {
            $query .= str_replace('facility_id', 'v.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedFacility) {
            $query .= " AND v.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " GROUP BY v.id, v.vehicle_name, v.number_plate, f.facility_name
                    ORDER BY total_liters DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_vehicles' => count($reportData),
            'total_consumption' => array_sum(array_column($reportData, 'total_liters')),
            'total_cost' => array_sum(array_column($reportData, 'total_cost')),
            'avg_per_vehicle' => count($reportData) > 0 ? array_sum(array_column($reportData, 'total_liters')) / count($reportData) : 0
        ];
        break;
        
    case 'user_consumption':
        $query = "
            SELECT u.name, f.facility_name,
                   COUNT(r.id) as trip_count,
                   COALESCE(SUM(r.requested_amount), 0) as total_liters,
                   COALESCE(SUM(r.requested_amount * r.fuel_price_per_liter), 0) as total_cost,
                   COALESCE(AVG(r.requested_amount), 0) as avg_liters
            FROM users u
            LEFT JOIN facilities f ON u.facility_id = f.id
            LEFT JOIN requisitions r ON u.id = r.staff_id 
                AND r.status = 'approved'
                AND DATE(r.request_date) BETWEEN ? AND ?
            WHERE 1=1
        ";
        
        $params = [$startDate, $endDate];
        
        // Add facility filter
        if ($facilityFilter) {
            $query .= str_replace('facility_id', 'u.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedFacility) {
            $query .= " AND u.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " GROUP BY u.id, u.name, f.facility_name
                    HAVING trip_count > 0
                    ORDER BY total_liters DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_users' => count($reportData),
            'total_consumption' => array_sum(array_column($reportData, 'total_liters')),
            'total_cost' => array_sum(array_column($reportData, 'total_cost')),
            'avg_per_user' => count($reportData) > 0 ? array_sum(array_column($reportData, 'total_liters')) / count($reportData) : 0
        ];
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Fuel Liquidation System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-red: #dc2626;
            --dark-red: #991b1b;
            --light-red: #fef2f2;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f5f5 0%, #e5e7eb 50%, #fee2e2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--gray-900);
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .facility-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--light-red);
            border: 2px solid var(--primary-red);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-red);
            margin-left: 15px;
        }

        .back-btn {
            padding: 10px 20px;
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .back-btn:hover {
            background: white;
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        /* Report Type Tabs */
        .report-tabs {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
        }

        .tabs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .tab-btn {
            padding: 14px 20px;
            background: white;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--gray-700);
            text-decoration: none;
        }

        .tab-btn:hover {
            border-color: var(--primary-red);
            background: var(--light-red);
            transform: translateY(-2px);
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            color: white;
            border-color: var(--primary-red);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .tab-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Filters */
        .filters-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
            width: 50%;
        }

        .filters-title {
            color: var(--gray-900);
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--gray-700);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 14px;
            border: 2px solid var(--gray-300);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            background: white;
            width: 100%;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        /* Keep date inputs compact on desktop so the picker icon is closer to the value */
        .filter-group.date-filter {
            max-width: 100%;
        }

        .filter-group.date-filter input[type="date"] {
            width: 100%;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        .btn-filter {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
        }

        .btn-clear {
            padding: 10px 24px;
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-clear:hover {
            background: var(--gray-100);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-red), #ef4444);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--light-red);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .stat-icon i {
            font-size: 22px;
            color: var(--primary-red);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--gray-900);
        }

        /* Export Actions */
        .export-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .btn-export {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-pdf {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-excel {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-print {
            background: linear-gradient(135deg, var(--gray-600), var(--gray-700));
            color: white;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Table */
        .report-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
            position: relative;
            overflow: hidden;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-red), #ef4444);
        }

        .report-title {
            color: var(--gray-900);
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-container {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .report-table th {
            background: var(--gray-100);
            padding: 12px 14px;
            text-align: left;
            font-weight: 700;
            color: var(--gray-700);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .report-table td {
            padding: 12px 14px;
            color: var(--gray-900);
            font-size: 13px;
            font-weight: 500;
            background: white;
            border-bottom: 1px solid var(--gray-200);
        }

        .report-table tbody tr:hover td {
            background: var(--light-red);
        }

        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-requisition {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }

        .badge-logbook {
            background: rgba(139, 92, 246, 0.2);
            color: #8b5cf6;
        }

        .badge-approved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .badge-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }

        .badge-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .badge-addition {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .badge-deduction {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .badge-adjustment {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }

        .price-change {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .price-old {
            color: var(--gray-500);
            text-decoration: line-through;
            font-size: 12px;
        }

        .price-new {
            color: var(--primary-red);
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--gray-300);
            margin-bottom: 15px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid #ef4444;
            color: #991b1b;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .header h1 {
                font-size: 22px;
            }

            .facility-badge {
                margin-left: 0;
                margin-top: 10px;
            }

            .back-btn {
                width: 100%;
                justify-content: center;
            }

            .tabs-grid {
                grid-template-columns: 1fr;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filters-card {
                width: 100%;
                max-width: 100%;
            }

            .filter-group.date-filter {
                max-width: 100%;
            }

            .filter-group.date-filter input[type="date"] {
                width: 100%;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn-filter,
            .btn-clear {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .export-actions {
                flex-direction: column;
            }

            .btn-export {
                width: 100%;
                justify-content: center;
            }
        }

        @media print {
            body {
                background: white;
            }
            .header, .report-tabs, .filters-card, .export-actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div>
                    <h1>
                        <i class="fas fa-chart-bar"></i> Reports Dashboard
                        <?php if ($selectedFacility && count($accessibleFacilities) > 0): ?>
                            <?php 
                            $currentFacility = array_filter($accessibleFacilities, fn($f) => $f['id'] == $selectedFacility);
                            if (!empty($currentFacility)):
                                $currentFacility = reset($currentFacility);
                            ?>
                                <span class="facility-badge">
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($currentFacility['facility_name']); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </h1>
                </div>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Report Type Tabs -->
        <div class="report-tabs">
            <div class="tabs-grid">
                <a href="?type=price_history&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?><?php echo $selectedFacility ? '&facility_id='.$selectedFacility : ''; ?>" 
                   class="tab-btn <?php echo $reportType === 'price_history' ? 'active' : ''; ?>">
                    <i class="fas fa-tag"></i> Price Changes
                </a>
                <a href="?type=float_adjustments&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?><?php echo $selectedFacility ? '&facility_id='.$selectedFacility : ''; ?>" 
                   class="tab-btn <?php echo $reportType === 'float_adjustments' ? 'active' : ''; ?>">
                    <i class="fas fa-coins"></i> Float Adjustments
                </a>
                <a href="?type=vehicle_activity&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?><?php echo $selectedFacility ? '&facility_id='.$selectedFacility : ''; ?>" 
                   class="tab-btn <?php echo $reportType === 'vehicle_activity' ? 'active' : ''; ?>">
                    <i class="fas fa-list-alt"></i> Vehicle Activity
                </a>
                <a href="?type=vehicle_consumption&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?><?php echo $selectedFacility ? '&facility_id='.$selectedFacility : ''; ?>" 
                   class="tab-btn <?php echo $reportType === 'vehicle_consumption' ? 'active' : ''; ?>">
                    <i class="fas fa-car"></i> Vehicle Consumption
                </a>
                <a href="?type=user_consumption&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?><?php echo $selectedFacility ? '&facility_id='.$selectedFacility : ''; ?>" 
                   class="tab-btn <?php echo $reportType === 'user_consumption' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> User Consumption
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-title">
                <i class="fas fa-filter"></i> Filters
            </div>
            <form method="GET" action="">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($reportType); ?>">
                
                <div class="filters-grid">
                    <?php if (count($accessibleFacilities) > 1): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-building"></i> Facility</label>
                        <select name="facility_id">
                            <option value="">All Facilities</option>
                            <?php foreach ($accessibleFacilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>" <?php echo $selectedFacility == $facility['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($facility['facility_name'] . ' (' . $facility['facility_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group date-filter">
                        <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                        <input type="date" name="start_date" value="<?php echo $startDate; ?>" required>
                    </div>
                    
                    <div class="filter-group date-filter">
                        <label><i class="fas fa-calendar-check"></i> End Date</label>
                        <input type="date" name="end_date" value="<?php echo $endDate; ?>" required>
                    </div>

                    <?php if (in_array($reportType, ['vehicle_activity', 'user_consumption'])): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> User</label>
                        <select name="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($reportType === 'vehicle_activity'): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-car"></i> Vehicle</label>
                        <select name="vehicle_id">
                            <option value="">All Vehicles</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>" <?php echo $selectedVehicle == $vehicle['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehicle['vehicle_name'] . ' (' . $vehicle['number_plate'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-info-circle"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $selectedStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $selectedStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="rejected" <?php echo $selectedStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($reportType === 'float_adjustments'): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-wallet"></i> Float Account</label>
                        <select name="account">
                            <option value="">All Accounts</option>
                            <?php foreach ($floatAccounts as $account): ?>
                                <option value="<?php echo htmlspecialchars($account['float_account_name']); ?>" 
                                        <?php echo $selectedAccount === $account['float_account_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['float_account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        Generate Report
                    </button>
                    <a href="?type=<?php echo $reportType; ?>" class="btn-clear" style="text-decoration: none; display: flex; align-items: center; justify-content: center;">
                        Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Stats Cards -->
        <?php if (!empty($reportStats)): ?>
        <div class="stats-grid">
            <?php 
            switch ($reportType) {
                case 'price_history':
                    ?>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                        <div class="stat-label">Total Changes</div>
                        <div class="stat-value"><?php echo $reportStats['total_changes']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-label">Average Price</div>
                        <div class="stat-value">K <?php echo number_format($reportStats['avg_price'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                        <div class="stat-label">Highest Price</div>
                        <div class="stat-value">K <?php echo number_format($reportStats['highest_price'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                        <div class="stat-label">Lowest Price</div>
                        <div class="stat-value">K <?php echo number_format($reportStats['lowest_price'], 2); ?></div>
                    </div>
                    <?php
                    break;
                
                case 'float_adjustments':
                    ?>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-list"></i></div>
                        <div class="stat-label">Total Transactions</div>
                        <div class="stat-value"><?php echo $reportStats['total_transactions']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-plus-circle"></i></div>
                        <div class="stat-label">Total Additions</div>
                        <div class="stat-value"><?php echo number_format($reportStats['total_additions'], 2); ?> L</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-minus-circle"></i></div>
                        <div class="stat-label">Total Deductions</div>
                        <div class="stat-value"><?php echo number_format($reportStats['total_deductions'], 2); ?> L</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
                        <div class="stat-label">Net Change</div>
                        <div class="stat-value" style="color: <?php echo $reportStats['net_change'] >= 0 ? '#10b981' : '#ef4444'; ?>">
                            <?php echo $reportStats['net_change'] >= 0 ? '+' : ''; ?><?php echo number_format($reportStats['net_change'], 2); ?> L
                        </div>
                    </div>
                    <?php
                    break;

                case 'vehicle_activity':
                    ?>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-list-alt"></i></div>
                        <div class="stat-label">Total Activities</div>
                        <div class="stat-value"><?php echo $reportStats['total_activities']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-label">Requisitions</div>
                        <div class="stat-value"><?php echo $reportStats['total_requisitions']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-book"></i></div>
                        <div class="stat-label">Logbook Entries</div>
                        <div class="stat-value"><?php echo $reportStats['total_logbook_entries']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-gas-pump"></i></div>
                        <div class="stat-label">Total Fuel</div>
                        <div class="stat-value"><?php echo number_format($reportStats['total_fuel'], 2); ?> L</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="stat-label">Total Cost</div>
                        <div class="stat-value">K <?php echo number_format($reportStats['total_cost'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-tachometer-alt"></i></div>
                        <div class="stat-label">Total Distance</div>
                        <div class="stat-value"><?php echo number_format($reportStats['total_distance'], 2); ?> km</div>
                    </div>
                    <?php
                    break;

                case 'vehicle_consumption':
                case 'user_consumption':
                    ?>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-<?php echo $reportType === 'vehicle_consumption' ? 'car' : 'users'; ?>"></i></div>
                        <div class="stat-label">Total <?php echo $reportType === 'vehicle_consumption' ? 'Vehicles' : 'Users'; ?></div>
                        <div class="stat-value"><?php echo $reportStats['total_' . ($reportType === 'vehicle_consumption' ? 'vehicles' : 'users')]; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-gas-pump"></i></div>
                        <div class="stat-label">Total Consumption</div>
                        <div class="stat-value"><?php echo number_format($reportStats['total_consumption'], 2); ?> L</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="stat-label">Total Cost</div>
                        <div class="stat-value">K <?php echo number_format($reportStats['total_cost'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="stat-label">Average Per <?php echo $reportType === 'vehicle_consumption' ? 'Vehicle' : 'User'; ?></div>
                        <div class="stat-value"><?php echo number_format($reportStats['avg_per_' . ($reportType === 'vehicle_consumption' ? 'vehicle' : 'user')], 2); ?> L</div>
                    </div>
                    <?php
                    break;
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- Export Actions -->
        <div class="export-actions">
            <button onclick="window.print()" class="btn-export btn-print">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="export_report_pdf.php?<?php echo http_build_query($_GET); ?>" class="btn-export btn-pdf">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
            <a href="export_report_excel.php?<?php echo http_build_query($_GET); ?>" class="btn-export btn-excel">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>

        <!-- Report Table -->
        <div class="report-card">
            <div class="report-title">
                <i class="fas fa-table"></i>
                <?php
                $titles = [
                    'price_history' => 'Price Change History',
                    'float_adjustments' => 'Float Adjustment History',
                    'vehicle_activity' => 'Vehicle Activity Report (Requisitions & Logbook)',
                    'vehicle_consumption' => 'Vehicle Fuel Consumption',
                    'user_consumption' => 'User Fuel Consumption'
                ];
                echo $titles[$reportType] ?? 'Report';
                ?>
            </div>

            <?php if (count($reportData) > 0): ?>
                <div class="table-container">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <?php
                                switch ($reportType) {
                                    case 'price_history':
                                        echo '<th><i class="fas fa-calendar"></i> Date & Time</th>';
                                        echo '<th><i class="fas fa-exchange-alt"></i> Price Change</th>';
                                        echo '<th><i class="fas fa-percentage"></i> % Change</th>';
                                        echo '<th><i class="fas fa-user"></i> Changed By</th>';
                                        echo '<th><i class="fas fa-comment"></i> Reason</th>';
                                        break;
                                    case 'float_adjustments':
                                        echo '<th><i class="fas fa-calendar"></i> Date & Time</th>';
                                        echo '<th><i class="fas fa-wallet"></i> Account</th>';
                                        echo '<th><i class="fas fa-exchange-alt"></i> Type</th>';
                                        echo '<th><i class="fas fa-droplet"></i> Amount (L)</th>';
                                        echo '<th><i class="fas fa-chart-line"></i> New Balance (L)</th>';
                                        echo '<th><i class="fas fa-comment"></i> Reason</th>';
                                        echo '<th><i class="fas fa-user"></i> By</th>';
                                        break;
                                    case 'vehicle_activity':
                                        echo '<th><i class="fas fa-tag"></i> Type</th>';
                                        echo '<th><i class="fas fa-calendar"></i> Date</th>';
                                        echo '<th><i class="fas fa-user"></i> Staff/Driver</th>';
                                        echo '<th><i class="fas fa-car"></i> Vehicle</th>';
                                        if (count($accessibleFacilities) > 1) {
                                            echo '<th><i class="fas fa-building"></i> Facility</th>';
                                        }
                                        echo '<th><i class="fas fa-tasks"></i> Activity/Purpose</th>';
                                        echo '<th><i class="fas fa-gas-pump"></i> Fuel (L)</th>';
                                        echo '<th><i class="fas fa-money-bill-wave"></i> Cost (K)</th>';
                                        echo '<th><i class="fas fa-tachometer-alt"></i> Distance (km)</th>';
                                        echo '<th><i class="fas fa-info-circle"></i> Status/Approver</th>';
                                        break;
                                    case 'vehicle_consumption':
                                        echo '<th><i class="fas fa-car"></i> Vehicle</th>';
                                        if (count($accessibleFacilities) > 1) {
                                            echo '<th><i class="fas fa-building"></i> Facility</th>';
                                        }
                                        echo '<th><i class="fas fa-route"></i> Trips</th>';
                                        echo '<th><i class="fas fa-gas-pump"></i> Total Liters</th>';
                                        echo '<th><i class="fas fa-chart-bar"></i> Avg/Trip</th>';
                                        echo '<th><i class="fas fa-money-bill-wave"></i> Total Cost (K)</th>';
                                        break;
                                    case 'user_consumption':
                                        echo '<th><i class="fas fa-user"></i> User</th>';
                                        if (count($accessibleFacilities) > 1) {
                                            echo '<th><i class="fas fa-building"></i> Facility</th>';
                                        }
                                        echo '<th><i class="fas fa-route"></i> Trips</th>';
                                        echo '<th><i class="fas fa-gas-pump"></i> Total Liters</th>';
                                        echo '<th><i class="fas fa-chart-bar"></i> Avg/Trip</th>';
                                        echo '<th><i class="fas fa-money-bill-wave"></i> Total Cost (K)</th>';
                                        break;
                                }
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                                <tr>
                                    <?php
                                    switch ($reportType) {
                                        case 'price_history':
                                            $change = $row['new_price'] - $row['old_price'];
                                            $changePercent = (($change / $row['old_price']) * 100);
                                            echo '<td>' . date('d M Y, h:i A', strtotime($row['created_at'])) . '</td>';
                                            echo '<td><div class="price-change">';
                                            echo '<span class="price-old">K ' . number_format($row['old_price'], 2) . '</span>';
                                            echo '<i class="fas fa-arrow-right" style="color: var(--gray-400);"></i>';
                                            echo '<span class="price-new">K ' . number_format($row['new_price'], 2) . '</span>';
                                            echo '</div></td>';
                                            echo '<td><span class="badge badge-' . ($change > 0 ? 'rejected' : 'approved') . '">';
                                            echo '<i class="fas fa-arrow-' . ($change > 0 ? 'up' : 'down') . '"></i> ';
                                            echo number_format(abs($changePercent), 2) . '%</span></td>';
                                            echo '<td>' . htmlspecialchars($row['changed_by']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['reason']) . '</td>';
                                            break;
                                        case 'float_adjustments':
                                            echo '<td>' . date('d M Y, h:i A', strtotime($row['created_at'])) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['float_account']) . '</td>';
                                            echo '<td><span class="badge badge-' . $row['transaction_type'] . '">';
                                            echo '<i class="fas fa-' . ($row['transaction_type'] === 'addition' ? 'plus' : ($row['transaction_type'] === 'deduction' ? 'minus' : 'cog')) . '-circle"></i> ';
                                            echo ucfirst($row['transaction_type']) . '</span></td>';
                                            echo '<td>' . number_format($row['amount'], 2) . '</td>';
                                            echo '<td><strong>' . number_format($row['new_balance'], 2) . '</strong></td>';
                                            echo '<td>' . htmlspecialchars($row['reason']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['created_by']) . '</td>';
                                            break;
                                        case 'vehicle_activity':
                                            echo '<td><span class="badge badge-' . $row['activity_type'] . '">';
                                            echo '<i class="fas fa-' . ($row['activity_type'] === 'requisition' ? 'file-alt' : 'book') . '"></i> ';
                                            echo ucfirst($row['activity_type']) . '</span></td>';
                                            echo '<td>' . date('d M Y', strtotime($row['activity_date'])) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['staff_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['vehicle_name'] . ' (' . $row['number_plate'] . ')') . '</td>';
                                            if (count($accessibleFacilities) > 1) {
                                                echo '<td>' . htmlspecialchars($row['facility_name'] ?? '-') . '</td>';
                                            }
                                            if ($row['activity_type'] === 'requisition') {
                                                echo '<td>' . htmlspecialchars($row['activity_name'] ?? '-') . '</td>';
                                                echo '<td><strong>' . number_format($row['fuel_amount'], 2) . '</strong></td>';
                                                echo '<td>' . number_format($row['total_cost'], 2) . '</td>';
                                                echo '<td>-</td>';
                                                echo '<td><span class="badge badge-' . $row['status'] . '">';
                                                echo '<i class="fas fa-' . ($row['status'] === 'approved' ? 'check' : ($row['status'] === 'rejected' ? 'times' : 'clock')) . '-circle"></i> ';
                                                echo ucfirst($row['status']) . '</span></td>';
                                            } else {
                                                echo '<td>' . htmlspecialchars($row['purpose']) . '</td>';
                                                echo '<td>-</td>';
                                                echo '<td>-</td>';
                                                echo '<td><strong>' . number_format($row['distance'], 2) . '</strong></td>';
                                                echo '<td>' . htmlspecialchars($row['approver_name'] ?? 'Pending') . '</td>';
                                            }
                                            break;
                                        case 'vehicle_consumption':
                                            echo '<td>' . htmlspecialchars($row['vehicle_name'] . ' (' . $row['number_plate'] . ')') . '</td>';
                                            if (count($accessibleFacilities) > 1) {
                                                echo '<td>' . htmlspecialchars($row['facility_name'] ?? '-') . '</td>';
                                            }
                                            echo '<td>' . $row['trip_count'] . '</td>';
                                            echo '<td><strong>' . number_format($row['total_liters'], 2) . '</strong></td>';
                                            echo '<td>' . number_format($row['avg_liters'], 2) . '</td>';
                                            echo '<td>' . number_format($row['total_cost'], 2) . '</td>';
                                            break;
                                        case 'user_consumption':
                                            echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                                            if (count($accessibleFacilities) > 1) {
                                                echo '<td>' . htmlspecialchars($row['facility_name'] ?? '-') . '</td>';
                                            }
                                            echo '<td>' . $row['trip_count'] . '</td>';
                                            echo '<td><strong>' . number_format($row['total_liters'], 2) . '</strong></td>';
                                            echo '<td>' . number_format($row['avg_liters'], 2) . '</td>';
                                            echo '<td>' . number_format($row['total_cost'], 2) . '</td>';
                                            break;
                                    }
                                    ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p style="font-weight: 600; color: var(--gray-700); margin-top: 10px;">No Data Found</p>
                    <p style="font-size: 14px; margin-top: 5px;">Try adjusting your date range or filters</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
