<?php
// manage_facilities.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$appRoot = dirname(__DIR__, 2);

require_once $appRoot . '/super_admin_auth.php';
require_once $appRoot . '/db_connect.php';
require_once $appRoot . '/facility_auth.php';

$selfPath = basename((string) ($_SERVER['PHP_SELF'] ?? 'manage_facilities.php'));

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
                    
                    // Check if province code already exists
                    $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE facility_code = ?");
                    $check_stmt->bind_param("s", $facility_code);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        throw new Exception("Province code already exists. Please use a unique code.");
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO facilities (facility_name, facility_code, location, address, phone, email, created_by, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->bind_param("ssssssi", $facility_name, $facility_code, $location, $address, $phone, $email, $logged_in_user_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Province '{$facility_name}' added successfully!";
                        header("Location: " . $selfPath);
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
                    
                    // Check if province code exists for other provinces
                    $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE facility_code = ? AND id != ?");
                    $check_stmt->bind_param("si", $facility_code, $facility_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        throw new Exception("Province code already exists. Please use a unique code.");
                    }
                    
                    $stmt = $conn->prepare("
                        UPDATE facilities 
                        SET facility_name = ?, facility_code = ?, location = ?, address = ?, phone = ?, email = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssssssi", $facility_name, $facility_code, $location, $address, $phone, $email, $facility_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Province '{$facility_name}' updated successfully!";
                        header("Location: " . $selfPath);
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
                        $_SESSION['success_message'] = "Province {$status_text} successfully!";
                        header("Location: " . $selfPath);
                        exit();
                    }
                    break;
                    
                case 'delete':
                    $facility_id = $_POST['facility_id'];
                    
                    // Check if province has associated records
                    $check_stmt = $conn->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM users WHERE facility_id = ?) as user_count,
                            (SELECT COUNT(*) FROM vehicles WHERE facility_id = ?) as vehicle_count
                    ");
                    $check_stmt->bind_param("ii", $facility_id, $facility_id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result()->fetch_assoc();
                    
                    if ($result['user_count'] > 0 || $result['vehicle_count'] > 0) {
                        throw new Exception("Cannot delete province with associated accounts or vehicles. Please reassign them first.");
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM facilities WHERE id = ?");
                    $stmt->bind_param("i", $facility_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = "Province deleted successfully!";
                        header("Location: " . $selfPath);
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
    <title>Manage Provinces - Fuel Liquidation System</title>
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
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
        }

        .add-btn:hover {
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
            border: 1px solid rgba(53, 98, 124, 0.16);
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            overflow: hidden;
        }

        .table-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
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
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
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
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
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
    <link rel="stylesheet" type="text/css" href="assets/css/manage_facilities.css?v=<?php echo urlencode((string) @filemtime($appRoot . '/assets/css/manage_facilities.css')); ?>">
</head>
<body>
    <div class="container">
        <div class="page-header">
            <div class="page-header-main">
                <a href="dashboard.php" class="back-link">Back to Dashboard</a>
                <div>
                    <h1 class="page-title">Manage Provinces</h1>
                </div>
                <button class="add-btn" type="button" onclick="openAddModal()">Add Province</button>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div style="flex: 1;"><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-label">Total Provinces</div>
                    <h3><?php echo $total_facilities; ?></h3>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-label">Active Provinces</div>
                    <h3><?php echo $active_facilities; ?></h3>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-label">Inactive Provinces</div>
                    <h3><?php echo $inactive_facilities; ?></h3>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-container">
                <?php if (empty($facilities)): ?>
                    <div class="empty-state">
                        <h3>No Provinces Found</h3>
                        <p>Start by adding your first province using the button above.</p>
                    </div>
                <?php else: ?>
                    <table id="facilitiesTable">
                        <thead>
                            <tr>
                                <th>Province</th>
                                <th>Code</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Overview</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="facilitiesTableBody">
                            <?php foreach ($facilities as $facility): ?>
                                <?php
                                $statusValue = $facility['is_active'] ? 'active' : 'inactive';
                                $overviewPrimary = number_format((int) $facility['total_users']) . ' Accounts - ' .
                                    number_format((int) $facility['total_vehicles']) . ' Vehicles - ' .
                                    number_format((int) $facility['total_requisitions']) . ' Requests';
                                $facilityAdminCount = (int) $facility['total_facility_admins'];
                                $overviewSecondary = $facilityAdminCount === 0
                                    ? 'No provincial admin assigned'
                                    : ($facilityAdminCount === 1
                                        ? '1 provincial admin'
                                        : number_format($facilityAdminCount) . ' provincial admins');
                                $searchBlob = strtolower(
                                    implode(' ', array_filter([
                                        (string) $facility['facility_name'],
                                        (string) $facility['facility_code'],
                                        (string) $facility['location'],
                                        (string) $facility['address'],
                                        (string) $facility['phone'],
                                        (string) $facility['email'],
                                    ]))
                                );
                                ?>
                                <tr class="facility-row"
                                    data-name="<?php echo htmlspecialchars(strtolower((string) $facility['facility_name']), ENT_QUOTES); ?>"
                                    data-location="<?php echo htmlspecialchars(strtolower((string) ($facility['location'] ?? '')), ENT_QUOTES); ?>"
                                    data-status="<?php echo $statusValue; ?>"
                                    data-vehicles="<?php echo (int) $facility['total_vehicles']; ?>"
                                    data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
                                    <td>
                                        <div class="facility-primary"><?php echo htmlspecialchars($facility['facility_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="facility-code"><?php echo htmlspecialchars($facility['facility_code']); ?></span>
                                    </td>
                                    <td>
                                        <div class="location-primary"><?php echo htmlspecialchars($facility['location'] ?: 'N/A'); ?></div>
                                        <?php if ($facility['address']): ?>
                                            <div class="location-secondary">
                                                <?php echo htmlspecialchars($facility['address']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="contact-stack">
                                            <?php if ($facility['phone']): ?>
                                                <div class="contact-line"><?php echo htmlspecialchars($facility['phone']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($facility['email']): ?>
                                                <div class="contact-line"><?php echo htmlspecialchars($facility['email']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!$facility['phone'] && !$facility['email']): ?>
                                                <div class="contact-line contact-empty">N/A</div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="overview-primary"><?php echo htmlspecialchars($overviewPrimary); ?></div>
                                        <div class="overview-secondary"><?php echo htmlspecialchars($overviewSecondary); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $facility['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $facility['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button type="button" class="action-edit" onclick='openEditModal(<?php echo json_encode($facility); ?>)'>Edit</button>
                                            <details class="row-menu">
                                                <summary class="menu-trigger">More</summary>
                                                <div class="row-menu-panel">
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="facility_id" value="<?php echo $facility['id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $facility['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" class="menu-action" onclick="return confirm('Are you sure you want to <?php echo $facility['is_active'] ? 'deactivate' : 'activate'; ?> this province?')"><?php echo $facility['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                                                    </form>
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="facility_id" value="<?php echo $facility['id']; ?>">
                                                        <button type="submit" class="menu-action danger" onclick="return confirm('Are you sure you want to delete this province? This action cannot be undone.')">Delete</button>
                                                    </form>
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="empty-state table-empty-state" id="filteredEmptyState" hidden>
                        <h3>No provinces match these filters</h3>
                        <p>Adjust the search, status, or sort settings to see more results.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Province Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Add Province</h2>
                    <p>Enter the core details for the new province.</p>
                </div>
                <button type="button" class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <section class="modal-section">
                        <div class="modal-section-title">Basic Details</div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Province Name <span class="required">*</span></label>
                                <input type="text" name="facility_name" required placeholder="e.g., Central">
                            </div>
                            <div class="form-group">
                                <label>Province Code <span class="required">*</span></label>
                                <input type="text" name="facility_code" required placeholder="e.g., PROV-CEN" style="text-transform: uppercase;">
                            </div>
                            <div class="form-group">
                                <label>Location <span class="required">*</span></label>
                                <input type="text" name="location" required placeholder="e.g., Lusaka">
                            </div>
                        </div>
                    </section>

                    <section class="modal-section">
                        <div class="modal-section-title">Contact Details</div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Address</label>
                                <textarea name="address" placeholder="Province office or contact address"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" placeholder="e.g., +260 211 123456">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" placeholder="e.g., info@province.org">
                            </div>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Save Province</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Province Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Edit Province</h2>
                    <p>Update the current province details and contact information.</p>
                </div>
                <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="facility_id" id="edit_facility_id">
                <div class="modal-body">
                    <section class="modal-section">
                        <div class="modal-section-title">Basic Details</div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Province Name <span class="required">*</span></label>
                                <input type="text" name="facility_name" id="edit_facility_name" required>
                            </div>
                            <div class="form-group">
                                <label>Province Code <span class="required">*</span></label>
                                <input type="text" name="facility_code" id="edit_facility_code" required style="text-transform: uppercase;">
                            </div>
                            <div class="form-group">
                                <label>Location <span class="required">*</span></label>
                                <input type="text" name="location" id="edit_location" required>
                            </div>
                        </div>
                    </section>

                    <section class="modal-section">
                        <div class="modal-section-title">Contact Details</div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Address</label>
                                <textarea name="address" id="edit_address"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" id="edit_phone">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" id="edit_email">
                            </div>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Save Province</button>
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

        function applyTableControls() {
            const tableBody = document.getElementById('facilitiesTableBody');
            if (!tableBody) {
                return;
            }

            const rows = Array.from(tableBody.querySelectorAll('.facility-row'));
            const searchValue = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
            const statusValue = document.getElementById('statusFilter')?.value || 'all';
            const sortValue = document.getElementById('sortSelect')?.value || 'name';

            rows.sort((a, b) => {
                if (sortValue === 'location') {
                    return (a.dataset.location || '').localeCompare(b.dataset.location || '');
                }

                if (sortValue === 'vehicles') {
                    return Number(b.dataset.vehicles || 0) - Number(a.dataset.vehicles || 0);
                }

                return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            });

            rows.forEach((row) => tableBody.appendChild(row));

            let visibleCount = 0;
            rows.forEach((row) => {
                const matchesSearch = searchValue === '' || (row.dataset.search || '').includes(searchValue);
                const matchesStatus = statusValue === 'all' || row.dataset.status === statusValue;
                const shouldShow = matchesSearch && matchesStatus;

                row.style.display = shouldShow ? '' : 'none';
                if (shouldShow) {
                    visibleCount += 1;
                }
            });

            const visibleCountEl = document.getElementById('visibleCount');
            if (visibleCountEl) {
                visibleCountEl.textContent = visibleCount + (visibleCount === 1 ? ' province shown' : ' provinces shown');
            }

            const filteredEmptyState = document.getElementById('filteredEmptyState');
            if (filteredEmptyState) {
                filteredEmptyState.hidden = visibleCount !== 0;
            }
        }

        function resetTableControls() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const sortSelect = document.getElementById('sortSelect');

            if (searchInput) {
                searchInput.value = '';
            }
            if (statusFilter) {
                statusFilter.value = 'all';
            }
            if (sortSelect) {
                sortSelect.value = 'name';
            }

            applyTableControls();
        }

        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');

            if (event.target === addModal) {
                closeAddModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }

            document.querySelectorAll('.row-menu[open]').forEach((menu) => {
                if (!menu.contains(event.target)) {
                    menu.removeAttribute('open');
                }
            });
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

        document.addEventListener('DOMContentLoaded', function() {
            const codeInputs = document.querySelectorAll('input[name="facility_code"]');
            codeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });

            applyTableControls();
        });
    </script>
</body>
</html>
