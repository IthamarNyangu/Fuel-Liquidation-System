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

function users_role_options(bool $isSuperAdmin): array {
    $options = [];
    if ($isSuperAdmin) {
        $options['super_admin'] = 'Super Admin';
    }
    $options['admin'] = 'Admin';
    $options['facility_admin'] = 'Facility Admin';
    $options['staff'] = 'Driver';

    return $options;
}

function users_role_requires_facility(string $role): bool {
    return in_array($role, ['facility_admin', 'staff'], true);
}

function users_status_label(string $status): string {
    return $status === 'inactive' ? 'Inactive' : 'Active';
}

function users_status_class(string $status): string {
    return $status === 'inactive' ? 'is-inactive' : 'is-active';
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $new_password = trim($_POST['password']);
        $facility_id = isset($_POST['facility_id']) && $_POST['facility_id'] !== '' ? (int) $_POST['facility_id'] : null;
        $roleOptions = users_role_options($is_super_admin);

        if (!isset($roleOptions[$role])) {
            throw new Exception('Choose a valid role for the account.');
        }

        if (users_role_requires_facility($role) && !$facility_id) {
            throw new Exception('Facility is required for Facility Admin and Driver accounts.');
        }
        
        // Validate facility access
        if (!$is_super_admin) {
            $facility_id = (int) $user_facility_id;
        }

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
        
        $message = "Account '{$name}' added successfully.";
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
        $facility_id = isset($_POST['facility_id']) && $_POST['facility_id'] !== '' ? (int) $_POST['facility_id'] : null;
        $roleOptions = users_role_options($is_super_admin);

        if (!isset($roleOptions[$role])) {
            throw new Exception('Choose a valid role for the account.');
        }

        if (users_role_requires_facility($role) && !$facility_id) {
            throw new Exception('Facility is required for Facility Admin and Driver accounts.');
        }
        
        // Verify user belongs to admin's facility (if not super admin)
        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
            $checkStmt->execute([$user_id]);
            $userFacility = $checkStmt->fetchColumn();
            
            if ($userFacility != $user_facility_id) {
                throw new Exception('You can only edit users in your facility');
            }
            
            $facility_id = (int) $user_facility_id;
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
        
        $message = "Account '{$name}' updated successfully.";
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

// Handle Activate / Deactivate Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
    try {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $target_status = trim((string) ($_POST['target_status'] ?? ''));

        if (!in_array($target_status, ['active', 'inactive'], true)) {
            throw new Exception('Choose a valid account status.');
        }

        if ($user_id === (int) $_SESSION['user_id'] && $target_status === 'inactive') {
            throw new Exception('You cannot deactivate your own account while signed in.');
        }

        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
            $checkStmt->execute([$user_id]);
            $userFacility = $checkStmt->fetchColumn();

            if ((int) $userFacility !== (int) $user_facility_id) {
                throw new Exception('You can only update account status for users in your facility.');
            }
        }

        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userName = $stmt->fetchColumn();

        if (!$userName) {
            throw new Exception('Account not found.');
        }

        $stmt = $pdo->prepare("UPDATE users SET user_status = ? WHERE id = ?");
        $stmt->execute([$target_status, $user_id]);

        $message = "Account '{$userName}' is now " . users_status_label($target_status) . '.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
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

$page_title = 'Account Management';
$role_options = users_role_options($is_super_admin);
$search = trim((string) ($_GET['search'] ?? ''));
$role_filter = trim((string) ($_GET['role'] ?? ''));
$facility_filter = trim((string) ($_GET['facility'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));
$accounts_page = max(1, (int) ($_GET['page'] ?? 1));
$accounts_per_page = 10;

if ($role_filter !== '' && !array_key_exists($role_filter, $role_options)) {
    $role_filter = '';
}
if (!in_array($status_filter, ['', 'active', 'inactive'], true)) {
    $status_filter = '';
}
if (!$is_super_admin) {
    $facility_filter = '';
}

$scopeWhere = [];
$scopeParams = [];
if (!$is_super_admin) {
    $scopeWhere[] = 'u.facility_id = ?';
    $scopeParams[] = $user_facility_id;
}

$statsSql = "
    SELECT
        COUNT(*) AS total_accounts,
        SUM(CASE WHEN u.role IN ('super_admin', 'admin', 'facility_admin') THEN 1 ELSE 0 END) AS total_admins,
        SUM(CASE WHEN u.role = 'staff' THEN 1 ELSE 0 END) AS total_drivers
    FROM users u
";
if ($scopeWhere) {
    $statsSql .= ' WHERE ' . implode(' AND ', $scopeWhere);
}
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($scopeParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total_users = (int) ($stats['total_accounts'] ?? 0);
$admin_count = (int) ($stats['total_admins'] ?? 0);
$staff_count = (int) ($stats['total_drivers'] ?? 0);

$tableWhere = $scopeWhere;
$tableParams = $scopeParams;
if ($search !== '') {
    $tableWhere[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $tableParams[] = '%' . $search . '%';
    $tableParams[] = '%' . $search . '%';
}
if ($role_filter !== '') {
    $tableWhere[] = 'u.role = ?';
    $tableParams[] = $role_filter;
}
if ($is_super_admin && $facility_filter !== '') {
    if ($facility_filter === 'unassigned') {
        $tableWhere[] = 'u.facility_id IS NULL';
    } else {
        $tableWhere[] = 'u.facility_id = ?';
        $tableParams[] = (int) $facility_filter;
    }
}
if ($status_filter !== '') {
    $tableWhere[] = 'u.user_status = ?';
    $tableParams[] = $status_filter;
}

$whereSql = $tableWhere ? ' WHERE ' . implode(' AND ', $tableWhere) : '';

$usersCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN facilities f ON u.facility_id = f.id
    $whereSql
");
$usersCountStmt->execute($tableParams);
$filtered_total_users = (int) $usersCountStmt->fetchColumn();
$total_pages = max(1, (int) ceil($filtered_total_users / $accounts_per_page));
$accounts_page = min($accounts_page, $total_pages);
$accounts_offset = ($accounts_page - 1) * $accounts_per_page;

$usersStmt = $pdo->prepare("
    SELECT u.*, f.facility_name
    FROM users u
    LEFT JOIN facilities f ON u.facility_id = f.id
    $whereSql
    ORDER BY u.created_at DESC
    LIMIT $accounts_per_page OFFSET $accounts_offset
");
$usersStmt->execute($tableParams);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$accounts_start = $filtered_total_users > 0 ? ($accounts_offset + 1) : 0;
$accounts_end = $filtered_total_users > 0 ? min($accounts_offset + $accounts_per_page, $filtered_total_users) : 0;
$has_active_filters = ($search !== '' || $role_filter !== '' || $facility_filter !== '' || $status_filter !== '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
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
    <link rel="stylesheet" type="text/css" href="users.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/users.css')); ?>">
</head>
<body>
    <div class="container">
        <div class="ua-page">
            <header class="ua-header">
                <div class="ua-header-main">
                    <a href="dashboard.php" class="ua-back-link">Back to Dashboard</a>
                    <div class="ua-title-block">
                        <h1><?php echo htmlspecialchars($page_title); ?></h1>
                        <p>Manage system accounts, access scope, and role assignments across the fleet platform.</p>
                    </div>
                </div>
                <div class="ua-header-actions">
                    <button type="button" class="ua-primary-button" onclick="openAddModal()">Add Account</button>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="ua-alert ua-alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <section class="ua-stats">
                <article class="ua-stat-card">
                    <div class="ua-stat-label">Total Accounts</div>
                    <div class="ua-stat-value"><?php echo number_format($total_users); ?></div>
                </article>
                <article class="ua-stat-card">
                    <div class="ua-stat-label">Administrators</div>
                    <div class="ua-stat-value"><?php echo number_format($admin_count); ?></div>
                </article>
                <article class="ua-stat-card">
                    <div class="ua-stat-label">Drivers</div>
                    <div class="ua-stat-value"><?php echo number_format($staff_count); ?></div>
                </article>
            </section>

            <section class="ua-panel">
                <div class="ua-panel-head">
                    <div>
                        <h2>Accounts</h2>
                        <p>Search, filter, and review account access without digging through a crowded list.</p>
                    </div>
                </div>

                <form method="get" class="ua-toolbar">
                    <div class="ua-toolbar-field ua-toolbar-search">
                        <label for="accountSearch">Search</label>
                        <input type="text" id="accountSearch" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email">
                    </div>
                    <div class="ua-toolbar-field">
                        <label for="roleFilter">Role</label>
                        <select id="roleFilter" name="role">
                            <option value="">All Roles</option>
                            <?php foreach ($role_options as $roleKey => $roleLabel): ?>
                                <option value="<?php echo htmlspecialchars($roleKey); ?>" <?php echo $role_filter === $roleKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ua-toolbar-field">
                        <label for="facilityFilter">Facility</label>
                        <select id="facilityFilter" name="facility">
                            <option value="">All Facilities</option>
                            <option value="unassigned" <?php echo $facility_filter === 'unassigned' ? 'selected' : ''; ?>>No Facility</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo htmlspecialchars((string) $facility['id']); ?>" <?php echo $facility_filter === (string) $facility['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($facility['facility_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ua-toolbar-field">
                        <label for="statusFilter">Status</label>
                        <select id="statusFilter" name="status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="ua-toolbar-actions">
                        <button type="submit" class="ua-secondary-button">Apply</button>
                        <a href="users.php" class="ua-secondary-button ua-secondary-link">Clear Filters</a>
                    </div>
                </form>

                <div class="ua-table-meta">
                    <span><?php echo number_format($accounts_start); ?>-<?php echo number_format($accounts_end); ?> of <?php echo number_format($filtered_total_users); ?> accounts</span>
                </div>

                <?php if (count($users) > 0): ?>
                    <div class="ua-table-wrap">
                        <table class="ua-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Facility</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="accountsTableBody">
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $roleKey = (string) $user['role'];
                                    $facilityName = $user['facility_name'] ?: 'N/A';
                                    ?>
                                    <tr class="ua-account-row">
                                        <td>
                                            <div class="ua-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <?php if ((int) $user['id'] === (int) $_SESSION['user_id']): ?>
                                                <div class="ua-subline">Current session</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="ua-role-chip ua-role-<?php echo htmlspecialchars($roleKey); ?>">
                                                <?php echo htmlspecialchars(users_role_label($roleKey)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="ua-facility"><?php echo htmlspecialchars($facilityName); ?></span>
                                        </td>
                                        <td>
                                            <?php $accountStatus = (string) ($user['user_status'] ?? 'active'); ?>
                                            <span class="ua-status-chip <?php echo users_status_class($accountStatus); ?>">
                                                <?php echo htmlspecialchars(users_status_label($accountStatus)); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="ua-row-actions">
                                                <button type="button" class="ua-action-edit" onclick='openEditModal(<?php echo json_encode($user); ?>)'>Edit</button>
                                                <details class="ua-row-menu">
                                                    <summary class="ua-menu-trigger">More</summary>
                                                    <div class="ua-menu-panel">
                                                        <button type="button" class="ua-menu-action" onclick="confirmReset(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')">Reset Password</button>
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button
                                                                type="button"
                                                                class="ua-menu-action"
                                                                onclick="confirmToggleStatus(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo $accountStatus === 'active' ? 'inactive' : 'active'; ?>')"
                                                            >
                                                                <?php echo $accountStatus === 'active' ? 'Deactivate Account' : 'Activate Account'; ?>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button type="button" class="ua-menu-action is-danger" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')">Delete Account</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="ua-empty-state">
                        <h3><?php echo $has_active_filters ? 'No accounts match these filters' : 'No accounts found'; ?></h3>
                        <p><?php echo $has_active_filters ? 'Try a different search term or clear the current filters.' : 'Create the first account to start assigning roles and access.'; ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($filtered_total_users > 0 && $total_pages > 1): ?>
                    <div class="ua-pagination" aria-label="Accounts pages">
                        <?php
                        $paginationBase = [
                            'search' => $search,
                            'role' => $role_filter,
                            'facility' => $facility_filter,
                            'status' => $status_filter,
                        ];
                        ?>
                        <?php if ($accounts_page > 1): ?>
                            <a class="ua-page-link" href="users.php?<?php echo http_build_query(array_merge($paginationBase, ['page' => $accounts_page - 1])); ?>">Previous</a>
                        <?php endif; ?>

                        <?php for ($page = 1; $page <= $total_pages; $page++): ?>
                            <a class="ua-page-link <?php echo $page === $accounts_page ? 'is-active' : ''; ?>" href="users.php?<?php echo http_build_query(array_merge($paginationBase, ['page' => $page])); ?>">
                                <?php echo htmlspecialchars((string) $page); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($accounts_page < $total_pages): ?>
                            <a class="ua-page-link" href="users.php?<?php echo http_build_query(array_merge($paginationBase, ['page' => $accounts_page + 1])); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Add Account</h2>
                    <p>Create a system account with the right role and facility access.</p>
                </div>
                <button type="button" class="btn-close" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="ua-modal-body">
                    <section class="ua-modal-section">
                        <div class="ua-modal-section-head">
                            <div class="ua-modal-section-title">Basic Details</div>
                            <p>Use the account holder's official details so sign-in and audit trails stay clean.</p>
                        </div>
                        <div class="ua-form-grid">
                            <div class="form-group ua-form-span-full">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="name" required placeholder="e.g. Martha Banda">
                                <div class="ua-field-note">Use the full name that should appear across the system.</div>
                            </div>
                            <div class="form-group ua-form-span-full">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" required placeholder="name@righttocare-zambia.org">
                                <div class="ua-field-note">Use the organisation email for password resets and account recovery.</div>
                            </div>
                            <div class="form-group ua-form-span-full">
                                <label>Password <span class="required">*</span></label>
                                <input type="password" name="password" required placeholder="Minimum 8 characters" value="<?php echo $DEFAULT_PASSWORD; ?>">
                                <div class="ua-field-note">This will be the starting password for the account and can be reset later.</div>
                            </div>
                        </div>
                    </section>

                    <section class="ua-modal-section">
                        <div class="ua-modal-section-head">
                            <div class="ua-modal-section-title">Role & Access</div>
                            <p>Facility access depends on the role selected below.</p>
                        </div>
                        <div class="ua-form-grid">
                            <div class="form-group">
                                <label>Role <span class="required">*</span></label>
                                <select name="role" id="add_role" required onchange="syncFacilityRequirement('add')">
                                    <option value="">Select Role</option>
                                    <?php foreach ($role_options as $roleKey => $roleLabel): ?>
                                        <option value="<?php echo htmlspecialchars($roleKey); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Facility <span class="required" id="addFacilityRequired"><?php echo $is_super_admin ? '' : '*'; ?></span></label>
                                <select name="facility_id" id="add_facility_id" <?php echo !$is_super_admin ? 'readonly' : ''; ?>>
                                    <?php if ($is_super_admin): ?>
                                        <option value="">No facility assigned</option>
                                    <?php endif; ?>
                                    <?php foreach ($facilities as $facility): ?>
                                        <option value="<?php echo $facility['id']; ?>" <?php echo (!$is_super_admin && $facility['id'] == $user_facility_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($facility['facility_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="ua-field-note" id="addFacilityNote">Facility is required for Facility Admin and Driver accounts.</div>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="ua-modal-footer">
                    <div class="ua-modal-footer-note">Facility is required for Facility Admin and Driver accounts.</div>
                    <div class="ua-modal-footer-actions">
                        <button type="button" class="ua-secondary-button" onclick="closeAddModal()">Cancel</button>
                        <button type="submit" name="add_user" class="ua-primary-button">Save Account</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Edit Account</h2>
                    <p>Update the account details, role, and facility access.</p>
                </div>
                <button type="button" class="btn-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="ua-modal-body">
                    <section class="ua-modal-section">
                        <div class="ua-modal-section-head">
                            <div class="ua-modal-section-title">Basic Details</div>
                            <p>Keep the account name and email aligned to the official organisation record.</p>
                        </div>
                        <div class="ua-form-grid">
                            <div class="form-group ua-form-span-full">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="name" id="edit_name" required placeholder="e.g. Martha Banda">
                            </div>
                            <div class="form-group ua-form-span-full">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" id="edit_email" required placeholder="name@righttocare-zambia.org">
                            </div>
                        </div>
                    </section>

                    <section class="ua-modal-section">
                        <div class="ua-modal-section-head">
                            <div class="ua-modal-section-title">Role & Access</div>
                            <p>Role changes update what the account can access across the system.</p>
                        </div>
                        <div class="ua-form-grid">
                            <div class="form-group">
                                <label>Role <span class="required">*</span></label>
                                <select name="role" id="edit_role" required onchange="syncFacilityRequirement('edit')">
                                    <?php foreach ($role_options as $roleKey => $roleLabel): ?>
                                        <option value="<?php echo htmlspecialchars($roleKey); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Facility <span class="required" id="editFacilityRequired"><?php echo $is_super_admin ? '' : '*'; ?></span></label>
                                <select name="facility_id" id="edit_facility_id" <?php echo !$is_super_admin ? 'readonly' : ''; ?>>
                                    <?php if ($is_super_admin): ?>
                                        <option value="">No facility assigned</option>
                                    <?php endif; ?>
                                    <?php foreach ($facilities as $facility): ?>
                                        <option value="<?php echo $facility['id']; ?>">
                                            <?php echo htmlspecialchars($facility['facility_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="ua-field-note" id="editFacilityNote">Facility is required for Facility Admin and Driver accounts.</div>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="ua-modal-footer">
                    <div class="ua-modal-footer-note">Status changes are managed from the account row menu.</div>
                    <div class="ua-modal-footer-actions">
                        <button type="button" class="ua-secondary-button" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="edit_user" class="ua-primary-button">Save Account</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden forms for reset, status toggle, and delete -->
    <form method="POST" id="resetForm" style="display: none;">
        <input type="hidden" name="user_id" id="reset_user_id">
        <input type="hidden" name="reset_password" value="1">
    </form>

    <form method="POST" id="statusForm" style="display: none;">
        <input type="hidden" name="user_id" id="status_user_id">
        <input type="hidden" name="target_status" id="status_target_status">
        <input type="hidden" name="toggle_user_status" value="1">
    </form>

    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="user_id" id="delete_user_id">
        <input type="hidden" name="delete_user" value="1">
    </form>

    <script>
        const facilityRequirementText = {
            super_admin: 'No facility is required for a Super Admin account.',
            admin: 'Facility is optional for Admin accounts.',
            facility_admin: 'Facility is required for Facility Admin accounts.',
            staff: 'Facility is required for Driver accounts.'
        };

        function roleNeedsFacility(role) {
            return ['facility_admin', 'staff'].includes(role);
        }

        function syncFacilityRequirement(prefix) {
            const roleField = document.getElementById(prefix + '_role');
            const facilityField = document.getElementById(prefix + '_facility_id');
            const requiredMarker = document.getElementById(prefix + 'FacilityRequired');
            const note = document.getElementById(prefix + 'FacilityNote');

            if (!roleField || !facilityField || !requiredMarker || !note) {
                return;
            }

            const role = roleField.value;
            const requiresFacility = roleNeedsFacility(role);

            facilityField.required = requiresFacility;
            requiredMarker.textContent = requiresFacility ? '*' : '';
            note.textContent = facilityRequirementText[role] || 'Choose the role first to confirm facility requirements.';

            <?php if (!$is_super_admin): ?>
            facilityField.value = '<?php echo htmlspecialchars((string) $user_facility_id, ENT_QUOTES); ?>';
            <?php endif; ?>
        }

        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            syncFacilityRequirement('add');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_facility_id').value = user.facility_id || '';
            document.getElementById('editModal').classList.add('active');
            syncFacilityRequirement('edit');
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

        function confirmToggleStatus(userId, userName, targetStatus) {
            const actionLabel = targetStatus === 'inactive' ? 'deactivate' : 'activate';
            if (confirm(`Are you sure you want to ${actionLabel} "${userName}"?`)) {
                document.getElementById('status_user_id').value = userId;
                document.getElementById('status_target_status').value = targetStatus;
                document.getElementById('statusForm').submit();
            }
        }

        function confirmDelete(userId, userName) {
            if (confirm(`Are you sure you want to delete account "${userName}"? This action cannot be undone.`)) {
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

        document.addEventListener('click', function(e) {
            document.querySelectorAll('.ua-row-menu[open]').forEach((menu) => {
                if (!menu.contains(e.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        // Auto-hide alert after 5 seconds
        <?php if ($message): ?>
            setTimeout(() => {
                const alert = document.querySelector('.ua-alert');
                if (alert) {
                    alert.style.animation = 'slideDown 0.3s reverse';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        <?php endif; ?>

        syncFacilityRequirement('add');
        syncFacilityRequirement('edit');
    </script>
</body>
</html>
