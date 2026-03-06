<?php
// manage_facilities.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Require admin authentication
session_start();
require_once 'db_connect.php';

// Check if user is logged in and is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'])) {
    header("Location: login.php");
    exit();
}

$logged_in_user_id = $_SESSION['user_id'];
$logged_in_user_name = $_SESSION['user_name'];
$logged_in_user_role = $_SESSION['user_role'];

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $facility_name = trim($_POST['facility_name']);
                    $facility_code = trim($_POST['facility_code']);
                    $location = trim($_POST['location']);
                    $address = trim($_POST['address']);
                    $phone = trim($_POST['phone']);
                    $email = trim($_POST['email']);
                    
                    // Check if facility code already exists
                    $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE facility_code = ?");
                    $check_stmt->bind_param("s", $facility_code);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        throw new Exception("Facility code already exists. Please use a unique code.");
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO facilities (facility_name, facility_code, location, address, phone, email, created_by, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->bind_param("ssssssi", $facility_name, $facility_code, $location, $address, $phone, $email, $logged_in_user_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Facility '{$facility_name}' added successfully!";
                        header("Location: manage_facilities.php");
                        exit();
                    }
                    break;
                    
                case 'edit':
                    $facility_id = $_POST['facility_id'];
                    $facility_name = trim($_POST['facility_name']);
                    $facility_code = trim($_POST['facility_code']);
                    $location = trim($_POST['location']);
                    $address = trim($_POST['address']);
                    $phone = trim($_POST['phone']);
                    $email = trim($_POST['email']);
                    
                    // Check if facility code exists for other facilities
                    $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE facility_code = ? AND id != ?");
                    $check_stmt->bind_param("si", $facility_code, $facility_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        throw new Exception("Facility code already exists. Please use a unique code.");
                    }
                    
                    $stmt = $conn->prepare("
                        UPDATE facilities 
                        SET facility_name = ?, facility_code = ?, location = ?, address = ?, phone = ?, email = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssssssi", $facility_name, $facility_code, $location, $address, $phone, $email, $facility_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Facility '{$facility_name}' updated successfully!";
                        header("Location: manage_facilities.php");
                        exit();
                    }
                    break;
                    
                case 'toggle_status':
                    $facility_id = $_POST['facility_id'];
                    $new_status = $_POST['new_status'];
                    
                    $stmt = $conn->prepare("UPDATE facilities SET is_active = ? WHERE id = ?");
                    $stmt->bind_param("ii", $new_status, $facility_id);
                    
                    if ($stmt->execute()) {
                        $status_text = $new_status ? 'activated' : 'deactivated';
                        $_SESSION['success_message'] = "Facility {$status_text} successfully!";
                        header("Location: manage_facilities.php");
                        exit();
                    }
                    break;
                    
                case 'delete':
                    $facility_id = $_POST['facility_id'];
                    
                    // Check if facility has associated records
                    $check_stmt = $conn->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM users WHERE facility_id = ?) as user_count,
                            (SELECT COUNT(*) FROM vehicles WHERE facility_id = ?) as vehicle_count
                    ");
                    $check_stmt->bind_param("ii", $facility_id, $facility_id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result()->fetch_assoc();
                    
                    if ($result['user_count'] > 0 || $result['vehicle_count'] > 0) {
                        throw new Exception("Cannot delete facility with associated users or vehicles. Please reassign them first.");
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM facilities WHERE id = ?");
                    $stmt->bind_param("i", $facility_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Facility deleted successfully!";
                        header("Location: manage_facilities.php");
                        exit();
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get success message from session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    $messageType = 'success';
    unset($_SESSION['success_message']);
}

// Fetch all facilities with statistics
$facilities_query = "
    SELECT 
        f.*,
        COALESCE(fs.total_users, 0) as total_users,
        COALESCE(fs.total_vehicles, 0) as total_vehicles,
        COALESCE(fs.total_requisitions, 0) as total_requisitions,
        COALESCE(fs.total_logbook_entries, 0) as total_logbook_entries,
        COALESCE(fs.total_facility_admins, 0) as total_facility_admins,
        u.name as created_by_name
    FROM facilities f
    LEFT JOIN facility_stats fs ON f.id = fs.facility_id
    LEFT JOIN users u ON f.created_by = u.id
    ORDER BY f.is_active DESC, f.facility_name ASC
";
$facilities = $conn->query($facilities_query)->fetch_all(MYSQLI_ASSOC);

// Count statistics
$total_facilities = count($facilities);
$active_facilities = count(array_filter($facilities, fn($f) => $f['is_active'] == 1));
$inactive_facilities = $total_facilities - $active_facilities;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Facilities - Fuel Liquidation System</title>
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            color: var(--gray-900);
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-name-badge {
            background: var(--primary-red);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .back-btn, .add-btn {
            padding: 10px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }

        .back-btn {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
        }

        .back-btn:hover {
            background: white;
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        .add-btn {
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            color: white;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
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
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.red {
            background: var(--light-red);
            color: var(--primary-red);
        }

        .stat-icon.green {
            background: #dcfce7;
            color: #16a34a;
        }

        .stat-icon.gray {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .stat-content h3 {
            font-size: 32px;
            font-weight: 800;
            color: var(--gray-900);
        }

        .stat-content p {
            font-size: 13px;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Table Card */
        .table-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(220, 38, 38, 0.18);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h2 {
            font-size: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 8px 15px 8px 40px;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 600;
            width: 250px;
        }

        .search-box input::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--gray-50);
        }

        th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 700;
            font-size: 12px;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-200);
        }

        td {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-800);
        }

        tr:hover {
            background: var(--gray-50);
        }

        .facility-name {
            font-weight: 800;
            color: var(--gray-900);
        }

        .facility-code {
            display: inline-block;
            background: var(--light-red);
            color: var(--primary-red);
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.inactive {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--gray-100);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--gray-700);
        }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-edit:hover {
            background: #3b82f6;
            color: white;
        }

        .btn-toggle {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-toggle:hover {
            background: #f59e0b;
            color: white;
        }

        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }

        .modal.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s;
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
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 20px 20px 0 0;
        }

        .modal-header h2 {
            font-size: 22px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
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
        }

        .required {
            color: var(--primary-red);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .modal-footer {
            padding: 20px 30px;
            background: var(--gray-50);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 20px 20px;
        }

        .btn-cancel {
            padding: 12px 24px;
            background: white;
            border: 2px solid var(--gray-300);
            color: var(--gray-700);
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        .btn-submit {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary-red), #ef4444);
            border: none;
            color: white;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--gray-700);
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
                align-items: stretch;
            }

            .header h1 {
                font-size: 22px;
            }

            .header-actions {
                flex-direction: column;
            }

            .back-btn, .add-btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 100%;
            }

            .table-header {
                flex-direction: column;
                gap: 15px;
            }

            .table-container {
                overflow-x: scroll;
            }

            table {
                min-width: 800px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
            }

            .action-btns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-building"></i> Manage Facilities</h1>
                <div class="header-actions">
                    <div class="user-name-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php echo htmlspecialchars($logged_in_user_name); ?>
                    </div>
                    <button class="add-btn" onclick="openAddModal()">
                        <i class="fas fa-plus-circle"></i> Add New Facility
                    </button>
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div style="flex: 1;"><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $total_facilities; ?></h3>
                    <p>Total Facilities</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $active_facilities; ?></h3>
                    <p>Active Facilities</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon gray">
                    <i class="fas fa-pause-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $inactive_facilities; ?></h3>
                    <p>Inactive Facilities</p>
                </div>
            </div>
        </div>

        <!-- Facilities Table -->
        <div class="table-card">
            <div class="table-header">
                <h2><i class="fas fa-list"></i> All Facilities</h2>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search facilities..." onkeyup="searchTable()">
                </div>
            </div>

            <div class="table-container">
                <?php if (empty($facilities)): ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>No Facilities Found</h3>
                        <p>Start by adding your first facility using the button above.</p>
                    </div>
                <?php else: ?>
                    <table id="facilitiesTable">
                        <thead>
                            <tr>
                                <th>Facility Name</th>
                                <th>Code</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Statistics</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facilities as $facility): ?>
                                <tr>
                                    <td>
                                        <div class="facility-name"><?php echo htmlspecialchars($facility['facility_name']); ?></div>
                                        <?php if ($facility['created_by_name']): ?>
                                            <small style="color: var(--gray-500); font-size: 11px;">
                                                Created by <?php echo htmlspecialchars($facility['created_by_name']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="facility-code"><?php echo htmlspecialchars($facility['facility_code']); ?></span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700;"><?php echo htmlspecialchars($facility['location'] ?: 'N/A'); ?></div>
                                        <?php if ($facility['address']): ?>
                                            <small style="color: var(--gray-500); font-size: 11px;">
                                                <?php echo htmlspecialchars($facility['address']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($facility['phone']): ?>
                                            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($facility['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($facility['email']): ?>
                                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($facility['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!$facility['phone'] && !$facility['email']): ?>
                                            <span style="color: var(--gray-400);">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <span class="stat-badge">
                                                <i class="fas fa-users"></i> <?php echo $facility['total_users']; ?> Users
                                            </span>
                                            <span class="stat-badge">
                                                <i class="fas fa-car"></i> <?php echo $facility['total_vehicles']; ?> Vehicles
                                            </span>
                                            <span class="stat-badge">
                                                <i class="fas fa-file-alt"></i> <?php echo $facility['total_requisitions']; ?> Requests
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $facility['is_active'] ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                                            <?php echo $facility['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="btn btn-edit" onclick='openEditModal(<?php echo json_encode($facility); ?>)'>
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="facility_id" value="<?php echo $facility['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $facility['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-toggle" onclick="return confirm('Are you sure you want to <?php echo $facility['is_active'] ? 'deactivate' : 'activate'; ?> this facility?')">
                                                    <i class="fas fa-<?php echo $facility['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                    <?php echo $facility['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="facility_id" value="<?php echo $facility['id']; ?>">
                                                <button type="submit" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this facility? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Facility Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Facility</h2>
                <button class="close-modal" onclick="closeAddModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Facility Name <span class="required">*</span></label>
                        <input type="text" name="facility_name" required placeholder="e.g., Central Hospital">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Facility Code <span class="required">*</span></label>
                            <input type="text" name="facility_code" required placeholder="e.g., FAC-001" style="text-transform: uppercase;">
                        </div>

                        <div class="form-group">
                            <label>Location <span class="required">*</span></label>
                            <input type="text" name="location" required placeholder="e.g., Lusaka">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" placeholder="Full facility address..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="tel" name="phone" placeholder="e.g., +260 211 123456">
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="e.g., info@facility.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Add Facility
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Facility Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Facility</h2>
                <button class="close-modal" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="facility_id" id="edit_facility_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Facility Name <span class="required">*</span></label>
                        <input type="text" name="facility_name" id="edit_facility_name" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Facility Code <span class="required">*</span></label>
                            <input type="text" name="facility_code" id="edit_facility_code" required style="text-transform: uppercase;">
                        </div>

                        <div class="form-group">
                            <label>Location <span class="required">*</span></label>
                            <input type="text" name="location" id="edit_location" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" id="edit_address"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="tel" name="phone" id="edit_phone">
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="edit_email">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Facility
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function openEditModal(facility) {
            document.getElementById('edit_facility_id').value = facility.id;
            document.getElementById('edit_facility_name').value = facility.facility_name;
            document.getElementById('edit_facility_code').value = facility.facility_code;
            document.getElementById('edit_location').value = facility.location || '';
            document.getElementById('edit_address').value = facility.address || '';
            document.getElementById('edit_phone').value = facility.phone || '';
            document.getElementById('edit_email').value = facility.email || '';
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target === addModal) {
                closeAddModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        }

        // Search functionality
        function searchTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('facilitiesTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let found = false;
                const td = tr[i].getElementsByTagName('td');
                
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }

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

        // Auto-uppercase facility code
        document.addEventListener('DOMContentLoaded', function() {
            const codeInputs = document.querySelectorAll('input[name="facility_code"]');
            codeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });
        });
    </script>
</body>
</html>