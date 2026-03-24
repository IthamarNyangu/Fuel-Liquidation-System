<?php
// user_dashboard.php
require_once 'db_config.php';

// TODO: Replace with session after implementing login
// Example: $current_user_id = $_SESSION['user_id'];
$current_user_id = 3; // Temporary - Change this to test different users

// Fetch user details
$stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch ALL requisitions from all users
$stmt = $pdo->prepare("
    SELECT 
        r.id,
        'fuel_request' as entry_type,
        v.vehicle_name,
        v.number_plate,
        r.requested_amount,
        r.fuel_price_per_liter,
        r.float_account,
        r.activity_name as purpose,
        r.mileage,
        r.filling_station,
        r.receipt_number,
        r.request_date as entry_date,
        r.request_time,
        NULL as time_in,
        NULL as location_from,
        NULL as location_to,
        NULL as start_kms,
        NULL as end_kms,
        NULL as total_kms,
        r.notes,
        r.status,
        (r.requested_amount * r.fuel_price_per_liter) as total_cost,
        approver.name as approver_name,
        staff.name as staff_name,
        r.created_at
    FROM requisitions r
    JOIN vehicles v ON r.vehicle_id = v.id
    LEFT JOIN users approver ON r.approver_id = approver.id
    LEFT JOIN users staff ON r.staff_id = staff.id
");
$stmt->execute();
$fuel_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ALL logbook entries
$stmt = $pdo->prepare("
    SELECT 
        l.id,
        'logbook_entry' as entry_type,
        v.vehicle_name,
        v.number_plate,
        NULL as requested_amount,
        NULL as fuel_price_per_liter,
        NULL as float_account,
        l.purpose,
        NULL as mileage,
        NULL as filling_station,
        NULL as receipt_number,
        l.log_date as entry_date,
        l.time_out as request_time,
        l.time_in,
        l.location_from,
        l.location_to,
        l.start_kms,
        l.end_kms,
        l.total_kms,
        NULL as notes,
        'completed' as status,
        NULL as total_cost,
        approver.name as approver_name,
        driver.name as staff_name,
        l.created_at
    FROM logbook l
    JOIN vehicles v ON l.vehicle_id = v.id
    LEFT JOIN users approver ON l.approver_id = approver.id
    LEFT JOIN users driver ON l.driver_id = driver.id
");
$stmt->execute();
$logbook_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge both arrays
$all_entries = array_merge($fuel_requests, $logbook_entries);

// Sort by date descending
usort($all_entries, function($a, $b) {
    $dateCompare = strtotime($b['entry_date']) - strtotime($a['entry_date']);
    if ($dateCompare === 0) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    }
    return $dateCompare;
});

// Calculate statistics
$total_requests = count($fuel_requests);
$total_logbook_entries = count($logbook_entries);
$approved_count = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'approved'));
$pending_count = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'pending'));
$rejected_count = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'rejected'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Fuel Requests</title>
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
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, rgba(31, 52, 67, 0.98) 0%, rgba(41, 72, 93, 0.98) 56%, rgba(53, 98, 124, 0.96) 100%);
            backdrop-filter: blur(20px);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            transition: all 0.3s;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar-header {
            padding: 25px 20px;
            background: rgba(31, 52, 67, 0.94);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .logo-text {
            color: white;
            font-size: 18px;
            font-weight: 700;
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .logo-text {
            opacity: 0;
            display: none;
        }

        .toggle-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .toggle-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .user-profile {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .user-details {
            flex: 1;
            overflow: hidden;
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .user-details {
            opacity: 0;
            display: none;
        }

        .user-name {
            color: white;
            font-weight: 700;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }

        .user-role {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            font-weight: 500;
        }

        .menu {
            list-style: none;
            padding: 20px 10px;
        }

        .menu li {
            margin-bottom: 6px;
        }

        .menu a {
            display: flex;
            align-items: center;
            padding: 14px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 600;
        }

        .menu a:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .menu a.active {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .menu-icon {
            font-size: 20px;
            min-width: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-text {
            margin-left: 12px;
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .menu-text {
            opacity: 0;
            display: none;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: margin-left 0.3s;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: 80px;
        }

        .content-header {
            margin-bottom: 30px;
        }

        .page-title {
            color: var(--gray-900);
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 16px;
            font-weight: 500;
        }

        /* Stats Cards */
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

        /* Requests Card */
        .requests-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            position: relative;
            overflow: hidden;
        }

        .requests-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .card-title {
            color: var(--gray-900);
            font-size: 22px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Request Item */
        .request-item {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            border: 2px solid var(--gray-200);
            transition: all 0.3s;
        }

        .request-item:hover {
            border-color: var(--primary-red);
            box-shadow: 0 12px 24px rgba(22, 32, 42, 0.08);
            transform: translateY(-2px);
        }

        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }

        .request-title {
            font-weight: 700;
            color: var(--gray-900);
            font-size: 17px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .status-approved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-completed {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .entry-type-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 8px;
        }

        .badge-fuel-request {
            background: rgba(220, 38, 38, 0.15);
            color: var(--primary-red);
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .badge-logbook-entry {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .request-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .notes-section {
            grid-column: 1 / -1;
            margin-top: 10px;
            padding: 15px;
            background: var(--gray-50);
            border-radius: 12px;
            border: 1px solid var(--gray-200);
        }

        .notes-label {
            font-weight: 700;
            font-size: 12px;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .notes-value {
            color: var(--gray-700);
            font-size: 14px;
            line-height: 1.6;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
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

        .empty-state p {
            font-size: 14px;
            margin-bottom: 25px;
        }

        .btn-new-request {
            padding: 12px 28px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-new-request:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar.collapsed ~ .main-content {
                margin-left: 0;
            }

            /* Mobile menu button */
            .mobile-menu-btn {
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 56px;
                height: 56px;
                background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
                border-radius: 50%;
                border: none;
                color: white;
                font-size: 20px;
                box-shadow: 0 4px 20px rgba(220, 38, 38, 0.4);
                cursor: pointer;
                z-index: 999;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s;
            }

            .mobile-menu-btn:hover {
                transform: scale(1.1);
            }

            .page-title {
                font-size: 24px;
                flex-wrap: wrap;
            }

            .page-subtitle {
                font-size: 14px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-value {
                font-size: 28px;
            }

            .requests-card {
                padding: 20px;
                border-radius: 16px;
            }

            .card-title {
                font-size: 18px;
            }

            .request-item {
                padding: 16px;
            }

            .request-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .request-title {
                font-size: 15px;
                word-break: break-word;
            }

            .request-details {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .detail-label {
                font-size: 11px;
            }

            .detail-value {
                font-size: 13px;
            }

            .detail-value.highlight {
                font-size: 15px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
            }

            .content-header {
                margin-bottom: 20px;
            }

            .page-title {
                font-size: 20px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
            }

            .stat-icon i {
                font-size: 18px;
            }

            .stat-label {
                font-size: 11px;
            }

            .stat-value {
                font-size: 24px;
            }

            .requests-card {
                padding: 15px;
            }

            .request-item {
                padding: 12px;
            }

            .status-badge {
                font-size: 10px;
                padding: 6px 12px;
            }
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" onclick="toggleMobileSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon"><i class="fas fa-gas-pump"></i></div>
                    <span class="logo-text">Fuel System</span>
                </div>
                <button class="toggle-btn" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

            <div class="user-profile">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                        <div class="user-role">Driver</div>
                    </div>
                </div>
            </div>

            <ul class="menu">
                <li>
                    <a href="user_dashboard.php" class="active">
                        <i class="fas fa-th-large menu-icon"></i>
                        <span class="menu-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="logbook.php">
                        <i class="fas fa-book menu-icon"></i>
                        <span class="menu-text">Log Book</span>
                    </a>
                </li>
                <li>
                    <a href="request.php">
                        <i class="fas fa-plus-circle menu-icon"></i>
                        <span class="menu-text">New Request</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-user menu-icon"></i>
                        <span class="menu-text">Profile</span>
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-sign-out-alt menu-icon"></i>
                        <span class="menu-text">Logout</span>
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <h1 class="page-title">
                    <i class="fas fa-clipboard-list"></i>
                    All Fuel Requests & Logbook Entries
                </h1>
                <p class="page-subtitle">View and track all fuel requisitions and vehicle logbook entries</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-label">Fuel Requests</div>
                    <div class="stat-value"><?php echo $total_requests; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(59, 130, 246, 0.2);">
                        <i class="fas fa-book" style="color: #3b82f6;"></i>
                    </div>
                    <div class="stat-label">Logbook Entries</div>
                    <div class="stat-value" style="color: #3b82f6;"><?php echo $total_logbook_entries; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.2);">
                        <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    </div>
                    <div class="stat-label">Approved</div>
                    <div class="stat-value" style="color: #10b981;"><?php echo $approved_count; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.2);">
                        <i class="fas fa-clock" style="color: #f59e0b;"></i>
                    </div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value" style="color: #f59e0b;"><?php echo $pending_count; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.2);">
                        <i class="fas fa-times-circle" style="color: #ef4444;"></i>
                    </div>
                    <div class="stat-label">Rejected</div>
                    <div class="stat-value" style="color: #ef4444;"><?php echo $rejected_count; ?></div>
                </div>
            </div>

            <!-- Requests List -->
            <div class="requests-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-list-alt"></i>
                        All Entries
                    </h2>
                </div>

                <?php if (count($all_entries) > 0): ?>
                    <?php foreach ($all_entries as $entry): ?>
                    <div class="request-item">
                        <div class="request-header">
                            <div class="request-title">
                                <i class="fas fa-car"></i>
                                <?php echo htmlspecialchars($entry['vehicle_name'] . ' (' . $entry['number_plate'] . ')'); ?>
                                <?php if ($entry['entry_type'] === 'fuel_request'): ?>
                                    <span class="entry-type-badge badge-fuel-request">
                                        <i class="fas fa-gas-pump"></i> Fuel Request
                                    </span>
                                <?php else: ?>
                                    <span class="entry-type-badge badge-logbook-entry">
                                        <i class="fas fa-book"></i> Logbook Entry
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($entry['entry_type'] === 'fuel_request'): ?>
                                <span class="status-badge status-<?php echo $entry['status']; ?>">
                                    <i class="fas fa-<?php echo $entry['status'] === 'approved' ? 'check-circle' : ($entry['status'] === 'rejected' ? 'times-circle' : 'clock'); ?>"></i>
                                    <?php echo ucfirst($entry['status']); ?>
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-completed">
                                    <i class="fas fa-check-circle"></i>
                                    Completed
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="request-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-user"></i> <?php echo $entry['entry_type'] === 'fuel_request' ? 'Requested By' : 'Driver'; ?></span>
                                <span class="detail-value"><?php echo htmlspecialchars($entry['staff_name'] ?? 'Unknown'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-calendar"></i> Date</span>
                                <span class="detail-value"><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></span>
                            </div>
                            
                            <?php if ($entry['entry_type'] === 'fuel_request'): ?>
                                <!-- Fuel Request Specific Fields -->
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
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['purpose'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Filling Station</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['filling_station'] ?? '-'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-receipt"></i> Receipt Number</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['receipt_number'] ?? '-'); ?></span>
                                </div>
                            <?php else: ?>
                                <!-- Logbook Entry Specific Fields -->
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-bullseye"></i> Purpose</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['purpose']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> From</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['location_from']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> To</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($entry['location_to']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-clock"></i> Time Out</span>
                                    <span class="detail-value"><?php echo date('h:i A', strtotime($entry['request_time'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-clock"></i> Time In</span>
                                    <span class="detail-value"><?php echo date('h:i A', strtotime($entry['time_in'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-tachometer-alt"></i> Start KMs</span>
                                    <span class="detail-value"><?php echo number_format($entry['start_kms'], 2); ?> km</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-tachometer-alt"></i> End KMs</span>
                                    <span class="detail-value"><?php echo number_format($entry['end_kms'], 2); ?> km</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-road"></i> Total KMs</span>
                                    <span class="detail-value highlight"><?php echo number_format($entry['total_kms'], 2); ?> km</span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-user-check"></i> Approver</span>
                                <span class="detail-value"><?php echo htmlspecialchars($entry['approver_name'] ?? '-'); ?></span>
                            </div>
                        </div>
                        
                        <?php if ($entry['entry_type'] === 'fuel_request' && $entry['notes']): ?>
                        <div class="notes-section">
                            <div class="notes-label">
                                <i class="fas fa-comment-alt"></i> Admin Notes
                            </div>
                            <div class="notes-value">
                                <?php echo htmlspecialchars($entry['notes']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Entries Yet</h3>
                        <p>No fuel requisitions or logbook entries have been submitted yet</p>
                        <a href="request.php" class="btn-new-request">
                            <i class="fas fa-plus-circle"></i>
                            Create New Request
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on a menu item on mobile
        document.querySelectorAll('.menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleMobileSidebar();
                }
            });
        });
    </script>
</body>
</html>