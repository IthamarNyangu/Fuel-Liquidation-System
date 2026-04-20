<?php
// dashboard.php - Updated with logbook entries and improved UI
error_reporting(E_ALL);
ini_set('display_errors', 1);
$appRoot = dirname(__DIR__, 2);

require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/facility_auth.php';

// Get facility information
$is_super_admin = isFleetManager();
$user_facility_id = getUserProvinceId();

// Get user role and info from session
$user_role = getCurrentRole();
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';
$user_email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '';

if ($user_role === 'driver') {
    header('Location: my_vehicle.php');
    exit();
}

$user_role_label = function_exists('getRoleDisplayName') ? getRoleDisplayName() : ucwords(str_replace('_', ' ', $user_role));
if ($user_role === 'fleet_manager') {
    $user_role_label = 'Fleet Manager';
}
$can_approve = canApproveRequisitions();
$can_review_weekly = isFleetManager() || isProvincialAdmin();
$can_access_vehicle_hub = in_array($user_role, ['driver', 'provincial_admin', 'fleet_manager'], true);
$can_use_driver_workflow = $user_role === 'driver';
$can_manage_accounts = canManageUsers();
$can_manage_vehicles = canManageVehicles();
$can_manage_provinces = canManageProvinces();
$can_adjust_float = canAdjustFloat();
$can_manage_fuel_prices = canManageFuelPrices();
$vehicleHubLabel = $user_role === 'driver' ? 'My Vehicle' : 'My Fleet';

// Check if non-fleet-manager user has a province assigned
if (!$is_super_admin && !$user_facility_id) {
    error_log("Warning: User {$_SESSION['user_id']} with role {$user_role} has no facility assigned");
}

// Database configuration
require_once $appRoot . '/db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Build province filter
$facility_filter_sql = "";
$facility_filter_params = [];
$selected_facility_id = $is_super_admin ? (int) ($_GET['facility_id'] ?? 0) : (int) $user_facility_id;
if (!$is_super_admin && $user_facility_id) {
    $facility_filter_sql = " AND u.facility_id = :facility_id";
    $facility_filter_params[':facility_id'] = $user_facility_id;
} elseif ($is_super_admin && $selected_facility_id > 0) {
    $facility_filter_sql = " AND u.facility_id = :facility_id";
    $facility_filter_params[':facility_id'] = $selected_facility_id;
}

// Build staff-only filter (staff can only see their own entries)
$staff_filter_sql = "";
$staff_filter_params = [];
if ($user_role === 'driver') {
    $staff_filter_sql = " AND u.id = :staff_user_id";
    $staff_filter_params[':staff_user_id'] = $_SESSION['user_id'];
}

// Handle requisition approval/rejection (ONLY for users with approval rights)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && $can_approve) {
    $req_id = $_POST['req_id'];
    $new_status = $_POST['status'];
    $notes = $_POST['notes'];
    
    // Verify user has access to this requisition's facility
    $stmt = $pdo->prepare("SELECT r.facility_id, r.requested_amount, r.float_account, r.fuel_price_per_liter, r.vehicle_id, u.facility_id as staff_facility_id 
                           FROM requisitions r 
                           JOIN users u ON r.staff_id = u.id 
                           WHERE r.id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check facility access
    if (!$is_super_admin && $req['staff_facility_id'] != $user_facility_id) {
        die("Access denied: You don't have permission to access this requisition");
    }
    
    // Update status
    $stmt = $pdo->prepare("UPDATE requisitions SET status = ?, notes = ? WHERE id = ?");
    $stmt->execute([$new_status, $notes, $req_id]);
    
    // If approved, deduct from vehicle's float account
    if ($new_status === 'approved' && $req['vehicle_id']) {
        $totalCost = $req['requested_amount'] * $req['fuel_price_per_liter'];
        
        // Get current balance
        $stmt = $pdo->prepare("SELECT float_balance FROM vehicles WHERE id = ?");
        $stmt->execute([$req['vehicle_id']]);
        $vehicleData = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousBalance = $vehicleData['float_balance'];
        $newBalance = $previousBalance - $totalCost;
        
        // Update vehicle float balance
        $stmt = $pdo->prepare("UPDATE vehicles SET float_balance = ? WHERE id = ?");
        $stmt->execute([$newBalance, $req['vehicle_id']]);
        
        // Update available fuel
        $stmt = $pdo->prepare("UPDATE settings SET available_fuel = available_fuel - ? WHERE id = 1");
        $stmt->execute([$req['requested_amount']]);
    }
    
    $redirectParams = [
        'account' => $_GET['account'] ?? 'All',
        'view' => $_GET['view'] ?? 'all',
    ];
    if ($is_super_admin && $selected_facility_id > 0) {
        $redirectParams['facility_id'] = $selected_facility_id;
    }
    header("Location: dashboard.php?" . http_build_query($redirectParams));
    exit;
}

// Get filter parameters
$filterStatus = $_GET['status'] ?? '';
$filterStaff = $_GET['staff'] ?? '';
$filterVehicle = $_GET['vehicle'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterYear = $_GET['year'] ?? '';
$filterAmount = $_GET['amount'] ?? '';
$sortBy = $_GET['sort'] ?? '';
$sortOrder = $_GET['order'] ?? 'DESC';
$selectedAccount = $_GET['account'] ?? 'All';
$viewMode = $_GET['view'] ?? 'all'; // all, requisitions, logbook
$itemsPerPage = 5;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

// Fetch available years from live reconciliation and movement data.
$yearsScopeSql = '';
$yearsScopeParams = [];
if (!$is_super_admin && $user_facility_id) {
    $yearsScopeSql = " AND COALESCE(scope_facility_id, 0) = :years_facility_id";
    $yearsScopeParams[':years_facility_id'] = (int) $user_facility_id;
} elseif ($is_super_admin && $selected_facility_id > 0) {
    $yearsScopeSql = " AND COALESCE(scope_facility_id, 0) = :years_facility_id";
    $yearsScopeParams[':years_facility_id'] = $selected_facility_id;
}

$yearsQuery = "
    SELECT DISTINCT year_value
    FROM (
        SELECT
            YEAR(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at)) AS year_value,
            COALESCE(wl.facility_id, v.facility_id, driver.facility_id) AS scope_facility_id
        FROM weekly_liquidations wl
        JOIN vehicles v
            ON v.id = wl.vehicle_id
        JOIN users driver
            ON driver.id = wl.driver_id
        WHERE wl.status IN ('submitted', 'under_review', 'returned', 'approved')

        UNION

        SELECT
            YEAR(tl.movement_date) AS year_value,
            COALESCE(tl.facility_id, v.facility_id, driver.facility_id) AS scope_facility_id
        FROM trip_legs tl
        JOIN vehicles v
            ON v.id = tl.vehicle_id
        JOIN users driver
            ON driver.id = tl.driver_id
        WHERE tl.record_status IN ('recorded', 'completed', 'locked')
          AND tl.total_km IS NOT NULL
    ) year_sources
    WHERE year_value IS NOT NULL" . $yearsScopeSql . "
    ORDER BY year_value DESC";
