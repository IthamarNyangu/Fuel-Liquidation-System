<?php
// users.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth_check.php';
require_once 'facility_auth.php';

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'staff';
$current_user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    die("Error: Your account is not assigned to a facility. Please contact your administrator.");
}

// Only allow admins and super admins to access this page
if (!in_array($user_role, ['super_admin', 'admin', 'facility_admin'])) {
    die("Access denied: You don't have permission to access this page.");
}

// Database configuration
require_once __DIR__ . '/db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$message = '';
$messageType = '';

// Default password for resets
$DEFAULT_PASSWORD = 'Password123!';

function users_role_label(string $role): string {
    if ($role === 'staff') {
        return 'Driver';
    }

    return ucwords(str_replace('_', ' ', $role));
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $new_password = trim($_POST['password']);
        $facility_id = $_POST['facility_id'];
        
        // Validate facility access
        if (!$is_super_admin && $facility_id != $user_facility_id) {
            throw new Exception('You can only add users to your own facility');
        }
        
        // Check if email already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception('Email address already exists');
        }
        
        // Hash password
        $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Set facility admin flag
        $is_facility_admin = ($role === 'facility_admin' || $role === 'admin') ? 1 : 0;
        $is_super = ($role === 'super_admin') ? 1 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, facility_id, is_facility_admin, is_super_admin, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$name, $email, $hashedPassword, $role, $facility_id, $is_facility_admin, $is_super]);
        
        $message = "User '{$name}' added successfully!";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    try {
        $user_id = $_POST['user_id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $facility_id = $_POST['facility_id'];
        
        // Verify user belongs to admin's facility (if not super admin)
        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
            $checkStmt->execute([$user_id]);
            $userFacility = $checkStmt->fetchColumn();
            
            if ($userFacility != $user_facility_id) {
                throw new Exception('You can only edit users in your facility');
            }
            
            if ($facility_id != $user_facility_id) {
                throw new Exception('You cannot transfer users to other facilities');
            }
        }
        
        // Check if email already exists for other users
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([$email, $user_id]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception('Email address already exists');
        }
        
        // Set facility admin flag
        $is_facility_admin = ($role === 'facility_admin' || $role === 'admin') ? 1 : 0;
        $is_super = ($role === 'super_admin') ? 1 : 0;
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, email = ?, role = ?, facility_id = ?, is_facility_admin = ?, is_super_admin = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $role, $facility_id, $is_facility_admin, $is_super, $user_id]);
        
        $message = "User '{$name}' updated successfully!";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    try {
        $user_id = $_POST['user_id'];
        
        // Verify user belongs to admin's facility (if not super admin)
        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
            $checkStmt->execute([$user_id]);
            $userFacility = $checkStmt->fetchColumn();
            
            if ($userFacility != $user_facility_id) {
                throw new Exception('You can only reset passwords for users in your facility');
            }
        }
        
        // Get user name for message
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userName = $stmt->fetchColumn();
        
        // Hash default password
        $hashedPassword = password_hash($DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $user_id]);
        
        $message = "Password for '{$userName}' has been reset to: {$DEFAULT_PASSWORD}";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    try {
        $user_id = $_POST['user_id'];
        
        // Prevent deleting self
        if ($user_id == $_SESSION['user_id']) {
            throw new Exception('You cannot delete your own account');
        }
        
        // Verify user belongs to admin's facility (if not super admin)
        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
            $checkStmt->execute([$user_id]);
            $userFacility = $checkStmt->fetchColumn();
            
            if ($userFacility != $user_facility_id) {
                throw new Exception('You can only delete users in your facility');
            }
        }
        
        // Get user name for message
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userName = $stmt->fetchColumn();
        
        // Delete user
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $message = "User '{$userName}' has been deleted successfully!";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch facilities for dropdown (if super admin)
if ($is_super_admin) {
    $facilitiesStmt = $pdo->query("SELECT id, facility_name FROM facilities WHERE is_active = 1 ORDER BY facility_name");
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $facilitiesStmt = $pdo->prepare("SELECT id, facility_name FROM facilities WHERE id = ?");
    $facilitiesStmt->execute([$user_facility_id]);
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch all users (filtered by facility for non-super admins)
if ($is_super_admin) {
    $usersStmt = $pdo->query("
        SELECT u.*, f.facility_name 
        FROM users u 
        LEFT JOIN facilities f ON u.facility_id = f.id 
        ORDER BY u.created_at DESC
    ");
} else {
    $usersStmt = $pdo->prepare("
        SELECT u.*, f.facility_name 
        FROM users u 
        LEFT JOIN facilities f ON u.facility_id = f.id 
        WHERE u.facility_id = ?
        ORDER BY u.created_at DESC
    ");
    $usersStmt->execute([$user_facility_id]);
}
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Count users by role
$total_users = count($users);
$admin_count = count(array_filter($users, function($u) { return in_array($u['role'], ['super_admin', 'admin', 'facility_admin']); }));
$staff_count = count(array_filter($users, function($u) { return $u['role'] === 'staff'; }));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
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
            padding: 20px;
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
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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

        /* Stats */
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
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
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
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .btn-add {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
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

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            background: white;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        .users-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .users-table th {
            background: var(--gray-100);
            padding: 14px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--gray-700);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .users-table td {
            padding: 14px 16px;
            color: var(--gray-900);
            font-size: 14px;
            font-weight: 500;
            background: white;
            border-bottom: 1px solid var(--gray-200);
        }

        .users-table tbody tr:hover td {
            background: var(--light-red);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.5px;
        }

        .badge-super-admin {
            background: linear-gradient(135deg, #29485d, #35627c);
            color: white;
        }

        .badge-admin {
            background: rgba(59, 130, 246, 0.2);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .badge-staff {
            background: rgba(107, 114, 128, 0.2);
            color: #4b5563;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        .facility-tag {
            font-size: 11px;
            font-weight: 600;
            color: #35627c;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-edit {
            background: rgba(59, 130, 246, 0.2);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .btn-edit:hover {
            background: #2563eb;
            color: white;
            transform: translateY(-2px);
        }

        .btn-reset {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .btn-reset:hover {
            background: #f59e0b;
            color: white;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-delete:hover {
            background: #ef4444;
            color: white;
            transform: translateY(-2px);
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
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: var(--gray-900);
            font-size: 24px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--gray-400);
            cursor: pointer;
            transition: all 0.3s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .btn-close:hover {
            background: var(--gray-100);
            color: var(--gray-900);
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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .header-content {
                flex-direction: column;
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

            .card-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .btn-add {
                width: 100%;
                justify-content: center;
            }

            .action-buttons {
                flex-direction: column;
            }

            .users-table {
                font-size: 12px;
            }

            .users-table th,
            .users-table td {
                padding: 10px 8px;
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
                    <i class="fas fa-users"></i> User Management
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
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo $total_users; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-label">Administrators</div>
                <div class="stat-value"><?php echo $admin_count; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="stat-label">Drivers</div>
                <div class="stat-value"><?php echo $staff_count; ?></div>
            </div>
        </div>

        <!-- Users List -->
        <div class="card">
            <div class="card-title">
                <span><i class="fas fa-list"></i> All Users</span>
                <button class="btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New User
                </button>
            </div>

            <?php if (count($users) > 0): ?>
                <div class="table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-user"></i> Name</th>
                                <th><i class="fas fa-envelope"></i> Email</th>
                                <th><i class="fas fa-shield-alt"></i> Role</th>
                                <?php if ($is_super_admin): ?>
                                <th><i class="fas fa-building"></i> Facility</th>
                                <?php endif; ?>
                                <th><i class="fas fa-calendar"></i> Created</th>
                                <th><i class="fas fa-cog"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php 
                                    $roleClass = 'badge-staff';
                                    $roleIcon = 'user';
                                    if ($user['role'] === 'super_admin') {
                                        $roleClass = 'badge-super-admin';
                                        $roleIcon = 'crown';
                                    } elseif (in_array($user['role'], ['admin', 'facility_admin'])) {
                                        $roleClass = 'badge-admin';
                                        $roleIcon = 'user-shield';
                                    }
                                    ?>
                                    <span class="badge <?php echo $roleClass; ?>">
                                        <i class="fas fa-<?php echo $roleIcon; ?>"></i>
                                        <?php echo users_role_label((string) $user['role']); ?>
                                    </span>
                                </td>
                                <?php if ($is_super_admin): ?>
                                <td>
                                    <?php if ($user['facility_name']): ?>
                                        <span class="facility-tag">
                                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($user['facility_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray-400);">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-edit" onclick='openEditModal(<?php echo json_encode($user); ?>)'>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn-icon btn-reset" onclick="confirmReset(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-key"></i> Reset
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button class="btn-icon btn-delete" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <p style="font-weight: 600; color: var(--gray-700); margin-top: 10px;">No users found</p>
                    <p style="font-size: 14px; margin-top: 5px;">Click "Add New User" to create your first user</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Add New User</h2>
                <button class="btn-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name <span class="required">*</span></label>
                        <input type="text" name="name" required placeholder="e.g., John Doe">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                        <input type="email" name="email" required placeholder="e.g., john@example.com">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Password <span class="required">*</span></label>
                        <input type="password" name="password" required placeholder="Minimum 8 characters" value="<?php echo $DEFAULT_PASSWORD; ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-shield-alt"></i> Role <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="">Select Role</option>
                            <?php if ($is_super_admin): ?>
                            <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                            <option value="admin">Admin</option>
                            <option value="facility_admin">Facility Admin</option>
                            <option value="staff">Driver</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Facility <span class="required">*</span></label>
                        <select name="facility_id" required <?php echo !$is_super_admin ? 'readonly' : ''; ?>>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>" <?php echo (!$is_super_admin && $facility['id'] == $user_facility_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($facility['facility_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="add_user" class="btn-submit">
                    <i class="fas fa-save"></i> Create User
                </button>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Edit User</h2>
                <button class="btn-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name <span class="required">*</span></label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-shield-alt"></i> Role <span class="required">*</span></label>
                        <select name="role" id="edit_role" required>
                            <?php if ($is_super_admin): ?>
                            <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                            <option value="admin">Admin</option>
                            <option value="facility_admin">Facility Admin</option>
                            <option value="staff">Driver</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Facility <span class="required">*</span></label>
                        <select name="facility_id" id="edit_facility_id" required>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>">
                                    <?php echo htmlspecialchars($facility['facility_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="edit_user" class="btn-submit">
                    <i class="fas fa-save"></i> Update User
                </button>
            </form>
        </div>
    </div>

    <!-- Hidden forms for reset and delete -->
    <form method="POST" id="resetForm" style="display: none;">
        <input type="hidden" name="user_id" id="reset_user_id">
        <input type="hidden" name="reset_password" value="1">
    </form>

    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="user_id" id="delete_user_id">
        <input type="hidden" name="delete_user" value="1">
    </form>

    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_facility_id').value = user.facility_id;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function confirmReset(userId, userName) {
            if (confirm(`Reset password for "${userName}" to default password (<?php echo $DEFAULT_PASSWORD; ?>)?`)) {
                document.getElementById('reset_user_id').value = userId;
                document.getElementById('resetForm').submit();
            }
        }

        function confirmDelete(userId, userName) {
            if (confirm(`Are you sure you want to delete user "${userName}"? This action cannot be undone.`)) {
                document.getElementById('delete_user_id').value = userId;
                document.getElementById('deleteForm').submit();
            }
        }

        // Close modals when clicking outside
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddModal();
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
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
