<?php
// fuel_topup.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth_check.php';
require_once 'facility_auth.php';
require_once 'db_config.php';

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'staff';
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    die("Error: Your account is not assigned to a facility. Please contact your administrator.");
}

// Only allow admins and super admins to access this page
if (!in_array($user_role, ['super_admin', 'admin', 'facility_admin'])) {
    die("Access denied: You don't have permission to access this page.");
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_fuel'])) {
    $vehicle_id = $_POST['vehicle_id'];
    $adjustment_type = $_POST['adjustment_type'];
    $amount = abs(floatval($_POST['amount'])); // Ensure positive number
    $reason = trim($_POST['reason']);
    $adjusted_by = $user_name; // Use logged-in user name
    
    // Handle optional photo attachment
    $photo_data = null;
    $photo_filename = null;
    $photo_type = null;
    
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $photo_data = file_get_contents($_FILES['attachment']['tmp_name']);
        $photo_filename = $_FILES['attachment']['name'];
        $photo_type = $_FILES['attachment']['type'];
    }
    
    if (empty($reason)) {
        $message = 'Reason is mandatory!';
        $messageType = 'error';
    } elseif ($amount <= 0) {
        $message = 'Amount must be greater than zero!';
        $messageType = 'error';
    } else {
        // Verify vehicle belongs to user's facility (if not super admin)
        if (!$is_super_admin) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM vehicles WHERE id = ? AND facility_id = ?");
            $checkStmt->execute([$vehicle_id, $user_facility_id]);
            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($checkResult['count'] == 0) {
                $message = 'Access denied: This vehicle does not belong to your facility.';
                $messageType = 'error';
                goto skip_adjustment;
            }
        }
        
        // Get current float balance for the vehicle
        $stmt = $pdo->prepare("SELECT float_balance, float_account_name, vehicle_name FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $vehicleData = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousBalance = floatval($vehicleData['float_balance']);
        $float_account = $vehicleData['float_account_name'];
        $vehicle_name = $vehicleData['vehicle_name'];
        
        // Calculate new balance with precision
        if ($adjustment_type === 'addition') {
            $newBalance = round($previousBalance + $amount, 2);
        } else {
            $newBalance = round($previousBalance - $amount, 2);
            if ($newBalance < 0) {
                $message = 'Cannot deduct more than current balance! Current: K ' . number_format($previousBalance, 2) . ', Attempted: K ' . number_format($amount, 2);
                $messageType = 'error';
                goto skip_adjustment;
            }
        }
        
        // Update vehicle float balance
        $stmt = $pdo->prepare("UPDATE vehicles SET float_balance = ? WHERE id = ?");
        $stmt->execute([$newBalance, $vehicle_id]);
        
        // Log transaction in float_transactions table
        $stmt = $pdo->prepare("
            INSERT INTO float_transactions 
            (float_account, transaction_type, amount, previous_balance, new_balance, reason, created_by, photo_data, photo_filename, photo_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $float_account,
            $adjustment_type,
            $amount,
            $previousBalance,
            $newBalance,
            $reason,
            $adjusted_by,
            $photo_data,
            $photo_filename,
            $photo_type
        ]);
        
        $actionText = $adjustment_type === 'addition' ? 'added to' : 'deducted from';
        $message = 'K ' . number_format($amount, 2) . ' ' . $actionText . ' ' . $vehicle_name . ' successfully! New balance: K ' . number_format($newBalance, 2);
        $messageType = 'success';
    }
    
    skip_adjustment:
}