$yearsStmt = $pdo->prepare($yearsQuery);
foreach ($yearsScopeParams as $key => $value) {
    $yearsStmt->bindValue($key, $value);
}
$yearsStmt->execute();
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch vehicle float accounts
$accountsQuery = "SELECT float_account_name, float_balance FROM vehicles WHERE float_account_name IS NOT NULL";
if ($user_role === 'driver') {
    $accountsQuery .= " AND current_driver_id = :current_driver_id";
} elseif (!$is_super_admin && $user_facility_id) {
    $accountsQuery .= " AND facility_id = :facility_id";
} elseif ($is_super_admin && $selected_facility_id > 0) {
    $accountsQuery .= " AND facility_id = :facility_id";
}
$accountsQuery .= " ORDER BY vehicle_name";
$accountsStmt = $pdo->prepare($accountsQuery);
if ($user_role === 'driver') {
    $accountsStmt->bindValue(':current_driver_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
} elseif ((!$is_super_admin && $user_facility_id) || ($is_super_admin && $selected_facility_id > 0)) {
    $accountsStmt->bindValue(':facility_id', $selected_facility_id);
}
$accountsStmt->execute();
$floatAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$availableAccountNames = array_map(static function (array $account): string {
    return (string) ($account['float_account_name'] ?? '');
}, $floatAccounts);
if ($selectedAccount !== 'All' && !in_array($selectedAccount, $availableAccountNames, true)) {
    $selectedAccount = 'All';
}

// Calculate total float
$totalFloat = 0;
foreach ($floatAccounts as $account) {
    $totalFloat += $account['float_balance'];
}

// Get selected account float
if ($selectedAccount === 'All') {
    $displayFloat = $totalFloat;
} else {
    $accountQuery = "SELECT float_balance FROM vehicles WHERE float_account_name = ?";
    if ($user_role === 'driver') {
        $accountQuery .= " AND current_driver_id = ?";
        $accountStmt = $pdo->prepare($accountQuery);
        $accountStmt->execute([$selectedAccount, (int) $_SESSION['user_id']]);
    } elseif ((!$is_super_admin && $user_facility_id) || ($is_super_admin && $selected_facility_id > 0)) {
        $accountQuery .= " AND facility_id = ?";
        $accountStmt = $pdo->prepare($accountQuery);
        $accountStmt->execute([$selectedAccount, $selected_facility_id]);
    } else {
        $accountStmt = $pdo->prepare($accountQuery);
        $accountStmt->execute([$selectedAccount]);
    }
    $accountData = $accountStmt->fetch(PDO::FETCH_ASSOC);
    $displayFloat = $accountData['float_balance'] ?? 0;
}

// Months array
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

// Date range calculations
$currentMonth = date('Y-m');
$startDate = $currentMonth . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

if ($filterMonth && $filterYear) {
    $monthName = $months[$filterMonth] . ' ' . $filterYear;
    $startDate = $filterYear . '-' . $filterMonth . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
} elseif ($filterYear) {
    $monthName = 'Year ' . $filterYear;
    $startDate = $filterYear . '-01-01';
    $endDate = $filterYear . '-12-31';
} elseif ($filterMonth) {
    $currentYear = date('Y');
    $monthName = $months[$filterMonth] . ' ' . $currentYear;
    $startDate = $currentYear . '-' . $filterMonth . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
} else {
    $monthName = date('F Y');
}

// Fetch settings
$stmt = $pdo->query("SELECT available_fuel, fuel_price FROM settings LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$availableFuel = $settings['available_fuel'] ?? 0;
$currentFuelPrice = $settings['fuel_price'] ?? 0;

// Dashboard now reads live reconciliation and movement data from the redesigned workflow.
$reconciliationScopeSql = '';
$reconciliationScopeParams = [];
if (!$is_super_admin && $user_facility_id) {
    $reconciliationScopeSql = " AND COALESCE(wl.facility_id, v.facility_id, driver.facility_id) = :scope_facility_id";
    $reconciliationScopeParams[':scope_facility_id'] = (int) $user_facility_id;
} elseif ($is_super_admin && $selected_facility_id > 0) {
    $reconciliationScopeSql = " AND COALESCE(wl.facility_id, v.facility_id, driver.facility_id) = :scope_facility_id";
    $reconciliationScopeParams[':scope_facility_id'] = $selected_facility_id;
}

$movementScopeSql = '';
$movementScopeParams = [];
if (!$is_super_admin && $user_facility_id) {
    $movementScopeSql = " AND COALESCE(tl.facility_id, v.facility_id, driver.facility_id) = :scope_facility_id";
    $movementScopeParams[':scope_facility_id'] = (int) $user_facility_id;
} elseif ($is_super_admin && $selected_facility_id > 0) {
    $movementScopeSql = " AND COALESCE(tl.facility_id, v.facility_id, driver.facility_id) = :scope_facility_id";
    $movementScopeParams[':scope_facility_id'] = $selected_facility_id;
}

$mapDashboardStatusToWorkflow = static function (string $dashboardStatus): array {
    return match ($dashboardStatus) {
        'approved' => ['approved'],
        'rejected' => ['returned'],
        'pending' => ['submitted', 'under_review'],
        default => [],
    };
};

$reconciliationStatsSql = "
    SELECT
        COALESCE(SUM(CASE WHEN wl.status = 'approved' THEN 1 ELSE 0 END), 0) AS approved_count,
        COALESCE(SUM(CASE WHEN wl.status = 'approved' THEN wl.total_fuel_amount ELSE 0 END), 0) AS approved_total,
        COALESCE(SUM(CASE WHEN wl.status = 'returned' THEN 1 ELSE 0 END), 0) AS rejected_count,
        COALESCE(SUM(CASE WHEN wl.status = 'returned' THEN wl.total_fuel_amount ELSE 0 END), 0) AS rejected_total,
        COALESCE(SUM(CASE WHEN wl.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN wl.status IN ('submitted', 'under_review') THEN wl.total_fuel_amount ELSE 0 END), 0) AS pending_total
    FROM weekly_liquidations wl
    JOIN vehicles v
        ON v.id = wl.vehicle_id
    JOIN users driver
        ON driver.id = wl.driver_id
    WHERE wl.status IN ('submitted', 'under_review', 'returned', 'approved')
      AND DATE(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at)) BETWEEN :start_date AND :end_date" . $reconciliationScopeSql;
$reconciliationStatsStmt = $pdo->prepare($reconciliationStatsSql);
$reconciliationStatsStmt->bindValue(':start_date', $startDate);
$reconciliationStatsStmt->bindValue(':end_date', $endDate);
foreach ($reconciliationScopeParams as $key => $value) {
    $reconciliationStatsStmt->bindValue($key, $value);
}
$reconciliationStatsStmt->execute();
$reconciliationStats = $reconciliationStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$approvedCount = (int) ($reconciliationStats['approved_count'] ?? 0);
$approvedSum = (float) ($reconciliationStats['approved_total'] ?? 0);
$rejectedCount = (int) ($reconciliationStats['rejected_count'] ?? 0);
$rejectedSum = (float) ($reconciliationStats['rejected_total'] ?? 0);
$pendingCount = (int) ($reconciliationStats['pending_count'] ?? 0);
$pendingSum = (float) ($reconciliationStats['pending_total'] ?? 0);

$movementStatsSql = "
    SELECT
        COUNT(*) AS count,
        COALESCE(SUM(tl.total_km), 0) AS total_kms
    FROM trip_legs tl
    JOIN vehicles v
        ON v.id = tl.vehicle_id
    JOIN users driver
        ON driver.id = tl.driver_id
    WHERE tl.record_status IN ('recorded', 'completed', 'locked')
      AND tl.total_km IS NOT NULL
      AND tl.movement_date BETWEEN :start_date AND :end_date" . $movementScopeSql;
$movementStatsStmt = $pdo->prepare($movementStatsSql);
$movementStatsStmt->bindValue(':start_date', $startDate);
$movementStatsStmt->bindValue(':end_date', $endDate);
foreach ($movementScopeParams as $key => $value) {
    $movementStatsStmt->bindValue($key, $value);
}
$movementStatsStmt->execute();
$movementStats = $movementStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$logbookCount = (int) ($movementStats['count'] ?? 0);
$logbookTotalKms = (float) ($movementStats['total_kms'] ?? 0);

$requisitions = [];
if ($viewMode === 'all' || $viewMode === 'requisitions') {
    $reconciliationQuery = "
        SELECT
            wl.id,
            'fuel_liquidation' AS entry_type,
            driver.name AS driver_name,
            v.vehicle_name,
            v.number_plate,
            COALESCE(f.facility_name, 'Unassigned Province') AS province_name,
            wl.week_start_date,
            wl.week_end_date,
            wl.total_trip_legs,
            wl.total_km,
            wl.total_fuel_litres,
            wl.total_fuel_amount,
            COALESCE(fuel_item_counts.total_fuel_purchases, 0) AS fuel_purchase_count,
            wl.review_notes,
            wl.return_reason,
            reviewer.name AS reviewer_name,
            COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at) AS activity_at,
            DATE(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at)) AS entry_date,
            CASE
                WHEN wl.status = 'returned' THEN 'rejected'
                WHEN wl.status IN ('submitted', 'under_review') THEN 'pending'
                ELSE 'approved'
            END AS status
        FROM weekly_liquidations wl
        JOIN vehicles v
            ON v.id = wl.vehicle_id
        JOIN users driver
            ON driver.id = wl.driver_id
        LEFT JOIN users reviewer
            ON reviewer.id = wl.reviewed_by
        LEFT JOIN facilities f
            ON f.id = COALESCE(wl.facility_id, v.facility_id, driver.facility_id)
        LEFT JOIN (
            SELECT
                weekly_liquidation_id,
                COUNT(*) AS total_fuel_purchases
            FROM weekly_liquidation_items
            WHERE item_type = 'fuel_purchase'
            GROUP BY weekly_liquidation_id
        ) fuel_item_counts
            ON fuel_item_counts.weekly_liquidation_id = wl.id
        WHERE wl.status IN ('submitted', 'under_review', 'returned', 'approved')" . $reconciliationScopeSql;

    $reconciliationParams = $reconciliationScopeParams;

    if ($filterStatus) {
        $workflowStatuses = $mapDashboardStatusToWorkflow($filterStatus);
        if ($workflowStatuses) {
            $statusPlaceholders = [];
            foreach ($workflowStatuses as $index => $workflowStatus) {
                $placeholder = ':filter_status_' . $index;
                $statusPlaceholders[] = $placeholder;
                $reconciliationParams[$placeholder] = $workflowStatus;
            }
            $reconciliationQuery .= " AND wl.status IN (" . implode(', ', $statusPlaceholders) . ")";
        }
    }
    if ($filterStaff) {
        $reconciliationQuery .= " AND driver.name LIKE :filter_staff";
        $reconciliationParams[':filter_staff'] = "%$filterStaff%";
    }
    if ($filterVehicle) {
        $reconciliationQuery .= " AND (v.vehicle_name LIKE :filter_vehicle1 OR v.number_plate LIKE :filter_vehicle2)";
        $reconciliationParams[':filter_vehicle1'] = "%$filterVehicle%";
        $reconciliationParams[':filter_vehicle2'] = "%$filterVehicle%";
    }
    if ($filterMonth && $filterYear) {
        $reconciliationQuery .= " AND DATE_FORMAT(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at), '%Y-%m') = :filter_month_year";
        $reconciliationParams[':filter_month_year'] = $filterYear . '-' . $filterMonth;
    } elseif ($filterMonth) {
        $reconciliationQuery .= " AND MONTH(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at)) = :filter_month";
        $reconciliationParams[':filter_month'] = $filterMonth;
    } elseif ($filterYear) {
        $reconciliationQuery .= " AND YEAR(COALESCE(wl.reviewed_at, wl.submitted_at, wl.updated_at, wl.created_at)) = :filter_year";
        $reconciliationParams[':filter_year'] = $filterYear;
    }
    if ($filterAmount) {
        $reconciliationQuery .= " AND wl.total_fuel_amount >= :filter_amount";
        $reconciliationParams[':filter_amount'] = $filterAmount;
    }

    if ($sortBy === 'province') {
        $reconciliationQuery .= " ORDER BY COALESCE(f.facility_name, '') " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'driver') {
        $reconciliationQuery .= " ORDER BY driver.name " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'vehicle') {
        $reconciliationQuery .= " ORDER BY v.vehicle_name " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'litres') {
        $reconciliationQuery .= " ORDER BY wl.total_fuel_litres " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'purchases') {
        $reconciliationQuery .= " ORDER BY COALESCE(fuel_item_counts.total_fuel_purchases, 0) " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'total') {
        $reconciliationQuery .= " ORDER BY wl.total_fuel_amount " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'recent') {
        $reconciliationQuery .= " ORDER BY activity_at " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } else {
        $reconciliationQuery .= " ORDER BY activity_at DESC";
    }

    $reconciliationStmt = $pdo->prepare($reconciliationQuery);
    foreach ($reconciliationParams as $key => $value) {
        $reconciliationStmt->bindValue($key, $value);
    }
    $reconciliationStmt->execute();
    $requisitions = $reconciliationStmt->fetchAll(PDO::FETCH_ASSOC);
}

