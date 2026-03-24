<?php
// assign_users_to_facility.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Require admin authentication
session_start();
require_once 'db_connect.php';

// Check if user is logged in and is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin', 'facility_admin'])) {
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
                case 'assign_user':
                    $user_id = $_POST['user_id'];
                    $facility_id = $_POST['facility_id'];
                    
                    // Check if user already assigned to a facility
                    $check_stmt = $conn->prepare("SELECT facility_id, name FROM users WHERE id = ?");
                    $check_stmt->bind_param("i", $user_id);
                    $check_stmt->execute();
                    $user_data = $check_stmt->get_result()->fetch_assoc();
                    
                    if ($user_data['facility_id'] && $user_data['facility_id'] != $facility_id) {
                        // Get old facility name
                        $old_facility_stmt = $conn->prepare("SELECT facility_name FROM facilities WHERE id = ?");
                        $old_facility_stmt->bind_param("i", $user_data['facility_id']);
                        $old_facility_stmt->execute();
                        $old_facility = $old_facility_stmt->get_result()->fetch_assoc();
                        
                        $confirm_message = "User is currently assigned to '{$old_facility['facility_name']}'. ";
                    }
                    
                    // Update user's facility
                    $stmt = $conn->prepare("UPDATE users SET facility_id = ? WHERE id = ?");
                    $stmt->bind_param("ii", $facility_id, $user_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "User '{$user_data['name']}' assigned to facility successfully!";
                        header("Location: assign_users_to_facility.php");
                        exit();
                    }
                    break;
                    
                case 'unassign_user':
                    $user_id = $_POST['user_id'];
                    
                    // Get user name
                    $name_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                    $name_stmt->bind_param("i", $user_id);
                    $name_stmt->execute();
                    $user_name = $name_stmt->get_result()->fetch_assoc()['name'];
                    
                    // Remove facility assignment
                    $stmt = $conn->prepare("UPDATE users SET facility_id = NULL WHERE id = ?");
                    $stmt->bind_param("i", $user_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "User '{$user_name}' unassigned from facility!";
                        header("Location: assign_users_to_facility.php");
                        exit();
                    }
                    break;
                    
                case 'bulk_assign':
                    $user_ids = $_POST['user_ids'] ?? [];
                    $facility_id = $_POST['facility_id'];
                    
                    if (empty($user_ids)) {
                        throw new Exception("Please select at least one user to assign.");
                    }
                    
                    $success_count = 0;
                    foreach ($user_ids as $user_id) {
                        $stmt = $conn->prepare("UPDATE users SET facility_id = ? WHERE id = ?");
                        $stmt->bind_param("ii", $facility_id, $user_id);
                        if ($stmt->execute()) {
                            $success_count++;
                        }
                    }
                    
                    $_SESSION['success_message'] = "{$success_count} user(s) assigned to facility successfully!";
                    header("Location: assign_users_to_facility.php");
                    exit();
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

