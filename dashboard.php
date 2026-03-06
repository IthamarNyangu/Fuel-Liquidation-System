<?php
// dashboard.php - Updated with logbook entries and improved UI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth_check.php';
require_once 'facility_auth.php';

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();

// Get user role and info from session
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'staff';
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';
$user_email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '';
$can_approve = in_array($user_role, ['super_admin', 'admin']);

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    error_log("Warning: User {$_SESSION['user_id']} with role {$user_role} has no facility assigned");
}

// Database configuration
require_once __DIR__ . '/db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Build facility filter
$facility_filter_sql = "";
$facility_filter_params = [];
if (!$is_super_admin && $user_facility_id) {
    $facility_filter_sql = " AND u.facility_id = :facility_id";
    $facility_filter_params[':facility_id'] = $user_facility_id;
}

// Build staff-only filter (staff can only see their own entries)
$staff_filter_sql = "";
$staff_filter_params = [];
if ($user_role === 'staff') {
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
    
    header("Location: dashboard.php?account=" . urlencode($_GET['account'] ?? 'All') . "&view=" . urlencode($_GET['view'] ?? 'all'));
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

// Fetch available years
$yearsQuery = "SELECT DISTINCT YEAR(r.request_date) as year 
               FROM requisitions r
               JOIN users u ON r.staff_id = u.id
               WHERE 1=1 " . $facility_filter_sql . $staff_filter_sql . " 
               UNION
               SELECT DISTINCT YEAR(l.log_date) as year
               FROM logbook l
               JOIN users u ON l.driver_id = u.id
               WHERE 1=1 " . $facility_filter_sql . $staff_filter_sql . "
               ORDER BY year DESC";
$yearsStmt = $pdo->prepare($yearsQuery);
foreach ($facility_filter_params as $key => $value) {
    $yearsStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $yearsStmt->bindValue($key, $value);
}
// Bind again for the second part of UNION
foreach ($facility_filter_params as $key => $value) {
    $yearsStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $yearsStmt->bindValue($key, $value);
}
$yearsStmt->execute();
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch vehicles with float accounts (filtered by facility)
$accountsQuery = "SELECT float_account_name, float_balance FROM vehicles WHERE float_account_name IS NOT NULL";
if (!$is_super_admin && $user_facility_id) {
    $accountsQuery .= " AND facility_id = :facility_id";
}
$accountsQuery .= " ORDER BY vehicle_name";
$accountsStmt = $pdo->prepare($accountsQuery);
if (!$is_super_admin && $user_facility_id) {
    $accountsStmt->bindValue(':facility_id', $user_facility_id);
}
$accountsStmt->execute();
$floatAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

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
    if (!$is_super_admin && $user_facility_id) {
        $accountQuery .= " AND facility_id = ?";
        $accountStmt = $pdo->prepare($accountQuery);
        $accountStmt->execute([$selectedAccount, $user_facility_id]);
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

// Fetch approved requisitions
$approvedQuery = "
    SELECT COUNT(*) as count, SUM(r.requested_amount * r.fuel_price_per_liter) as total 
    FROM requisitions r
    JOIN users u ON r.staff_id = u.id
    WHERE r.status = 'approved' AND r.request_date BETWEEN :start_date AND :end_date" . $facility_filter_sql . $staff_filter_sql;
$approvedStmt = $pdo->prepare($approvedQuery);
$approvedStmt->bindValue(':start_date', $startDate);
$approvedStmt->bindValue(':end_date', $endDate);
foreach ($facility_filter_params as $key => $value) {
    $approvedStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $approvedStmt->bindValue($key, $value);
}
$approvedStmt->execute();
$approved = $approvedStmt->fetch(PDO::FETCH_ASSOC);
$approvedCount = $approved['count'];
$approvedSum = $approved['total'] ?? 0;

// Fetch rejected requisitions
$rejectedQuery = "
    SELECT COUNT(*) as count, SUM(r.requested_amount * r.fuel_price_per_liter) as total 
    FROM requisitions r
    JOIN users u ON r.staff_id = u.id
    WHERE r.status = 'rejected' AND r.request_date BETWEEN :start_date AND :end_date" . $facility_filter_sql . $staff_filter_sql;
$rejectedStmt = $pdo->prepare($rejectedQuery);
$rejectedStmt->bindValue(':start_date', $startDate);
$rejectedStmt->bindValue(':end_date', $endDate);
foreach ($facility_filter_params as $key => $value) {
    $rejectedStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $rejectedStmt->bindValue($key, $value);
}
$rejectedStmt->execute();
$rejected = $rejectedStmt->fetch(PDO::FETCH_ASSOC);
$rejectedCount = $rejected['count'];
$rejectedSum = $rejected['total'] ?? 0;

// Fetch pending requisitions
$pendingQuery = "
    SELECT COUNT(*) as count, SUM(r.requested_amount * r.fuel_price_per_liter) as total 
    FROM requisitions r
    JOIN users u ON r.staff_id = u.id
    WHERE r.status = 'pending' AND r.request_date BETWEEN :start_date AND :end_date" . $facility_filter_sql . $staff_filter_sql;
$pendingStmt = $pdo->prepare($pendingQuery);
$pendingStmt->bindValue(':start_date', $startDate);
$pendingStmt->bindValue(':end_date', $endDate);
foreach ($facility_filter_params as $key => $value) {
    $pendingStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $pendingStmt->bindValue($key, $value);
}
$pendingStmt->execute();
$pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);
$pendingCount = $pending['count'];
$pendingSum = $pending['total'] ?? 0;

// Fetch logbook stats
$logbookStatsQuery = "
    SELECT COUNT(*) as count, SUM(l.total_kms) as total_kms
    FROM logbook l
    JOIN users u ON l.driver_id = u.id
    WHERE l.log_date BETWEEN :start_date AND :end_date" . $facility_filter_sql . $staff_filter_sql;
$logbookStatsStmt = $pdo->prepare($logbookStatsQuery);
$logbookStatsStmt->bindValue(':start_date', $startDate);
$logbookStatsStmt->bindValue(':end_date', $endDate);
foreach ($facility_filter_params as $key => $value) {
    $logbookStatsStmt->bindValue($key, $value);
}
foreach ($staff_filter_params as $key => $value) {
    $logbookStatsStmt->bindValue($key, $value);
}
$logbookStatsStmt->execute();
$logbookStats = $logbookStatsStmt->fetch(PDO::FETCH_ASSOC);
$logbookCount = $logbookStats['count'] ?? 0;
$logbookTotalKms = $logbookStats['total_kms'] ?? 0;

// Fetch requisitions
$requisitions = [];
if ($viewMode === 'all' || $viewMode === 'requisitions') {
    $query = "
        SELECT 
            r.id,
            'fuel_request' as entry_type,
            u.name as staff_name,
            v.vehicle_name,
            v.number_plate,
            r.requested_amount,
            r.fuel_price_per_liter,
            r.float_account,
            r.activity_name,
            r.mileage,
            r.filling_station,
            r.receipt_number,
            r.receipt_filename,
            r.receipt_type,
            r.request_date,
            r.request_time,
            r.notes,
            r.status,
            (r.requested_amount * r.fuel_price_per_liter) as total_cost,
            approver.name as approver_name,
            r.created_at
        FROM requisitions r
        JOIN users u ON r.staff_id = u.id
        JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN users approver ON r.approver_id = approver.id
        WHERE 1=1" . $facility_filter_sql . $staff_filter_sql;

    $params = array_merge($facility_filter_params, $staff_filter_params);

    if ($filterStatus) {
        $query .= " AND r.status = :filter_status";
        $params[':filter_status'] = $filterStatus;
    }
    if ($filterStaff) {
        $query .= " AND u.name LIKE :filter_staff";
        $params[':filter_staff'] = "%$filterStaff%";
    }
    if ($filterVehicle) {
        $query .= " AND (v.vehicle_name LIKE :filter_vehicle1 OR v.number_plate LIKE :filter_vehicle2)";
        $params[':filter_vehicle1'] = "%$filterVehicle%";
        $params[':filter_vehicle2'] = "%$filterVehicle%";
    }
    if ($filterMonth && $filterYear) {
        $query .= " AND DATE_FORMAT(r.request_date, '%Y-%m') = :filter_month_year";
        $params[':filter_month_year'] = $filterYear . '-' . $filterMonth;
    } elseif ($filterMonth) {
        $query .= " AND MONTH(r.request_date) = :filter_month";
        $params[':filter_month'] = $filterMonth;
    } elseif ($filterYear) {
        $query .= " AND YEAR(r.request_date) = :filter_year";
        $params[':filter_year'] = $filterYear;
    }
    if ($filterAmount) {
        $query .= " AND r.requested_amount >= :filter_amount";
        $params[':filter_amount'] = $filterAmount;
    }

    // Sorting
    if ($sortBy === 'amount') {
        $query .= " ORDER BY r.requested_amount " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } elseif ($sortBy === 'price') {
        $query .= " ORDER BY r.fuel_price_per_liter " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } elseif ($sortBy === 'total') {
        $query .= " ORDER BY (r.requested_amount * r.fuel_price_per_liter) " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } else {
        $query .= " ORDER BY r.created_at DESC";
    }

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch logbook entries
$logbookEntries = [];
if ($viewMode === 'all' || $viewMode === 'logbook') {
    // Build facility filter for logbook
    $logbook_facility_filter = "";
    $logbook_params = [];
    if (!$is_super_admin && $user_facility_id) {
        $logbook_facility_filter = " AND u.facility_id = :facility_id";
        $logbook_params[':facility_id'] = $user_facility_id;
    }
    
    // Build staff-only filter for logbook (check driver_id)
    $logbook_staff_filter = "";
    if ($user_role === 'staff') {
        $logbook_staff_filter = " AND u.id = :staff_user_id";
        $logbook_params[':staff_user_id'] = $_SESSION['user_id'];
    }

    $logbookQuery = "
        SELECT 
            l.id,
            'logbook' as entry_type,
            l.log_date,
            l.purpose,
            l.location_from,
            l.location_to,
            l.time_out,
            l.time_in,
            l.start_kms,
            l.end_kms,
            l.total_kms,
            driver.name as driver_name,
            v.vehicle_name,
            v.number_plate,
            approver.name as approver_name,
            l.created_at
        FROM logbook l
        JOIN users driver ON l.driver_id = driver.id
        JOIN vehicles v ON l.vehicle_id = v.id
        LEFT JOIN users approver ON l.approver_id = approver.id
        JOIN users u ON l.driver_id = u.id
        WHERE 1=1" . $logbook_facility_filter . $logbook_staff_filter;

    if ($filterStaff) {
        $logbookQuery .= " AND driver.name LIKE :filter_staff";
        $logbook_params[':filter_staff'] = "%$filterStaff%";
    }
    if ($filterVehicle) {
        $logbookQuery .= " AND (v.vehicle_name LIKE :filter_vehicle1 OR v.number_plate LIKE :filter_vehicle2)";
        $logbook_params[':filter_vehicle1'] = "%$filterVehicle%";
        $logbook_params[':filter_vehicle2'] = "%$filterVehicle%";
    }
    if ($filterMonth && $filterYear) {
        $logbookQuery .= " AND DATE_FORMAT(l.log_date, '%Y-%m') = :filter_month_year";
        $logbook_params[':filter_month_year'] = $filterYear . '-' . $filterMonth;
    } elseif ($filterMonth) {
        $logbookQuery .= " AND MONTH(l.log_date) = :filter_month";
        $logbook_params[':filter_month'] = $filterMonth;
    } elseif ($filterYear) {
        $logbookQuery .= " AND YEAR(l.log_date) = :filter_year";
        $logbook_params[':filter_year'] = $filterYear;
    }

    // Sorting for logbook
    if ($sortBy === 'kms') {
        $logbookQuery .= " ORDER BY l.total_kms " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC');
    } else {
        $logbookQuery .= " ORDER BY l.created_at DESC";
    }

    $logbookStmt = $pdo->prepare($logbookQuery);
    foreach ($logbook_params as $key => $value) {
        $logbookStmt->bindValue($key, $value);
    }
    $logbookStmt->execute();
    $logbookEntries = $logbookStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Combine and sort entries if viewing all
$allEntries = [];
if ($viewMode === 'all') {
    $allEntries = array_merge($requisitions, $logbookEntries);
    usort($allEntries, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
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

// Get facility name for display
if ($is_super_admin) {
    $facility_display = "All Facilities";
} else {
    $facility_query = "SELECT facility_name FROM facilities WHERE id = ?";
    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute([$user_facility_id]);
    $facility_row = $facility_stmt->fetch(PDO::FETCH_ASSOC);
    $facility_display = $facility_row ? $facility_row['facility_name'] : "Your Facility";
}

// Get user initials for avatar
$user_initials = strtoupper(substr($user_name, 0, 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Liquidation Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="dashboard.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/dashboard.css')); ?>">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-gas-pump"></i><span>Fuel System</span></h2>
                <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            </div>
            <ul class="menu">
                <li><a href="dashboard.php" class="active"><span class="menu-icon"><i class="fas fa-home"></i></span><span class="menu-text">Dashboard</span></a></li>
                <li><a href="reports.php"><span class="menu-icon"><i class="fas fa-chart-line"></i></span><span class="menu-text">Reports</span></a></li>
                <li><a href="weekly_report.php"><span class="menu-icon"><i class="fas fa-file-alt"></i></span><span class="menu-text">Weekly Report</span></a></li>
                <li><a href="logbook.php"><span class="menu-icon"><i class="fas fa-book"></i></span><span class="menu-text">Log Book</span></a></li>
                <li><a href="request.php"><span class="menu-icon"><i class="fas fa-gas-pump"></i></span><span class="menu-text">Request Fuel</span></a></li>
                <?php if ($can_approve): ?>
                <li><a href="users.php"><span class="menu-icon"><i class="fas fa-users"></i></span><span class="menu-text">Users</span></a></li>
                <li><a href="manage_vehicles.php"><span class="menu-icon"><i class="fas fa-car"></i></span><span class="menu-text">Vehicles</span></a></li>
                <!-- <li><a href="settings.php"><span class="menu-icon"><i class="fas fa-cog"></i></span><span class="menu-text">Settings</span></a></li> -->
                <?php endif; ?>
                <?php if ($is_super_admin): ?>
                <li><a href="manage_facilities.php"><span class="menu-icon"><i class="fas fa-building"></i></span><span class="menu-text">Facilities</span></a></li>
                <?php endif; ?>
                <?php if ($can_approve): ?>
                <li><a href="fuel_topup.php"><span class="menu-icon"><i class="fas fa-droplet"></i></span><span class="menu-text">Fuel Card Top Up</span></a></li>
                <li><a href="fuelset.php"><span class="menu-icon"><i class="fas fa-sliders"></i></span><span class="menu-text">Fuel Price Set</span></a></li>
                <?php endif; ?>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <div>
                    <h1>
                        <i class="fas fa-tachometer-alt"></i> Dashboard Overview
                        <span class="facility-badge <?php echo $is_super_admin ? 'super-admin' : ''; ?>">
                            <i class="fas fa-<?php echo $is_super_admin ? 'crown' : 'building'; ?>"></i> 
                            <?php echo htmlspecialchars($facility_display); ?>
                        </span>
                    </h1>
                </div>
                <div class="user-header">
                    <div class="user-info-header">
                        <div class="user-avatar-header"><?php echo $user_initials; ?></div>
                        <div class="user-details-header">
                            <div class="user-name-header"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="user-role-header"><?php echo ucwords(str_replace('_', ' ', $user_role)); ?></div>
                        </div>
                    </div>
                    <a href="logout.php" class="btn-logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Vehicle Float Account Selector -->
            <div class="account-selector">
                <label for="accountSelect"><i class="fas fa-wallet"></i> Vehicle Float Account:</label>
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

            <!-- Stats Cards -->
            <div class="cards">
                <div class="card">
                    <i class="fas fa-gas-pump card-icon"></i>
                    <h3><i class="fas fa-droplet"></i> Total Float Balance</h3>
                    <div class="card-value">K <?php echo number_format($displayFloat, 2); ?></div>
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

            <?php if ($user_role === 'staff'): ?>
            <?php endif; ?>

            <!-- View Toggle -->
            <div class="view-toggle">
                <button class="view-tab <?php echo $viewMode === 'all' ? 'active' : ''; ?>" onclick="changeView('all')">
                    <i class="fas fa-th-list"></i> All Entries
                </button>
                <button class="view-tab <?php echo $viewMode === 'requisitions' ? 'active' : ''; ?>" onclick="changeView('requisitions')">
                    <i class="fas fa-gas-pump"></i> Fuel Requests
                </button>
                <button class="view-tab <?php echo $viewMode === 'logbook' ? 'active' : ''; ?>" onclick="changeView('logbook')">
                    <i class="fas fa-book"></i> Logbook
                </button>
            </div>

            <!-- Entries List -->
            <div class="requests-container">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-list-alt"></i>
                        <?php 
                        if ($viewMode === 'requisitions') {
                            echo 'Recent Requisitions';
                        } elseif ($viewMode === 'logbook') {
                            echo 'Recent Logbook Entries';
                        } else {
                            echo 'All Recent Entries';
                        }
                        ?>
                    </h2>
                    <div class="filter-controls">
                        <?php if ($viewMode !== 'logbook'): ?>
                        <select class="sort-select" onchange="sortTable(this.value)">
                            <option value="">Sort by...</option>
                            <option value="amount_desc" <?php echo ($sortBy === 'amount' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Amount ↓</option>
                            <option value="amount_asc" <?php echo ($sortBy === 'amount' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Amount ↑</option>
                            <option value="price_desc" <?php echo ($sortBy === 'price' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Price ↓</option>
                            <option value="price_asc" <?php echo ($sortBy === 'price' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Price ↑</option>
                            <option value="total_desc" <?php echo ($sortBy === 'total' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Total ↓</option>
                            <option value="total_asc" <?php echo ($sortBy === 'total' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Total ↑</option>
                        </select>
                        <?php else: ?>
                        <select class="sort-select" onchange="sortTable(this.value)">
                            <option value="">Sort by...</option>
                            <option value="kms_desc" <?php echo ($sortBy === 'kms' && $sortOrder === 'DESC') ? 'selected' : ''; ?>>Distance ↓</option>
                            <option value="kms_asc" <?php echo ($sortBy === 'kms' && $sortOrder === 'ASC') ? 'selected' : ''; ?>>Distance ↑</option>
                        </select>
                        <?php endif; ?>
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
                                    <input type="hidden" name="month" id="selectedMonth" value="<?php echo htmlspecialchars($filterMonth); ?>">
                                    <input type="hidden" name="year" id="selectedYear" value="<?php echo htmlspecialchars($filterYear); ?>">
                                    
                                    <div class="filter-section">
                                        <div class="filter-group">
                                            <label><i class="fas fa-user"></i> Staff/Driver</label>
                                            <input type="text" name="staff" placeholder="Search by name" value="<?php echo htmlspecialchars($filterStaff); ?>">
                                        </div>
                                        <div class="filter-group">
                                            <label><i class="fas fa-car"></i> Vehicle</label>
                                            <input type="text" name="vehicle" placeholder="Search vehicle" value="<?php echo htmlspecialchars($filterVehicle); ?>">
                                        </div>
                                        <?php if ($viewMode !== 'logbook'): ?>
                                        <div class="filter-group">
                                            <label><i class="fas fa-dollar-sign"></i> Min Amount (L)</label>
                                            <input type="number" name="amount" step="0.01" placeholder="Minimum amount" value="<?php echo htmlspecialchars($filterAmount); ?>">
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
                                        <?php echo $isLogbook ? 'Logbook' : 'Requisition'; ?>
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
                                        <i class="fas fa-user"></i> <?php echo $isLogbook ? 'Driver' : 'Requested By'; ?>
                                    </span>
                                    <span class="summary-meta-value"><?php echo htmlspecialchars($isLogbook ? ($entry['driver_name'] ?? 'Unknown') : ($entry['staff_name'] ?? 'Unknown')); ?></span>
                                </div>
                                <div class="summary-meta">
                                    <span class="summary-meta-label"><i class="fas fa-calendar"></i> Date</span>
                                    <span class="summary-meta-value"><?php echo date('d M Y', strtotime($isLogbook ? $entry['log_date'] : $entry['request_date'])); ?></span>
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
                                    <span class="detail-value highlight-blue"><?php echo number_format($entry['total_kms'], 2); ?> KM</span>
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
                                    <span class="detail-label"><i class="fas fa-droplet"></i> Amount</span>
                                    <span class="detail-value highlight"><?php echo number_format($entry['requested_amount'], 2); ?> L</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-money-bill-wave"></i> Total Cost</span>
                                    <span class="detail-value highlight">K <?php echo number_format($entry['total_cost'], 2); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-wallet"></i> Float Account</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['float_account'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-tasks"></i> Activity</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['activity_name'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Filling Station</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['filling_station'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-user-check"></i> Approver</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['approver_name'] ?? '-'); ?></span>
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
                            if ($user_role === 'staff') {
                                echo ' in your personal records';
                            } elseif (!$is_super_admin) {
                                echo ' in your facility';
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
            const view = url.searchParams.get('view') || 'all';
            window.location.href = 'dashboard.php?view=' + view;
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
                        <div class="modal-info-label">Staff</div>
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
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${data.mileage ? parseFloat(data.mileage).toFixed(1) + ' KM' : '-'}</div>
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
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${parseFloat(data.start_kms).toFixed(2)} KM</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">End Mileage</div>
                        <div class="modal-info-value"><i class="fas fa-tachometer-alt"></i> ${parseFloat(data.end_kms).toFixed(2)} KM</div>
                    </div>
                    <div class="modal-info-item">
                        <div class="modal-info-label">Total Distance</div>
                        <div class="modal-info-value" style="font-size: 18px; color: var(--primary-blue);"><i class="fas fa-route"></i> <strong>${parseFloat(data.total_kms).toFixed(2)} KM</strong></div>
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