$logbookEntries = [];
if ($viewMode === 'all' || $viewMode === 'logbook') {
    $movementQuery = "
        SELECT
            tl.id,
            'logbook' AS entry_type,
            driver.name AS driver_name,
            v.vehicle_name,
            v.number_plate,
            COALESCE(f.facility_name, 'Unassigned Province') AS province_name,
            tl.movement_date AS log_date,
            tl.from_location AS location_from,
            tl.to_location AS location_to,
            tl.purpose,
            tl.time_out,
            tl.time_in,
            tl.odometer_start_km AS start_kms,
            tl.odometer_end_km AS end_kms,
            tl.total_km AS total_kms,
            tl.record_status,
            TIMESTAMP(tl.movement_date, COALESCE(tl.time_in, tl.time_out, '00:00:00')) AS activity_at
        FROM trip_legs tl
        JOIN vehicles v
            ON v.id = tl.vehicle_id
        JOIN users driver
            ON driver.id = tl.driver_id
        LEFT JOIN facilities f
            ON f.id = COALESCE(tl.facility_id, v.facility_id, driver.facility_id)
        WHERE tl.record_status IN ('recorded', 'completed', 'locked')
          AND tl.total_km IS NOT NULL" . $movementScopeSql;

    $movementParams = $movementScopeParams;

    if ($filterStaff) {
        $movementQuery .= " AND driver.name LIKE :filter_staff";
        $movementParams[':filter_staff'] = "%$filterStaff%";
    }
    if ($filterVehicle) {
        $movementQuery .= " AND (v.vehicle_name LIKE :filter_vehicle1 OR v.number_plate LIKE :filter_vehicle2)";
        $movementParams[':filter_vehicle1'] = "%$filterVehicle%";
        $movementParams[':filter_vehicle2'] = "%$filterVehicle%";
    }
    if ($filterMonth && $filterYear) {
        $movementQuery .= " AND DATE_FORMAT(tl.movement_date, '%Y-%m') = :filter_month_year";
        $movementParams[':filter_month_year'] = $filterYear . '-' . $filterMonth;
    } elseif ($filterMonth) {
        $movementQuery .= " AND MONTH(tl.movement_date) = :filter_month";
        $movementParams[':filter_month'] = $filterMonth;
    } elseif ($filterYear) {
        $movementQuery .= " AND YEAR(tl.movement_date) = :filter_year";
        $movementParams[':filter_year'] = $filterYear;
    }

    if ($sortBy === 'province') {
        $movementQuery .= " ORDER BY COALESCE(f.facility_name, '') " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'driver') {
        $movementQuery .= " ORDER BY driver.name " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'vehicle') {
        $movementQuery .= " ORDER BY v.vehicle_name " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'kms') {
        $movementQuery .= " ORDER BY tl.total_km " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . ", activity_at DESC";
    } elseif ($sortBy === 'recent') {
        $movementQuery .= " ORDER BY activity_at " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } else {
        $movementQuery .= " ORDER BY activity_at DESC";
    }

    $movementStmt = $pdo->prepare($movementQuery);
    foreach ($movementParams as $key => $value) {
        $movementStmt->bindValue($key, $value);
    }
    $movementStmt->execute();
    $logbookEntries = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
}