// Fetch all vehicles with their float accounts (filtered by facility)
if ($is_super_admin) {
    $vehiclesStmt = $pdo->query("
        SELECT 
            id, 
            vehicle_name, 
            number_plate, 
            float_account_name, 
            COALESCE(float_balance, 0) as float_balance 
        FROM vehicles 
        ORDER BY vehicle_name
    ");
} else {
    $vehiclesStmt = $pdo->prepare("
        SELECT 
            id, 
            vehicle_name, 
            number_plate, 
            float_account_name, 
            COALESCE(float_balance, 0) as float_balance 
        FROM vehicles 
        WHERE facility_id = ?
        ORDER BY vehicle_name
    ");
    $vehiclesStmt->execute([$user_facility_id]);
}
$vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total balance (only for user's facility)
$total_balance = array_sum(array_column($vehicles, 'float_balance'));

// Fetch adjustment history (filtered by facility)
if ($is_super_admin) {
    $stmt = $pdo->query("
        SELECT ft.*, v.facility_id, f.facility_name
        FROM float_transactions ft
        LEFT JOIN vehicles v ON ft.float_account = v.float_account_name
        LEFT JOIN facilities f ON v.facility_id = f.id
        WHERE ft.transaction_type IN ('addition', 'deduction', 'adjustment')
        ORDER BY ft.created_at DESC 
        LIMIT 50
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT ft.*, v.facility_id
        FROM float_transactions ft
        LEFT JOIN vehicles v ON ft.float_account = v.float_account_name
        WHERE ft.transaction_type IN ('addition', 'deduction', 'adjustment')
        AND v.facility_id = ?
        ORDER BY ft.created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$user_facility_id]);
}
$adjustmentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Vehicle Fuel Top-Up</title>
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        /* Form Card */
        .form-card {
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

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .form-title {
            color: var(--gray-900);
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-row {
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
        .form-group select,
        .form-group textarea {
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
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(53, 98, 124, 0.12);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.6;
        }

        /* Radio Group */
        .radio-group {
            display: flex;
            gap: 15px;
            margin-top: 8px;
        }

        .radio-option {
            flex: 1;
            position: relative;
        }

        .radio-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .radio-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 20px;
            background: white;
            border: 2px solid var(--gray-300);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 700;
            font-size: 14px;
        }

        .radio-option input[type="radio"]:checked + .radio-label {
            background: var(--light-red);
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        .radio-label:hover {
            border-color: var(--primary-red);
        }

        /* Calculator Preview */
        .calculator-preview {
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }

        .calculator-preview.show {
            display: block;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .calc-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .calc-equation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            font-size: 24px;
            font-weight: 800;
            color: var(--gray-900);
            flex-wrap: wrap;
        }

        .calc-current {
            color: var(--gray-700);
        }

        .calc-operator {
            color: var(--primary-red);
            font-size: 32px;
        }

        .calc-amount {
            color: var(--primary-red);
        }

        .calc-equals {
            color: var(--gray-500);
        }

        .calc-result {
            color: #10b981;
            font-size: 32px;
        }

        .calc-result.deduction {
            color: var(--primary-red);
        }

        /* File Upload */
        .file-upload-area {
            border: 3px dashed var(--gray-300);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            background: var(--gray-50);
            transition: all 0.3s;
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: var(--primary-red);
            background: var(--light-red);
        }

        .file-upload-area.has-file {
            border-color: #10b981;
            background: #dcfce7;
        }

        .upload-icon {
            font-size: 40px;
            color: var(--gray-400);
            margin-bottom: 12px;
        }

        .file-upload-area.has-file .upload-icon {
            color: #10b981;
        }

        .upload-text {
            color: var(--gray-700);
            font-weight: 600;
            margin-bottom: 6px;
        }

        .upload-hint {
            color: var(--gray-500);
            font-size: 13px;
        }

        .file-name-display {
            margin-top: 12px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            color: #10b981;
            font-weight: 700;
            display: none;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .file-name-display.show {
            display: flex;
        }

        input[type="file"] {
            display: none;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 22px rgba(53, 98, 124, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(53, 98, 124, 0.22);
        }

        /* History Table */
        .history-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(53, 98, 124, 0.16);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(22, 32, 42, 0.08);
            position: relative;
            overflow: hidden;
        }

        .history-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dark-red), var(--primary-red));
        }

        .history-title {
            color: var(--gray-900);
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-container {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .history-table th {
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

        .history-table td {
            padding: 14px 16px;
            color: var(--gray-900);
            font-size: 14px;
            font-weight: 500;
            background: white;
            border-bottom: 1px solid var(--gray-200);
        }

        .history-table tbody tr:hover td {
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

        .badge-addition {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-deduction {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .facility-tag {
            font-size: 11px;
            font-weight: 600;
            color: #35627c;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 4px;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .view-btn {
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--dark-red), var(--primary-red));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }

        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(53, 98, 124, 0.24);
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .radio-group {
                flex-direction: column;
            }

            .calc-equation {
                font-size: 18px;
            }

            .calc-result {
                font-size: 24px;
            }

            .history-table {
                font-size: 12px;
            }

            .history-table th,
            .history-table td {
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
                    <i class="fas fa-gas-pump"></i> Vehicle Fuel Top-Up
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
                    <i class="fas fa-car"></i>
                </div>
                <div class="stat-label">
                    <?php if (!$is_super_admin): ?>Your Facility's <?php endif; ?>Vehicles
                </div>
                <div class="stat-value"><?php echo count($vehicles); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-label">
                    <?php if (!$is_super_admin): ?>Your Facility's <?php endif; ?>Total Float Balance
                </div>
                <div class="stat-value" style="font-size: 24px;">K <?php echo number_format($total_balance, 2); ?></div>
            </div>
        </div>

        <!-- Adjustment Form -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-sliders-h"></i> Top-Up Vehicle Fuel
            </div>

            <?php if (count($vehicles) > 0): ?>
            <form method="POST" action="" enctype="multipart/form-data" id="adjustmentForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <i class="fas fa-car"></i> Select Vehicle <span class="required">*</span>
                        </label>
                        <select name="vehicle_id" id="vehicle_select" required onchange="updateCalculator()">
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>" 
                                        data-balance="<?php echo $vehicle['float_balance']; ?>"
                                        data-name="<?php echo htmlspecialchars($vehicle['vehicle_name']); ?>">
                                    <?php echo htmlspecialchars($vehicle['vehicle_name'] . ' - ' . $vehicle['number_plate']); ?> 
                                    (K <?php echo number_format($vehicle['float_balance'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-exchange-alt"></i> Transaction Type <span class="required">*</span>
                        </label>
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="adjustment_type" value="addition" id="addition" checked onchange="updateCalculator()">
                                <label for="addition" class="radio-label">
                                    <i class="fas fa-plus-circle"></i> Add Fuel
                                </label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="adjustment_type" value="deduction" id="deduction" onchange="updateCalculator()">
                                <label for="deduction" class="radio-label">
                                    <i class="fas fa-minus-circle"></i> Deduct Fuel
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-money-bill-wave"></i> Amount (Kwacha) <span class="required">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="amount" 
                        id="amount" 
                        step="0.01" 
                        min="0.01" 
                        placeholder="e.g., 500.00"
                        required
                        oninput="updateCalculator()"
                    >
                </div>

                <!-- Live Calculator Preview -->
                <div class="calculator-preview" id="calculatorPreview">
                    <div class="calc-title">
                        <i class="fas fa-calculator"></i> Calculation Preview
                    </div>
                    <div class="calc-equation">
                        <span class="calc-current">K <span id="calcCurrent">0.00</span></span>
                        <span class="calc-operator" id="calcOperator">+</span>
                        <span class="calc-amount">K <span id="calcAmount">0.00</span></span>
                        <span class="calc-equals">=</span>
                        <span class="calc-result">K <span id="calcResult">0.00</span></span>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-comment-alt"></i> Reason <span class="required">*</span>
                    </label>
                    <textarea 
                        name="reason" 
                        placeholder="e.g., Fuel top-up from finance, Monthly allocation, Correction for error"
                        required
                    ></textarea>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-paperclip"></i> Receipt/Approval Attachment (Optional)
                    </label>
                    <label for="attachment" class="file-upload-area" id="uploadArea">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="upload-text">Click to upload document</div>
                        <div class="upload-hint">Support: JPG, PNG, PDF (Max 5MB)</div>
                    </label>
                    <input 
                        type="file" 
                        id="attachment" 
                        name="attachment" 
                        accept="image/*,.pdf"
                        onchange="handleFileSelect(this)"
                    >
                    <div class="file-name-display" id="fileNameDisplay">
                        <i class="fas fa-check-circle"></i>
                        <span id="fileName"></span>
                    </div>
                </div>

                <button type="submit" name="adjust_fuel" class="submit-btn">
                    <i class="fas fa-save"></i>
                    Submit Top-Up
                </button>
            </form>
            <?php else: ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <i class="fas fa-car-slash"></i>
                <h3 style="color: var(--gray-700); margin: 15px 0 10px 0;">No Vehicles Available</h3>
                <p>There are no vehicles in <?php echo $is_super_admin ? 'the system' : 'your facility'; ?> yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top-Up History -->
        <div class="history-card">
            <div class="history-title">
                <i class="fas fa-history"></i> Top-Up History
                <?php if (!$is_super_admin): ?>
                    <span style="font-size: 14px; font-weight: 600; color: var(--gray-600);">(Your Facility)</span>
                <?php endif; ?>
            </div>
            
            <?php if (count($adjustmentHistory) > 0): ?>
                <div class="table-container">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar"></i> Date & Time</th>
                                <th><i class="fas fa-car"></i> Vehicle</th>
                                <th><i class="fas fa-exchange-alt"></i> Type</th>
                                <th><i class="fas fa-money-bill-wave"></i> Amount (K)</th>
                                <th><i class="fas fa-chart-line"></i> New Balance (K)</th>
                                <th><i class="fas fa-comment"></i> Reason</th>
                                <th><i class="fas fa-user"></i> By</th>
                                <th><i class="fas fa-paperclip"></i> Attachment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustmentHistory as $history): ?>
                            <tr>
                                <td><?php echo date('d M Y H:i', strtotime($history['created_at'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($history['float_account']); ?>
                                    <?php if ($is_super_admin && isset($history['facility_name'])): ?>
                                        <br><span class="facility-tag"><i class="fas fa-building"></i> <?php echo htmlspecialchars($history['facility_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $history['transaction_type']; ?>">
                                        <i class="fas fa-<?php echo $history['transaction_type'] === 'addition' ? 'plus' : 'minus'; ?>-circle"></i>
                                        <?php echo ucfirst($history['transaction_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($history['amount'], 2); ?></td>
                                <td><strong><?php echo number_format($history['new_balance'], 2); ?></strong></td>
                                <td><?php echo htmlspecialchars($history['reason']); ?></td>
                                <td><?php echo htmlspecialchars($history['created_by']); ?></td>
                                <td>
                                    <?php if ($history['photo_data']): ?>
                                        <a href="view_adjustment_photo.php?id=<?php echo $history['id']; ?>" target="_blank" class="view-btn">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--gray-400);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <p style="font-weight: 600; color: var(--gray-700); margin-top: 10px;">No top-up history yet</p>
                    <p style="font-size: 14px; margin-top: 5px;">
                        Top-up transactions<?php echo !$is_super_admin ? ' for your facility' : ''; ?> will appear here once you start managing vehicle fuel
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateCalculator() {
            const vehicleSelect = document.getElementById('vehicle_select');
            const amountInput = document.getElementById('amount');
            const additionRadio = document.getElementById('addition');
            const calculatorPreview = document.getElementById('calculatorPreview');
            
            const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
            const currentBalance = parseFloat(selectedOption.getAttribute('data-balance')) || 0;
            const amount = parseFloat(amountInput.value) || 0;
            const isAddition = additionRadio.checked;
            
            if (vehicleSelect.value && amount > 0) {
                // Calculate with proper precision
                const result = isAddition 
                    ? Math.round((currentBalance + amount) * 100) / 100 
                    : Math.round((currentBalance - amount) * 100) / 100;
                
                // Update display
                document.getElementById('calcCurrent').textContent = currentBalance.toFixed(2);
                document.getElementById('calcOperator').textContent = isAddition ? '+' : '-';
                document.getElementById('calcOperator').style.color = isAddition ? '#10b981' : '#ef4444';
                document.getElementById('calcAmount').textContent = amount.toFixed(2);
                document.getElementById('calcResult').textContent = result.toFixed(2);
                
                const calcResultElement = document.querySelector('.calc-result');
                calcResultElement.className = isAddition ? 'calc-result' : 'calc-result deduction';
                
                calculatorPreview.classList.add('show');
            } else {
                calculatorPreview.classList.remove('show');
            }
        }

        function handleFileSelect(input) {
            const uploadArea = document.getElementById('uploadArea');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            const fileName = document.getElementById('fileName');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = file.size / 1024 / 1024;
                
                if (fileSize > 5) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                
                uploadArea.classList.add('has-file');
                fileNameDisplay.classList.add('show');
                fileName.textContent = file.name;
                
                uploadArea.querySelector('.upload-icon i').className = 'fas fa-check-circle';
                uploadArea.querySelector('.upload-text').textContent = 'Document uploaded successfully';
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