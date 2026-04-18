<?php
// logbook.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$appRoot = dirname(__DIR__, 2);

require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/db_connect.php';
require_once $appRoot . '/facility_auth.php';

if (getCurrentRole() !== 'driver') {
    $_SESSION['error_message'] = 'Log Book is only available to drivers.';
    header('Location: dashboard.php');
    exit();
}

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();
$current_user_id = $_SESSION['user_id'];

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    die("Error: Your account is not assigned to a facility. Please contact your administrator.");
}

$message = '';
$messageType = '';

// Convert mysqli connection to PDO for this file
$pdo = new PDO("mysql:host=localhost;dbname=fuel", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Handle AJAX request for vehicle data
if (isset($_GET['action']) && $_GET['action'] === 'get_vehicle_mileage' && isset($_GET['vehicle_id'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ?");
    $stmt->execute([$_GET['vehicle_id']]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['current_mileage' => $vehicle['current_mileage'] ?? 0]);
    exit;
}

// Fetch user details
$stmt = $pdo->prepare("SELECT name, email, role FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_role = normalizeRole($user['role'] ?? 'driver');

// Handle form submission for new trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trip'])) {
    try {
        $startKmsRaw = trim((string) ($_POST['start_kms'] ?? ''));
        $endKmsRaw = trim((string) ($_POST['end_kms'] ?? ''));

        if (!preg_match('/^\d+$/', $startKmsRaw) || !preg_match('/^\d+$/', $endKmsRaw)) {
            throw new Exception('Start KMs and End KMs must be whole numbers.');
        }

        $startKms = (int) $startKmsRaw;
        $endKms = (int) $endKmsRaw;
        $total_kms = $endKms - $startKms;
        
        if ($total_kms <= 0) {
            throw new Exception('End KMs must be greater than Start KMs');
        }
        
        // Verify user has access to this vehicle
        if (!$is_super_admin) {
            $vehicleCheckStmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM vehicle_assignments 
                WHERE vehicle_id = ? AND user_id = ? AND is_active = 1
            ");
            $vehicleCheckStmt->execute([$_POST['vehicle_id'], $current_user_id]);
            $vehicleCheck = $vehicleCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($vehicleCheck['count'] == 0) {
                throw new Exception('You do not have access to this vehicle');
            }
        }
        
        // Get facility_id from user's session (will be auto-assigned by trigger, but we can set it explicitly)
        $facility_id = $is_super_admin ? null : $user_facility_id;
        
        $stmt = $pdo->prepare("
            INSERT INTO logbook (
                vehicle_id, driver_id, facility_id, log_date, purpose, location_from, 
                location_to, time_out, time_in, start_kms, end_kms, 
                total_kms, approver_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_POST['vehicle_id'],
            $current_user_id,
            $facility_id,
            $_POST['log_date'],
            $_POST['purpose'],
            $_POST['location_from'],
            $_POST['location_to'],
            $_POST['time_out'],
            $_POST['time_in'],
            $startKms,
            $endKms,
            $total_kms,
            $_POST['approver_id']
        ]);
        
        $message = "Trip log added successfully! Vehicle mileage updated.";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Error adding trip: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch approvers (facility admins only)
if ($is_super_admin) {
    // Super admin can select any facility admin
    $stmt = $pdo->query("
        SELECT DISTINCT u.id, u.name, f.facility_name
        FROM users u
        LEFT JOIN facilities f ON u.facility_id = f.id
        WHERE u.is_facility_admin = 1 OR u.role = 'facility_admin' OR u.role = 'admin' OR u.is_super_admin = 1
        ORDER BY u.name
    ");
} else {
    // Regular users can only select facility admins from their facility
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name
        FROM users u
        WHERE (u.is_facility_admin = 1 OR u.role = 'facility_admin' OR u.role = 'admin' OR u.is_super_admin = 1)
        AND (u.facility_id = ? OR u.is_super_admin = 1)
        ORDER BY u.name
    ");
    $stmt->execute([$user_facility_id]);
}
$approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch vehicles (only vehicles assigned to current user)
if ($is_super_admin) {
    // Super admin can see all vehicles
    $stmt = $pdo->query("SELECT id, vehicle_name, number_plate, current_mileage FROM vehicles ORDER BY vehicle_name");
} else {
    // Regular users can only see vehicles assigned to them
    $stmt = $pdo->prepare("
        SELECT DISTINCT v.id, v.vehicle_name, v.number_plate, v.current_mileage
        FROM vehicles v
        INNER JOIN vehicle_assignments va ON v.id = va.vehicle_id
        WHERE va.user_id = ? 
        AND va.is_active = 1
        AND v.facility_id = ?
        ORDER BY v.vehicle_name
    ");
    $stmt->execute([$current_user_id, $user_facility_id]);
}
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all log entries (filtered by facility and user role)
$logQuery = "
    SELECT 
        l.id,
        l.log_date,
        l.purpose,
        l.location_from,
        l.location_to,
        l.time_out,
        l.time_in,
        l.start_kms,
        l.end_kms,
        l.total_kms,
        v.vehicle_name,
        v.number_plate,
        driver.name as driver_name,
        approver.name as approver_name,
        f.facility_name
    FROM logbook l
    JOIN vehicles v ON l.vehicle_id = v.id
    JOIN users driver ON l.driver_id = driver.id
    LEFT JOIN users approver ON l.approver_id = approver.id
    LEFT JOIN facilities f ON l.facility_id = f.id
    WHERE 1=1";

$logParams = [];

// Apply facility filter
if (!$is_super_admin && $user_facility_id) {
    $logQuery .= " AND l.facility_id = ?";
    $logParams[] = $user_facility_id;
}

// Apply staff filter (staff can only see their own entries)
if ($user_role === 'driver') {
    $logQuery .= " AND l.driver_id = ?";
    $logParams[] = $current_user_id;
}

$logQuery .= " ORDER BY l.log_date DESC, l.created_at DESC";

$stmt = $pdo->prepare($logQuery);
$stmt->execute($logParams);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_logs = count($logs);
$total_distance = array_sum(array_column($logs, 'total_kms'));

$currentDate = date('Y-m-d');

// Get facility display name
if ($is_super_admin) {
    $facility_display = "All Facilities";
} else {
    $facility_query = "SELECT facility_name FROM facilities WHERE id = ?";
    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute([$user_facility_id]);
    $facility_row = $facility_stmt->fetch(PDO::FETCH_ASSOC);
    $facility_display = $facility_row ? $facility_row['facility_name'] : 'Your Facility';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Log Book</title>
    <?php require $appRoot . '/favicon_links.php'; ?>
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
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
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
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
            flex-wrap: wrap;
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

        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 20px;
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

        /* Staff View Notice */
        .staff-notice {
            background: rgba(59, 130, 246, 0.1);
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .staff-notice i {
            color: #2563eb;
            font-size: 20px;
        }

        .staff-notice-content {
            flex: 1;
        }

        .staff-notice-title {
            color: #1e40af;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .staff-notice-text {
            color: #3b82f6;
            font-size: 13px;
        }

        /* No Vehicles Warning */
        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 2px solid rgba(245, 158, 11, 0.3);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: center;
        }

        .warning-box i {
            font-size: 48px;
            color: #f59e0b;
            margin-bottom: 15px;
        }

        .warning-box h3 {
            color: #d97706;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .warning-box p {
            color: #92400e;
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
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

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 18px 36px rgba(22, 32, 42, 0.12);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--light-red);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }

        .stat-icon i {
            font-size: 22px;
            color: var(--primary-red);
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            color: var(--gray-900);
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
        }

        /* Card */
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .card-title {
            color: var(--gray-900);
            font-size: 22px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            color: var(--gray-700);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .required {
            color: var(--primary-red);
        }

        .form-input,
        .form-select {
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .form-input:read-only {
            background: var(--gray-100);
            cursor: not-allowed;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Log Entry */
        .log-entry {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            border: 2px solid var(--gray-200);
            transition: all 0.3s;
        }

        .log-entry:hover {
            border-color: var(--primary-red);
            box-shadow: 0 12px 24px rgba(22, 32, 42, 0.08);
            transform: translateY(-2px);
        }

        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }

        .log-title {
            font-weight: 700;
            color: var(--gray-900);
            font-size: 17px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .log-date {
            color: var(--gray-600);
            font-size: 14px;
            font-weight: 600;
        }

        .log-facility {
            font-size: 12px;
            font-weight: 600;
            color: #35627c;
            background: rgba(139, 92, 246, 0.1);
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 8px;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .log-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .detail-label {
            font-weight: 600;
            font-size: 12px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-value {
            color: var(--gray-900);
            font-weight: 600;
            font-size: 14px;
        }

        .detail-value.highlight {
            color: var(--primary-red);
            font-size: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 10px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .header {
                padding: 20px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .header h1 {
                font-size: 22px;
            }

            .back-btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 20px;
            }

            .log-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .log-details {
                grid-template-columns: 1fr;
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
                    <i class="fas fa-book"></i> Vehicle Log Book
                    <span class="facility-badge <?php echo $is_super_admin ? 'super-admin' : ''; ?>">
                        <i class="fas fa-<?php echo $is_super_admin ? 'crown' : 'building'; ?>"></i> 
                        <?php echo htmlspecialchars($facility_display); ?>
                    </span>
                </h1>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

   

        <!-- No Vehicles Warning -->
        <?php if (count($vehicles) === 0 && !$is_super_admin): ?>
        <div class="warning-box">
            <i class="fas fa-car-slash"></i>
            <h3>No Vehicles Assigned</h3>
            <p>You don't have any vehicles assigned to you yet. Please contact your Provincial Admin to get vehicle access.</p>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <!-- <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-list-alt"></i>
                </div>
                <div class="stat-label">
                    <?php if ($user_role === 'driver'): ?>
                        My Logs
                    <?php elseif (!$is_super_admin): ?>
                        Total Logs (Your Facility)
                    <?php else: ?>
                        Total Logs
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?php echo $total_logs; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-road"></i>
                </div>
                <div class="stat-label">
                    <?php if ($user_role === 'driver'): ?>
                        My Distance
                    <?php elseif (!$is_super_admin): ?>
                        Total Distance (Your Facility)
                    <?php else: ?>
                        Total Distance
                    <?php endif; ?>
                </div>
                <div class="stat-value" style="font-size: 24px;"><?php echo number_format($total_distance); ?> km</div>
            </div>
        </div> -->

        <!-- Add New Trip Form -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-plus-circle"></i>
                Add New Trip
            </h2>

            <?php if (count($vehicles) > 0): ?>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-car"></i> Vehicle <span class="required">*</span>
                        </label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>" data-mileage="<?php echo $vehicle['current_mileage']; ?>">
                                    <?php echo htmlspecialchars($vehicle['vehicle_name'] . ' - ' . $vehicle['number_plate']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar"></i> Date <span class="required">*</span>
                        </label>
                        <input type="date" name="log_date" class="form-input" value="<?php echo $currentDate; ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-clock"></i> Time Out <span class="required">*</span>
                        </label>
                        <input type="time" name="time_out" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-clock"></i> Time In <span class="required">*</span>
                        </label>
                        <input type="time" name="time_in" class="form-input" required>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-bullseye"></i> Purpose <span class="required">*</span>
                        </label>
                        <input type="text" name="purpose" class="form-input" placeholder="e.g., Client meeting, Delivery, etc." required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-map-marker-alt"></i> From <span class="required">*</span>
                        </label>
                        <input type="text" name="location_from" class="form-input" placeholder="Starting location" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-map-marker-alt"></i> To <span class="required">*</span>
                        </label>
                        <input type="text" name="location_to" class="form-input" placeholder="Destination" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-tachometer-alt"></i> Start KMs <span class="required">*</span>
                        </label>
                        <input type="number" name="start_kms" class="form-input" step="1" min="0" placeholder="e.g. 12000" required id="startKms" >
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-tachometer-alt"></i> End KMs <span class="required">*</span>
                        </label>
                        <input type="number" name="end_kms" class="form-input" step="1" min="0" placeholder="e.g. 12005" required id="endKms">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user-check"></i> Approver <span class="required">*</span>
                        </label>
                        <select name="approver_id" class="form-select" required>
                            <option value="">Select Approver</option>
                            <?php foreach ($approvers as $approver): ?>
                                <option value="<?php echo $approver['id']; ?>">
                                    <?php 
                                    echo htmlspecialchars($approver['name']);
                                    if ($is_super_admin && isset($approver['facility_name'])) {
                                        echo ' (' . htmlspecialchars($approver['facility_name']) . ')';
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 20px;">
                    <button type="submit" name="add_trip" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save Trip Log
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <i class="fas fa-car-slash"></i>
                <h3>Cannot Add Trip</h3>
                <p>No vehicles are assigned to you. Please contact your Provincial Admin.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Log Entries -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-history"></i>
                <?php if ($user_role === 'driver'): ?>
                    My Log Entries
                <?php elseif (!$is_super_admin): ?>
                    All Log Entries (Your Facility)
                <?php else: ?>
                    All Log Entries
                <?php endif; ?>
            </h2>

            <?php if (count($logs) > 0): ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-entry">
                        <div class="log-header">
                            <div>
                                <div class="log-title">
                                    <i class="fas fa-car"></i>
                                    <?php echo htmlspecialchars($log['vehicle_name'] . ' (' . $log['number_plate'] . ')'); ?>
                                </div>
                                <?php if ($is_super_admin && $log['facility_name']): ?>
                                    <div class="log-facility">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($log['facility_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="log-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d M Y', strtotime($log['log_date'])); ?>
                            </div>
                        </div>

                        <div class="log-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-user"></i> Driver</span>
                                <span class="detail-value"><?php echo htmlspecialchars($log['driver_name']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-bullseye"></i> Purpose</span>
                                <span class="detail-value"><?php echo htmlspecialchars($log['purpose']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i> From</span>
                                <span class="detail-value"><?php echo htmlspecialchars($log['location_from']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i> To</span>
                                <span class="detail-value"><?php echo htmlspecialchars($log['location_to']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i> Time Out</span>
                                <span class="detail-value"><?php echo date('h:i A', strtotime($log['time_out'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-clock"></i> Time In</span>
                                <span class="detail-value"><?php echo date('h:i A', strtotime($log['time_in'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-tachometer-alt"></i> Start KMs</span>
                                <span class="detail-value"><?php echo number_format((int) round((float) $log['start_kms'])); ?> km</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-tachometer-alt"></i> End KMs</span>
                                <span class="detail-value"><?php echo number_format((int) round((float) $log['end_kms'])); ?> km</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-road"></i> Total KMs</span>
                                <span class="detail-value highlight"><?php echo number_format((int) round((float) $log['total_kms'])); ?> km</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-user-check"></i> Approver</span>
                                <span class="detail-value"><?php echo htmlspecialchars($log['approver_name'] ?? 'Pending'); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h3>No Log Entries Yet</h3>
                    <p>
                        <?php if ($user_role === 'driver'): ?>
                            You haven't created any trip logs yet
                        <?php elseif (!$is_super_admin): ?>
                            No trip logs have been created in your facility yet
                        <?php else: ?>
                            No trip logs have been created yet
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-populate start_kms when vehicle is selected
        const vehicleSelect = document.getElementById('vehicleSelect');
        const startKmsInput = document.getElementById('startKms');
        const endKmsInput = document.getElementById('endKms');

        vehicleSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const mileage = selectedOption.getAttribute('data-mileage');
            
            if (mileage) {
                startKmsInput.value = Math.round(parseFloat(mileage)).toString();
                endKmsInput.value = ''; // Clear end kms when vehicle changes
            } else {
                startKmsInput.value = '';
                endKmsInput.value = '';
            }
        });

        // Validate that end_kms is greater than start_kms
        function validateKms() {
            const startKms = parseInt(startKmsInput.value || '0', 10) || 0;
            const endKms = parseInt(endKmsInput.value || '0', 10) || 0;
            
            if (endKms <= startKms) {
                endKmsInput.setCustomValidity('End KMs must be greater than Start KMs');
            } else {
                endKmsInput.setCustomValidity('');
            }
        }

        startKmsInput.addEventListener('input', validateKms);
        endKmsInput.addEventListener('input', validateKms);

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