$allEntries = [];
if ($viewMode === 'all') {
    $allEntries = array_merge($requisitions, $logbookEntries);
    $allSortBy = $sortBy ?: 'recent';
    $allSortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';
    usort($allEntries, static function (array $a, array $b) use ($allSortBy, $allSortOrder): int {
        $direction = $allSortOrder === 'ASC' ? 1 : -1;

        switch ($allSortBy) {
            case 'province':
                $left = strtolower((string) ($a['province_name'] ?? ''));
                $right = strtolower((string) ($b['province_name'] ?? ''));
                break;
            case 'driver':
                $left = strtolower((string) ($a['driver_name'] ?? $a['staff_name'] ?? ''));
                $right = strtolower((string) ($b['driver_name'] ?? $b['staff_name'] ?? ''));
                break;
            case 'vehicle':
                $left = strtolower((string) ($a['vehicle_name'] ?? ''));
                $right = strtolower((string) ($b['vehicle_name'] ?? ''));
                break;
            case 'recent':
            default:
                $left = strtotime((string) ($a['activity_at'] ?? $a['created_at'] ?? 'now'));
                $right = strtotime((string) ($b['activity_at'] ?? $b['created_at'] ?? 'now'));
                break;
        }

        if ($left === $right) {
            return 0;
        }

        return $left <=> $right ? (($left <=> $right) * $direction) : 0;
    });
}

// Pagination for all list views
$entriesSource = $viewMode === 'all'
    ? $allEntries
    : ($viewMode === 'requisitions' ? $requisitions : $logbookEntries);