// Fetch all facilities
$facilities = $conn->query("
    SELECT id, facility_name, facility_code, location 
    FROM facilities 
    WHERE is_active = 1 
    ORDER BY facility_name
")->fetch_all(MYSQLI_ASSOC);

// Fetch all users with their current facility assignments
$users_query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        u.role,
        u.facility_id,
        f.facility_name,
        f.facility_code
    FROM users u
    LEFT JOIN facilities f ON u.facility_id = f.id
    ORDER BY u.facility_id IS NULL DESC, f.facility_name, u.name
";
$users = $conn->query($users_query)->fetch_all(MYSQLI_ASSOC);

// Count statistics
$total_users = count($users);
$assigned_users = count(array_filter($users, fn($u) => $u['facility_id'] !== null));
$unassigned_users = $total_users - $assigned_users;

// Group users by facility
$users_by_facility = [];
$unassigned_users_list = [];

foreach ($users as $user) {
    if ($user['facility_id']) {
        if (!isset($users_by_facility[$user['facility_id']])) {
            $users_by_facility[$user['facility_id']] = [
                'facility_name' => $user['facility_name'],
                'facility_code' => $user['facility_code'],
                'users' => []
            ];
        }
        $users_by_facility[$user['facility_id']]['users'][] = $user;
    } else {
        $unassigned_users_list[] = $user;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Users to Facilities - Fuel Liquidation System</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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

        .back-btn, .bulk-btn {
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

        .bulk-btn {
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .bulk-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
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
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
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

        .stat-icon.yellow {
            background: #fef3c7;
            color: #d97706;
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

        /* Quick Assign Card */
        .quick-assign-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .quick-assign-card::before {
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
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .assign-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
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

        .required {
            color: var(--primary-red);
        }

        .form-group select {
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            background: white;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .assign-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .assign-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* Users Grid */
        .users-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .section-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .count-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
        }

        .users-grid {
            padding: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .user-card {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.3s;
            position: relative;
        }

        .user-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 10px 22px rgba(22, 32, 42, 0.08);
            transform: translateY(-2px);
        }

        .user-info {
            display: flex;
            align-items: start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--light-red);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-red);
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-size: 15px;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .user-email {
            font-size: 12px;
            color: var(--gray-600);
            font-weight: 600;
        }

        .user-role {
            display: inline-block;
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 8px;
        }

        .user-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-sm {
            flex: 1;
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-reassign {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-reassign:hover {
            background: #3b82f6;
            color: white;
        }

        .btn-unassign {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-unassign:hover {
            background: #dc2626;
            color: white;
        }

        .facility-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dcfce7;
            color: #166534;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 8px;
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

        /* Facility Group */
        .facility-group {
            margin-bottom: 25px;
        }

        .facility-group-header {
            padding: 15px 20px;
            background: var(--gray-50);
            border-radius: 12px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .facility-info h3 {
            font-size: 16px;
            font-weight: 800;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .facility-code-badge {
            background: var(--light-red);
            color: var(--primary-red);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
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
            max-width: 500px;
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
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 20px 20px 0 0;
        }

        .modal-header h2 {
            font-size: 20px;
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
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            border: none;
            color: white;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* Checkbox styling */
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
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

            .back-btn, .bulk-btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .assign-form {
                grid-template-columns: 1fr;
            }

            .users-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }

            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-user-cog"></i> Assign Users to Facilities</h1>
                <div class="header-actions">
                    <div class="user-name-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php echo htmlspecialchars($logged_in_user_name); ?>
                    </div>
                    <button class="bulk-btn" onclick="openBulkModal()">
                        <i class="fas fa-users"></i> Bulk Assign
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
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $total_users; ?></h3>
                    <p>Total Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $assigned_users; ?></h3>
                    <p>Assigned Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon yellow">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $unassigned_users; ?></h3>
                    <p>Unassigned Users</p>
                </div>
            </div>
        </div>

        <!-- Quick Assign Form -->
        <div class="quick-assign-card">
            <div class="card-title">
                <i class="fas fa-bolt"></i> Quick Assign User
            </div>
            <form method="POST" class="assign-form">
                <input type="hidden" name="action" value="assign_user">
                <div class="form-group">
                    <label>
                        <i class="fas fa-user"></i> Select User <span class="required">*</span>
                    </label>
                    <select name="user_id" required>
                        <option value="">Choose a user...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['name']); ?> 
                                (<?php echo htmlspecialchars($user['email']); ?>)
                                <?php if ($user['facility_id']): ?>
                                    - Currently: <?php echo htmlspecialchars($user['facility_name']); ?>
                                <?php else: ?>
                                    - Unassigned
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-building"></i> Select Facility <span class="required">*</span>
                    </label>
                    <select name="facility_id" required>
                        <option value="">Choose a facility...</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>">
                                <?php echo htmlspecialchars($facility['facility_name']); ?>
                                (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="assign-btn">
                    <i class="fas fa-link"></i> Assign User
                </button>
            </form>
        </div>

        <!-- Unassigned Users -->
        <?php if (!empty($unassigned_users_list)): ?>
        <div class="users-section">
            <div class="section-header">
                <h2><i class="fas fa-user-slash"></i> Unassigned Users</h2>
                <span class="count-badge"><?php echo count($unassigned_users_list); ?> user(s)</span>
            </div>
            <div class="users-grid">
                <?php foreach ($unassigned_users_list as $user): ?>
                    <div class="user-card">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                <span class="user-role"><?php echo htmlspecialchars($user['role']); ?></span>
                            </div>
                        </div>
                        <div class="user-actions">
                            <button class="btn-sm btn-reassign" onclick='openAssignModal(<?php echo json_encode($user); ?>)'>
                                <i class="fas fa-plus"></i> Assign to Facility
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Users by Facility -->
        <?php foreach ($users_by_facility as $facility_id => $facility_data): ?>
        <div class="users-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-building"></i> 
                    <?php echo htmlspecialchars($facility_data['facility_name']); ?>
                    <span class="facility-code-badge"><?php echo htmlspecialchars($facility_data['facility_code']); ?></span>
                </h2>
                <span class="count-badge"><?php echo count($facility_data['users']); ?> user(s)</span>
            </div>
            <div class="users-grid">
                <?php foreach ($facility_data['users'] as $user): ?>
                    <div class="user-card">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                <span class="user-role"><?php echo htmlspecialchars($user['role']); ?></span>
                            </div>
                        </div>
                        <div class="user-actions">
                            <button class="btn-sm btn-reassign" onclick='openReassignModal(<?php echo json_encode($user); ?>)'>
                                <i class="fas fa-exchange-alt"></i> Reassign
                            </button>
                            <form method="POST" style="flex: 1; display: inline;">
                                <input type="hidden" name="action" value="unassign_user">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn-sm btn-unassign" onclick="return confirm('Are you sure you want to unassign this user?')">
                                    <i class="fas fa-unlink"></i> Unassign
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Assign/Reassign Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> <span id="modalTitle">Assign User</span></h2>
                <button class="close-modal" onclick="closeAssignModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="assign_user">
                <input type="hidden" name="user_id" id="assign_user_id">
                <div class="modal-body">
                    <div style="margin-bottom: 20px; padding: 15px; background: var(--gray-50); border-radius: 12px;">
                        <div style="font-size: 12px; color: var(--gray-600); font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Selected User</div>
                        <div id="selectedUserName" style="font-size: 16px; font-weight: 800; color: var(--gray-900);"></div>
                        <div id="selectedUserEmail" style="font-size: 13px; color: var(--gray-600); font-weight: 600;"></div>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-building"></i> Select Facility <span class="required">*</span>
                        </label>
                        <select name="facility_id" required>
                            <option value="">Choose a facility...</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>">
                                    <?php echo htmlspecialchars($facility['facility_name']); ?>
                                    (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeAssignModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check"></i> Assign User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Assign Modal -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-users-cog"></i> Bulk Assign Users</h2>
                <button class="close-modal" onclick="closeBulkModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="bulk_assign">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>
                            <i class="fas fa-building"></i> Select Facility <span class="required">*</span>
                        </label>
                        <select name="facility_id" required>
                            <option value="">Choose a facility...</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>">
                                    <?php echo htmlspecialchars($facility['facility_name']); ?>
                                    (<?php echo htmlspecialchars($facility['facility_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="margin-bottom: 12px;">
                            <i class="fas fa-users"></i> Select Users <span class="required">*</span>
                        </label>
                        <div style="max-height: 300px; overflow-y: auto; border: 2px solid var(--gray-300); border-radius: 12px; padding: 15px;">
                            <?php foreach ($users as $user): ?>
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>" id="bulk_user_<?php echo $user['id']; ?>">
                                    <label for="bulk_user_<?php echo $user['id']; ?>" style="margin: 0; text-transform: none; font-size: 14px; cursor: pointer;">
                                        <?php echo htmlspecialchars($user['name']); ?>
                                        <span style="color: var(--gray-500); font-size: 12px;">
                                            (<?php echo $user['facility_id'] ? htmlspecialchars($user['facility_name']) : 'Unassigned'; ?>)
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeBulkModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check-double"></i> Assign Selected Users
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAssignModal(user) {
            document.getElementById('modalTitle').textContent = 'Assign User to Facility';
            document.getElementById('assign_user_id').value = user.id;
            document.getElementById('selectedUserName').textContent = user.name;
            document.getElementById('selectedUserEmail').textContent = user.email;
            document.getElementById('assignModal').classList.add('active');
        }

        function openReassignModal(user) {
            document.getElementById('modalTitle').textContent = 'Reassign User';
            document.getElementById('assign_user_id').value = user.id;
            document.getElementById('selectedUserName').textContent = user.name;
            document.getElementById('selectedUserEmail').textContent = user.email + ' (Currently: ' + user.facility_name + ')';
            document.getElementById('assignModal').classList.add('active');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('active');
        }

        function openBulkModal() {
            document.getElementById('bulkModal').classList.add('active');
        }

        function closeBulkModal() {
            document.getElementById('bulkModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const assignModal = document.getElementById('assignModal');
            const bulkModal = document.getElementById('bulkModal');
            if (event.target === assignModal) {
                closeAssignModal();
            }
            if (event.target === bulkModal) {
                closeBulkModal();
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
    </script>
</body>
</html>