$totalEntries = count($entriesSource);
$totalPages = max(1, (int)ceil($totalEntries / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $itemsPerPage;
$entriesToShow = array_slice($entriesSource, $offset, $itemsPerPage);

$buildPageUrl = function($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'dashboard.php?' . http_build_query($params);
};

$viewTitle = match ($viewMode) {
    'requisitions' => 'Recent Fuel Liquidations',
    'logbook' => 'Recent Log Movements',
    default => 'All Recent Entries',
};

$sortOptions = match ($viewMode) {
    'requisitions' => [
        ['value' => 'recent_desc', 'label' => 'Most Recent'],
        ['value' => 'recent_asc', 'label' => 'Oldest First'],
        ['value' => 'total_desc', 'label' => 'Highest Total'],
        ['value' => 'total_asc', 'label' => 'Lowest Total'],
        ['value' => 'purchases_desc', 'label' => 'Most Fuel Purchases'],
        ['value' => 'purchases_asc', 'label' => 'Fewest Fuel Purchases'],
        ['value' => 'province_asc', 'label' => 'Province A-Z'],
        ['value' => 'province_desc', 'label' => 'Province Z-A'],
    ],
    'logbook' => [
        ['value' => 'recent_desc', 'label' => 'Most Recent'],
        ['value' => 'recent_asc', 'label' => 'Oldest First'],
        ['value' => 'kms_desc', 'label' => 'Longest Distance'],
        ['value' => 'kms_asc', 'label' => 'Shortest Distance'],
        ['value' => 'province_asc', 'label' => 'Province A-Z'],
        ['value' => 'province_desc', 'label' => 'Province Z-A'],
    ],
    default => [
        ['value' => 'recent_desc', 'label' => 'Most Recent'],
        ['value' => 'recent_asc', 'label' => 'Oldest First'],
        ['value' => 'province_asc', 'label' => 'Province A-Z'],
        ['value' => 'province_desc', 'label' => 'Province Z-A'],
        ['value' => 'driver_asc', 'label' => 'Driver A-Z'],
        ['value' => 'vehicle_asc', 'label' => 'Vehicle A-Z'],
    ],
};
$currentSortValue = $sortBy !== '' ? $sortBy . '_' . strtolower($sortOrder) : '';

// Get facility name for display
if ($is_super_admin) {
    $facilitiesStmt = $pdo->query("SELECT id, facility_name FROM facilities WHERE is_active = 1 ORDER BY facility_name");
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    $facility_display = "All Provinces";
    if ($selected_facility_id > 0) {
        foreach ($facilities as $facility) {
            if ((int) $facility['id'] === $selected_facility_id) {
                $facility_display = $facility['facility_name'];
                break;
            }
        }
    }
} else {
    $facilities = [];
    $facility_query = "SELECT facility_name FROM facilities WHERE id = ?";
    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute([$user_facility_id]);
    $facility_row = $facility_stmt->fetch(PDO::FETCH_ASSOC);
    $facility_display = $facility_row ? $facility_row['facility_name'] : "Your Province";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Liquidation Dashboard</title>
    <?php require $appRoot . '/favicon_links.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/dashboard.css?v=<?php echo urlencode((string) @filemtime($appRoot . '/assets/css/dashboard.css')); ?>">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <h2><i class="fas fa-gas-pump"></i><span>Fuel System</span></h2>
            </div>
            <ul class="menu">
                <li><a href="dashboard.php" class="active"><span class="menu-icon"><i class="fas fa-home"></i></span><span class="menu-text">Dashboard</span></a></li>
                <li><a href="reports.php"><span class="menu-icon"><i class="fas fa-chart-line"></i></span><span class="menu-text">Reports</span></a></li>
                <?php if ($can_access_vehicle_hub): ?>
                <li><a href="my_vehicle.php"><span class="menu-icon"><i class="fas fa-car-side"></i></span><span class="menu-text"><?php echo htmlspecialchars($vehicleHubLabel); ?></span></a></li>
                <?php endif; ?>
                <?php if ($can_use_driver_workflow): ?>
                <li><a href="weekly_report.php"><span class="menu-icon"><i class="fas fa-file-alt"></i></span><span class="menu-text">Weekly Report</span></a></li>
                <li><a href="pending_reconciliations.php"><span class="menu-icon"><i class="fas fa-clipboard-check"></i></span><span class="menu-text">Pending Reconciliations</span></a></li>
                <?php endif; ?>
                <?php if ($can_review_weekly): ?>
                <li><a href="province_liquidation.php"><span class="menu-icon"><i class="fas fa-user-check"></i></span><span class="menu-text">Reconciliation Review</span></a></li>
                <?php endif; ?>
                <?php if ($can_use_driver_workflow): ?>
                <li><a href="logbook.php"><span class="menu-icon"><i class="fas fa-book"></i></span><span class="menu-text">Log Book</span></a></li>
                <li><a href="request.php"><span class="menu-icon"><i class="fas fa-gas-pump"></i></span><span class="menu-text">Request Fuel</span></a></li>
                <?php endif; ?>
                <?php if ($can_manage_accounts): ?>
                <li><a href="users.php"><span class="menu-icon"><i class="fas fa-users"></i></span><span class="menu-text">Users</span></a></li>
                <?php endif; ?>
                <?php if ($can_manage_vehicles): ?>
                <li><a href="manage_vehicles.php"><span class="menu-icon"><i class="fas fa-car"></i></span><span class="menu-text">Vehicles</span></a></li>
                <?php endif; ?>
                <?php if ($can_manage_provinces): ?>
                <li><a href="manage_provinces.php"><span class="menu-icon"><i class="fas fa-building"></i></span><span class="menu-text">Provinces</span></a></li>
                <?php endif; ?>
                <?php if ($can_adjust_float): ?>
                <li><a href="fuel_topup.php"><span class="menu-icon"><i class="fas fa-droplet"></i></span><span class="menu-text">Fuel Card Top Up</span></a></li>
                <?php endif; ?>
                <?php if ($can_manage_fuel_prices): ?>
                <li><a href="fuelset.php"><span class="menu-icon"><i class="fas fa-sliders"></i></span><span class="menu-text">Fuel Price Set</span></a></li>
                <?php endif; ?>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <div class="header-title-block">
                    <h1>Overview</h1>
                </div>
                <div class="user-header">
                    <div class="user-role-header"><?php echo htmlspecialchars($user_role_label); ?></div>
                    <a href="logout.php" class="btn-logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Vehicle Float Account Selector -->
            <div class="account-selector">
                <?php if ($is_super_admin): ?>
                <div class="selector-field">
                    <label for="facilitySelect">Province</label>
                    <select id="facilitySelect" onchange="changeFacility(this.value)">
                        <option value="0" <?php echo $selected_facility_id === 0 ? 'selected' : ''; ?>>All Provinces</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo (int) $facility['id']; ?>" <?php echo $selected_facility_id === (int) $facility['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($facility['facility_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="selector-field">
                    <label for="accountSelect">Vehicle Float Account</label>
                    <select id="accountSelect" onchange="changeAccount(this.value)">
                        <option value="All" <?php echo $selectedAccount === 'All' ? 'selected' : ''; ?>>All Accounts</option>
                        <?php foreach ($floatAccounts as $account): ?>
                            <option value="<?php echo htmlspecialchars($account['float_account_name']); ?>" 
                                    <?php echo $selectedAccount === $account['float_account_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['float_account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="cards">
                <div class="card">
                    <i class="fas fa-gas-pump card-icon"></i>
                    <h3><i class="fas fa-droplet"></i> Total Float Balance</h3>
                    <div class="card-value">K <?php echo number_format($displayFloat, 0); ?></div>
                    <div class="card-subtitle"><?php echo htmlspecialchars($selectedAccount); ?></div>
                </div>
                <div class="card clickable" onclick="toggleCardFilter('approved')">
                    <i class="fas fa-check-circle card-icon"></i>
                    <h3><i class="fas fa-thumbs-up"></i> Approvals</h3>
                    <div class="card-value"><?php echo $approvedCount; ?></div>
                    <div class="card-subtitle">ZMW <?php echo number_format($approvedSum, 2); ?> • <?php echo $monthName; ?></div>
                </div>
                <div class="card clickable" onclick="toggleCardFilter('rejected')">
                    <i class="fas fa-times-circle card-icon"></i>
                    <h3><i class="fas fa-thumbs-down"></i> Rejections</h3>
                    <div class="card-value"><?php echo $rejectedCount; ?></div>
                    <div class="card-subtitle">ZMW <?php echo number_format($rejectedSum, 2); ?> • <?php echo $monthName; ?></div>
                </div>
                <div class="card clickable" onclick="toggleCardFilter('pending')">
                    <i class="fas fa-clock card-icon"></i>
                    <h3><i class="fas fa-hourglass-half"></i> Pending</h3>
                    <div class="card-value"><?php echo $pendingCount; ?></div>
                    <div class="card-subtitle">ZMW <?php echo number_format($pendingSum, 2); ?> • <?php echo $monthName; ?></div>
                </div>
                <div class="card logbook-card">
                    <i class="fas fa-book card-icon blue"></i>
                    <h3><i class="fas fa-route"></i> Logbook Entries</h3>
                    <div class="card-value"><?php echo $logbookCount; ?></div>
                    <div class="card-subtitle"><?php echo number_format($logbookTotalKms, 2); ?> KM • <?php echo $monthName; ?></div>
                </div>
            </div>

            <?php if ($user_role === 'driver'): ?>
            <?php endif; ?>

            <!-- View Toggle -->
            <div class="view-toggle">
                <button class="view-tab <?php echo $viewMode === 'all' ? 'active' : ''; ?>" onclick="changeView('all')">
                    <i class="fas fa-th-list"></i> All Entries
                </button>
                <button class="view-tab <?php echo $viewMode === 'requisitions' ? 'active' : ''; ?>" onclick="changeView('requisitions')">
                    <i class="fas fa-gas-pump"></i> Fuel Liquidations
                </button>
                <button class="view-tab <?php echo $viewMode === 'logbook' ? 'active' : ''; ?>" onclick="changeView('logbook')">
                    <i class="fas fa-book"></i> Logbook
                </button>
            </div>

            <!-- Entries List -->
            <div class="requests-container" id="entries-section">
                <div class="card-header">
                    <h2 class="card-title"><?php echo htmlspecialchars($viewTitle); ?></h2>
                    <div class="filter-controls">
                        <select class="sort-select" onchange="sortTable(this.value)" style="display:none;">
                            <option value="">Sort by...</option>
                            <option value="amount_desc" <?php echo ($sortBy === 'amount' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Amount ↓</option>
                            <option value="amount_asc" <?php echo ($sortBy === 'amount' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Amount ↑</option>
                            <option value="price_desc" <?php echo ($sortBy === 'price' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Price ↓</option>
                            <option value="price_asc" <?php echo ($sortBy === 'price' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Price ↑</option>
                            <option value="total_desc" <?php echo ($sortBy === 'total' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Total ↓</option>
                            <option value="total_asc" <?php echo ($sortBy === 'total' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Total ↑</option>
                        </select>
                        <select class="sort-select" onchange="sortTable(this.value)" style="display:none;">
                            <option value="">Sort by...</option>
                            <option value="kms_desc" <?php echo ($sortBy === 'kms' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Distance ↓</option>
                            <option value="kms_asc" <?php echo ($sortBy === 'kms' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Distance ↑</option>
                        </select>
                        <select class="sort-select" onchange="sortTable(this.value)">
                            <option value="">Sort by...</option>
                            <?php foreach ($sortOptions as $option): ?>
                            <option value="<?php echo htmlspecialchars($option['value']); ?>" <?php echo $currentSortValue === $option['value'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="position: relative;">
                            <div class="filter-icon" onclick="toggleFilters()">
                                <i class="fas fa-filter"></i> Filter
                            </div>
                            <div class="filter-dropdown" id="filterDropdown">
                                <h3><i class="fas fa-sliders-h"></i> Filter Entries</h3>
                                <form method="GET" id="filterForm">
                                    <input type="hidden" name="account" value="<?php echo htmlspecialchars($selectedAccount); ?>">
                                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
                                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                                    <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder); ?>">
                                    <?php if ($is_super_admin && $selected_facility_id > 0): ?>
                                    <input type="hidden" name="facility_id" value="<?php echo (int) $selected_facility_id; ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="month" id="selectedMonth" value="<?php echo htmlspecialchars($filterMonth); ?>">
                                    <input type="hidden" name="year" id="selectedYear" value="<?php echo htmlspecialchars($filterYear); ?>">
                                    
                                    <div class="filter-section">
                                        <div class="filter-group">
                                            <label><i class="fas fa-user"></i> Driver</label>
                                            <input type="text" name="staff" placeholder="Search by name" value="<?php echo htmlspecialchars($filterStaff); ?>">
                                        </div>
                                        <div class="filter-group">
                                            <label><i class="fas fa-car"></i> Vehicle</label>
                                            <input type="text" name="vehicle" placeholder="Search vehicle" value="<?php echo htmlspecialchars($filterVehicle); ?>">
                                        </div>
                                        <?php if ($viewMode === 'requisitions'): ?>
                                        <div class="filter-group">
                                            <label><i class="fas fa-money-bill-wave"></i> Minimum Total (ZMW)</label>
                                            <input type="number" name="amount" step="0.01" placeholder="Minimum total spend" value="<?php echo htmlspecialchars($filterAmount); ?>">
                                        </div>
                                        <div class="filter-group">
                                            <label><i class="fas fa-info-circle"></i> Status</label>
                                            <select name="status" id="statusFilter">
                                                <option value="">All Status</option>
                                                <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-title"><i class="fas fa-calendar-alt"></i> Filter by Month</div>
                                        <div class="button-grid">
                                            <?php foreach ($months as $num => $name): ?>
                                                <button 
                                                    type="button" 
                                                    class="month-btn <?php echo $filterMonth === $num ? 'selected' : ''; ?>" 
                                                    onclick="selectMonth('<?php echo $num; ?>')"
                                                >
                                                    <?php echo $name; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-title"><i class="fas fa-calendar"></i> Filter by Year</div>
                                        <div class="button-grid">
                                            <?php 
                                            if (empty($availableYears)) {
                                                $currentYear = date('Y');
                                                $availableYears = [$currentYear, $currentYear - 1, $currentYear - 2];
                                            }
                                            foreach ($availableYears as $year): 
                                            ?>
                                                <button 
                                                    type="button" 
                                                    class="year-btn <?php echo $filterYear == $year ? 'selected' : ''; ?>" 
                                                    onclick="selectYear('<?php echo $year; ?>')"
                                                >
                                                    <?php echo $year; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-actions">
                                        <button type="submit" class="btn-filter"><i class="fas fa-check"></i> Apply</button>
                                        <button type="button" class="btn-clear" onclick="clearFilters()"><i class="fas fa-times"></i> Clear</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (count($entriesToShow) > 0): ?>
                <?php foreach ($entriesToShow as $entry): 
                        $isLogbook = ($entry['entry_type'] === 'logbook');
                        $entryDate = $isLogbook ? ($entry['log_date'] ?? null) : ($entry['entry_date'] ?? null);
                ?>
                    <details class="request-item <?php echo $isLogbook ? 'logbook-item' : ''; ?>">
                        <summary class="request-summary">
                            <div class="summary-top">
                                <div class="request-title">
                                    <i class="fas fa-<?php echo $isLogbook ? 'book' : 'gas-pump'; ?>"></i>
                                    <?php echo htmlspecialchars($entry['vehicle_name'] . ' (' . $entry['number_plate'] . ')'); ?>
                                    <?php if ($viewMode === 'all'): ?>
                                    <span class="entry-type-badge <?php echo $isLogbook ? 'logbook' : 'requisition'; ?>">
                                        <i class="fas fa-<?php echo $isLogbook ? 'book' : 'gas-pump'; ?>"></i>
                                        <?php echo $isLogbook ? 'Logbook' : 'Fuel Liquidation'; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-right">
                                    <?php if (!$isLogbook): ?>
                                    <span class="status-badge status-<?php echo $entry['status']; ?>">
                                        <i class="fas fa-<?php echo $entry['status'] === 'approved' ? 'check-circle' : ($entry['status'] === 'rejected' ? 'times-circle' : 'clock'); ?>"></i>
                                        <?php echo ucfirst($entry['status']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="details-pill" aria-hidden="true">
                                        Details <i class="fas fa-chevron-down"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="summary-bottom">
                                <div class="summary-meta">
                                    <span class="summary-meta-label">
                                        <i class="fas fa-user"></i> Driver
                                    </span>
                                    <span class="summary-meta-value"><?php echo htmlspecialchars($entry['driver_name'] ?? 'Unknown'); ?></span>
                                </div>
                                <div class="summary-meta">
                                    <span class="summary-meta-label"><i class="fas fa-calendar"></i> Date</span>
                                    <span class="summary-meta-value"><?php echo $entryDate ? htmlspecialchars(date('d M Y', strtotime((string) $entryDate))) : '-'; ?></span>
                                </div>
                                <div class="summary-meta">
                                    <span class="summary-meta-label"><i class="fas fa-location-dot"></i> Province</span>
                                    <span class="summary-meta-value"><?php echo htmlspecialchars((string) ($entry['province_name'] ?? 'Unassigned Province')); ?></span>
                                </div>
                            </div>
                        </summary>
                        <div class="request-expand">
                            <div class="request-details">
                            <?php if ($isLogbook): ?>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> From</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['location_from'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> To</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['location_to'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-route"></i> Distance</span>
                                    <span class="detail-value highlight-blue"><?php echo number_format((int) round((float) $entry['total_kms'])); ?> KM</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-tasks"></i> Purpose</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['purpose'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-clock"></i> Time Out</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['time_out'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-clock"></i> Time In</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['time_in'] ?? '-'); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-receipt"></i> Fuel Purchases</span>
                                    <span class="detail-value highlight"><?php echo number_format((int) ($entry['fuel_purchase_count'] ?? 0)); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-droplet"></i> Total Fuel</span>
                                    <span class="detail-value highlight"><?php echo number_format((float) ($entry['total_fuel_litres'] ?? 0), 2); ?> L</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-money-bill-wave"></i> Total Spend</span>
                                    <span class="detail-value highlight">K <?php echo number_format((float) ($entry['total_fuel_amount'] ?? 0), 2); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-route"></i> Movement Legs</span>
                                    <span class="detail-value"><?php echo number_format((int) ($entry['total_trip_legs'] ?? 0)); ?> legs · <?php echo number_format((float) ($entry['total_km'] ?? 0), 2); ?> KM</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-calendar-week"></i> Reconciliation Window</span>
                                    <span class="detail-value"><?php echo htmlspecialchars(date('d M Y', strtotime((string) $entry['week_start_date'])) . ' - ' . date('d M Y', strtotime((string) $entry['week_end_date']))); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-user-check"></i> Reviewer</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['reviewer_name'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-note-sticky"></i> Notes</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['return_reason'] ?: ($entry['review_notes'] ?? '-')); ?></span>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Entries Found</h3>
                        <p>No entries match your filters<?php 
                            if ($user_role === 'driver') {
                                echo ' in your personal records';
                            } elseif (!$is_super_admin) {
                                echo ' in your province';
                            }
                        ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($currentPage > 1): ?>
                            <a class="page-link page-nav" href="<?php echo htmlspecialchars($buildPageUrl($currentPage - 1)); ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <div class="page-numbers">
                            <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                <a class="page-link page-number <?php echo $page === $currentPage ? 'active' : ''; ?>"
                                   href="<?php echo htmlspecialchars($buildPageUrl($page)); ?>">
                                    <?php echo $page; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($currentPage < $totalPages): ?>
                            <a class="page-link page-nav" href="<?php echo htmlspecialchars($buildPageUrl($currentPage + 1)); ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Requisition Modal -->
    <div class="modal" id="approvalModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Requisition Details</h2>
            </div>
            <div class="modal-body">
                <div class="modal-info" id="modalInfo"></div>
                <form method="POST" id="approvalForm">
                    <input type="hidden" name="req_id" id="reqId">
                    <input type="hidden" name="status" id="statusInput">
                    <input type="hidden" name="update_status" value="1">
                    
                    <div class="form-group">
                        <label for="notes" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-weight: 700; color: var(--gray-700);">
                            <i class="fas fa-comment"></i> <?php echo $can_approve ? 'Admin Notes' : 'Notes'; ?>
                        </label>
                        <textarea name="notes" id="notes" placeholder="<?php echo $can_approve ? 'Add notes for this requisition...' : 'View notes for this requisition...'; ?>" <?php echo !$can_approve ? 'readonly' : ''; ?>></textarea>
                    </div>
                    
                    <div class="modal-actions" id="modalActions">
                        <!-- Buttons will be dynamically shown based on user role and status -->
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Logbook Modal -->
    <div class="modal" id="logbookModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-book"></i> Logbook Entry Details</h2>
            </div>
            <div class="modal-body">
                <div class="modal-info" id="logbookModalInfo"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeLogbookModal()" style="width: 100%;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function changeFacility(facilityId) {
            const url = new URL(window.location.href);
            if (!facilityId || facilityId === '0') {
                url.searchParams.delete('facility_id');
            } else {
                url.searchParams.set('facility_id', facilityId);
            }
            url.searchParams.set('account', 'All');
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function changeAccount(account) {
            const url = new URL(window.location.href);
            url.searchParams.set('account', account);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function changeView(view) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
            url.searchParams.set('page', '1');
            // Reset sort when changing views
            if (view === 'logbook') {
                url.searchParams.delete('sort');
                url.searchParams.delete('order');
            }
            url.hash = 'entries-section';
            window.location.href = url.toString();
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleFilters() {
            document.getElementById('filterDropdown').classList.toggle('active');
        }

        function selectMonth(month) {
            const hiddenInput = document.getElementById('selectedMonth');
            const buttons = document.querySelectorAll('.month-btn');
            
            if (hiddenInput.value === month) {
                hiddenInput.value = '';
                buttons.forEach(btn => btn.classList.remove('selected'));
            } else {
                hiddenInput.value = month;
                buttons.forEach(btn => btn.classList.remove('selected'));
                event.target.classList.add('selected');
            }
        }

        function selectYear(year) {
            const hiddenInput = document.getElementById('selectedYear');
            const buttons = document.querySelectorAll('.year-btn');
            
            if (hiddenInput.value === year) {
                hiddenInput.value = '';
                buttons.forEach(btn => btn.classList.remove('selected'));
            } else {
                hiddenInput.value = year;
                buttons.forEach(btn => btn.classList.remove('selected'));
                event.target.classList.add('selected');
            }
        }

        function toggleCardFilter(status) {
            const currentStatus = '<?php echo $filterStatus; ?>';
            const url = new URL(window.location.href);
            url.searchParams.set('page', '1');
            
            if (currentStatus === status) {
                url.searchParams.delete('status');
            } else {
                url.searchParams.set('status', status);
                url.searchParams.set('view', 'requisitions');
            }
            window.location.href = url.toString();
        }

        function sortTable(sortValue) {
            if (!sortValue) {
                const url = new URL(window.location.href);
                url.searchParams.delete('sort');
                url.searchParams.delete('order');
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
                return;
            }

            const [sortBy, order] = sortValue.split('_');
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortBy);
            url.searchParams.set('order', order.toUpperCase());
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function clearFilters() {
            const url = new URL(window.location.href);
            ['status', 'staff', 'vehicle', 'month', 'year', 'amount', 'sort', 'order', 'page'].forEach((key) => {
                url.searchParams.delete(key);
            });
            url.searchParams.set('view', url.searchParams.get('view') || 'all');
            window.location.href = url.toString();
        }

        function openModal(data, canApprove) {
            const modal = document.getElementById('approvalModal');
            const modalInfo = document.getElementById('modalInfo');
            const modalActions = document.getElementById('modalActions');
            
            let receiptHtml = '';
            
            if (data.receipt_filename) {
                const receiptType = data.receipt_type || '';
                const receiptId = data.id;
                
                if (receiptType.startsWith('image/')) {
                    receiptHtml = `
                        <div class="receipt-preview">
                            <h4><i class="fas fa-paperclip"></i> Attached Receipt</h4>
                            <img src="download_receipt.php?id=${receiptId}" alt="Receipt" class="receipt-image" />
                        </div>
                    `;
                } else {
                    const icon = receiptType.includes('pdf') ? 'fa-file-pdf' : 'fa-file-alt';
                    receiptHtml = `
                        <div class="receipt-preview">
                            <h4><i class="fas fa-paperclip"></i> Attached Receipt</h4>
                            <div style="display: flex; align-items: center; gap: 15px; padding: 20px; background: var(--gray-50); border-radius: 12px;">
                                <i class="fas ${icon}" style="font-size: 36px; color: var(--primary-red);"></i>
                                <div style="flex: 1;">
                                    <div style="font-weight: 700; color: var(--gray-900); margin-bottom: 5px;">${data.receipt_filename}</div>
                                    <div style="font-size: 13px; color: var(--gray-600);">${receiptType || 'Document'}</div>
                                </div>
                                <a href="download_receipt.php?id=${receiptId}" target="_blank" style="padding: 10px 20px; background: var(--primary-red); color: white; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 13px;"><i class="fas fa-eye"></i> View</a>
                            </div>
                        </div>
                    `;
                }
            } else {
                receiptHtml = `
                    <div class="receipt-preview">
                        <div class="no-receipt"><i class="fas fa-file-excel" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>No receipt attached</div>
                    </div>
                `;
            }
            
            modalInfo.innerHTML = `
                <div class="modal-info-grid">
                    <div class="modal-info-item">
                        <div class="modal-info-label">Driver</div>
                        <div class="modal-info-value"><i class="fas fa-user"></i> ${data.staff_name}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Vehicle</div>
                        <div class="modal-info-value"><i class="fas fa-car"></i> ${data.vehicle_name} (${data.number_plate})</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Float Account</div>
                        <div class="modal-info-value">
                            <span style="background: rgba(220, 38, 38, 0.15); color: var(--primary-red); padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: 13px; border: 1px solid rgba(220, 38, 38, 0.3);"><i class="fas fa-wallet"></i> ${data.float_account || 'N/A'}</span>
                        </div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Requested Amount</div>
                        <div class="modal-info-value"><i class="fas fa-gas-pump"></i> ${parseFloat(data.requested_amount).toFixed(2)} L</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Current Mileage</div>
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${data.mileage ? Math.round(parseFloat(data.mileage)).toLocaleString('en-US') + ' KM' : '-'}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Filling Station</div>
                        <div class="modal-info-value"><i class="fas fa-map-marker-alt"></i> ${data.filling_station || '-'}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Receipt Number</div>
                        <div class="modal-info-value"><i class="fas fa-receipt"></i> ${data.receipt_number || '-'}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Approver</div>
                        <div class="modal-info-value"><i class="fas fa-user-check"></i> ${data.approver_name || '-'}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Price per Liter</div>
                        <div class="modal-info-value"><i class="fas fa-money-bill-wave"></i> ZMW ${parseFloat(data.fuel_price_per_liter).toFixed(2)}/L</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Total Cost</div>
                        <div class="modal-info-value" style="font-size: 18px; color: var(--primary-red);"><i class="fas fa-calculator"></i> <strong>ZMW ${(data.requested_amount * data.fuel_price_per_liter).toFixed(2)}</strong></div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Date</div>
                        <div class="modal-info-value"><i class="fas fa-calendar-day"></i> ${new Date(data.request_date).toLocaleDateString()}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Status</div>
                        <div class="modal-info-value">
                            <span class="status-badge status-${data.status}">
                                ${data.status === 'approved' ? '<i class="fas fa-check-circle"></i>' : 
                                  data.status === 'rejected' ? '<i class="fas fa-times-circle"></i>' : 
                                  '<i class="fas fa-clock"></i>'}
                                ${data.status.charAt(0).toUpperCase() + data.status.slice(1)}
                            </span>
                        </div>
                    </div>
                    <div class="modal-info-item full-width">
                        <div class="modal-info-label">Activity/Purpose</div>
                        <div class="modal-info-value"><i class="fas fa-tasks"></i> ${data.activity_name || '-'}</div>
                    </div>
                </div>
                ${receiptHtml}
            `;
            
            // Update modal action buttons based on user permissions and status
            if (canApprove && data.status === 'pending') {
                modalActions.innerHTML = `
                    <button type="button" class="btn-approve" onclick="submitApproval('approved')">
                        <i class="fas fa-check-circle"></i> Approve
                    </button>
                    <button type="button" class="btn-reject" onclick="submitApproval('rejected')">
                        <i class="fas fa-times-circle"></i> Reject
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <i class="fas fa-ban"></i> Cancel
                    </button>
                `;
            } else {
                let message = '';
                if (!canApprove) {
                    message = '<div style="padding: 12px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 10px; margin-bottom: 15px; color: #d97706; font-weight: 600; font-size: 14px;"><i class="fas fa-info-circle"></i> You can view this requisition but cannot approve or reject it. Only admins can process requests.</div>';
                } else if (data.status !== 'pending') {
                    message = '<div style="padding: 12px; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 10px; margin-bottom: 15px; color: #2563eb; font-weight: 600; font-size: 14px;"><i class="fas fa-info-circle"></i> This requisition has already been ' + data.status + '.</div>';
                }
                
                modalActions.innerHTML = message + `
                    <button type="button" class="btn-cancel" onclick="closeModal()" style="width: 100%;">
                        <i class="fas fa-times"></i> Close
                    </button>
                `;
            }
            
            document.getElementById('reqId').value = data.id;
            document.getElementById('notes').value = data.notes || '';
            
            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('approvalModal').classList.remove('active');
        }

        function submitApproval(status) {
            document.getElementById('statusInput').value = status;
            document.getElementById('approvalForm').submit();
        }

        function openLogbookModal(data) {
            const modal = document.getElementById('logbookModal');
            const modalInfo = document.getElementById('logbookModalInfo');
            
            modalInfo.innerHTML = `
                <div class="modal-info-grid">
                    <div class="modal-info-item">
                        <div class="modal-info-label">Driver</div>
                        <div class="modal-info-value"><i class="fas fa-user"></i> ${data.driver_name}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Vehicle</div>
                        <div class="modal-info-value"><i class="fas fa-car"></i> ${data.vehicle_name} (${data.number_plate})</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Date</div>
                        <div class="modal-info-value"><i class="fas fa-calendar-day"></i> ${new Date(data.log_date).toLocaleDateString()}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Approver</div>
                        <div class="modal-info-value"><i class="fas fa-user-check"></i> ${data.approver_name || '-'}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">From</div>
                        <div class="modal-info-value"><i class="fas fa-map-marker-alt"></i> ${data.location_from}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">To</div>
                        <div class="modal-info-value"><i class="fas fa-map-marker-alt"></i> ${data.location_to}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Time Out</div>
                        <div class="modal-info-value"><i class="fas fa-clock"></i> ${data.time_out}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Time In</div>
                        <div class="modal-info-value"><i class="fas fa-clock"></i> ${data.time_in}</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Start Mileage</div>
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${Math.round(parseFloat(data.start_kms)).toLocaleString('en-US')} KM</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">End Mileage</div>
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${Math.round(parseFloat(data.end_kms)).toLocaleString('en-US')} KM</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Total Distance</div>
                        <div class="modal-info-value" style="font-size: 18px; color: var(--primary-blue);"><i class="fas fa-route"></i> <strong>${Math.round(parseFloat(data.total_kms)).toLocaleString('en-US')} KM</strong></div>
                    </div>
                    <div class="modal-info-item full-width">
                        <div class="modal-info-label">Purpose</div>
                        <div class="modal-info-value"><i class="fas fa-tasks"></i> ${data.purpose}</div>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
        }

        function closeLogbookModal() {
            document.getElementById('logbookModal').classList.remove('active');
        }

        document.getElementById('approvalModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('logbookModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLogbookModal();
            }
        });

        document.addEventListener('click', function(e) {
            const filterDropdown = document.getElementById('filterDropdown');
            const filterIcon = document.querySelector('.filter-icon');
            
            if (!filterDropdown.contains(e.target) && !filterIcon.contains(e.target)) {
                filterDropdown.classList.remove('active');
            }
        });
    </script>
</body>
</html>
