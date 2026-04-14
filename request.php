<?php
// request.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Driver authentication - allows staff and admins
require_once 'driver_auth.php';
require_once 'db_connect.php';
require_once 'facility_auth.php';

// Get logged-in user information
$logged_in_user_id = $_SESSION['user_id'];
$logged_in_user_name = $_SESSION['user_name'];
$logged_in_user_email = $_SESSION['user_email'];
$logged_in_user_role = $_SESSION['user_role'];

// Get facility information
$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();

// Check if non-super-admin user has a facility assigned
if (!$is_super_admin && !$user_facility_id) {
    die("Error: Your account is not assigned to a facility. Please contact your administrator.");
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Use logged-in user as staff_id (staff member is auto-selected)
        $staff_id = $logged_in_user_id;
        $vehicle_id = $_POST['vehicle_id'];
        $requested_amount = $_POST['requested_amount'];
        $float_account = $_POST['float_account'];
        $mileageRaw = trim((string) ($_POST['mileage'] ?? ''));
        $mileage = (int) $mileageRaw;
        $filling_station = $_POST['filling_station'];
        $activity_name = $_POST['activity_name'];
        $approver_id = $_POST['approver_id'];
        $receipt_number = trim($_POST['receipt_number']);
        $request_date = $_POST['request_date'];
        $request_time = $_POST['request_time'];
        
        // Verify that the vehicle belongs to user's facility (for non-super-admins)
        if (!$is_super_admin) {
            $vehicle_check = $conn->prepare("SELECT facility_id FROM vehicles WHERE id = ?");
            $vehicle_check->bind_param("i", $vehicle_id);
            $vehicle_check->execute();
            $vehicle_result = $vehicle_check->get_result();
            $vehicle_data = $vehicle_result->fetch_assoc();
            
            if ($vehicle_data['facility_id'] != $user_facility_id) {
                throw new Exception('Access denied: This vehicle does not belong to your facility.');
            }
        }
        
        if (!preg_match('/^\d+$/', $mileageRaw)) {
            throw new Exception('Current mileage must be a whole number.');
        }

        // Get current fuel price
        try {
            $stmt = $conn->query("SELECT fuel_price FROM settings WHERE id = 1");
            $settings = $stmt->fetch_assoc();
            $current_price = $settings['fuel_price'] ?? 25.50;
            
            if (!$current_price || $current_price <= 0) {
                $current_price = 25.50;
            }
        } catch (Exception $e) {
            $current_price = 25.50;
        }
        
        // Handle file upload
        $receipt_data = NULL;
        $receipt_filename = NULL;
        $receipt_type = NULL;
        
        if (isset($_FILES['fuel_receipt']) && $_FILES['fuel_receipt']['error'] === UPLOAD_ERR_OK) {
            // Validate file size (max 5MB)
            if ($_FILES['fuel_receipt']['size'] > 5 * 1024 * 1024) {
                throw new Exception('File size must be less than 5MB');
            }
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            if (!in_array($_FILES['fuel_receipt']['type'], $allowed_types)) {
                throw new Exception('Invalid file type. Only JPG, PNG, PDF, DOC, and DOCX are allowed');
            }
            
            $file_extension = strtolower(pathinfo($_FILES['fuel_receipt']['name'], PATHINFO_EXTENSION));
            $receipt_data = file_get_contents($_FILES['fuel_receipt']['tmp_name']);
            $safe_receipt_number = preg_replace('/[^a-zA-Z0-9_-]/', '_', $receipt_number);
            $receipt_filename = $safe_receipt_number . '.' . $file_extension;
            $receipt_type = $_FILES['fuel_receipt']['type'];
        } else if (isset($_FILES['fuel_receipt']) && $_FILES['fuel_receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in HTML form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            $error_message = $upload_errors[$_FILES['fuel_receipt']['error']] ?? 'Unknown upload error';
            throw new Exception('File upload error: ' . $error_message);
        }
        
        // Prepare and execute INSERT
        $stmt = $conn->prepare("
            INSERT INTO requisitions (
                staff_id, vehicle_id, requested_amount, fuel_price_per_liter, 
                float_account, mileage, filling_station, activity_name, 
                approver_id, receipt_number, request_date, request_time, 
                receipt_data, receipt_filename, receipt_type, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->bind_param("iiddsdssissssss",
            $staff_id, 
            $vehicle_id, 
            $requested_amount, 
            $current_price, 
            $float_account, 
            $mileage, 
            $filling_station, 
            $activity_name,
            $approver_id, 
            $receipt_number, 
            $request_date, 
            $request_time,
            $receipt_data, 
            $receipt_filename, 
            $receipt_type
        );
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Fuel request submitted successfully for ' . htmlspecialchars($float_account) . ' account with receipt #' . htmlspecialchars($receipt_number) . '!';
            header("Location: request.php");
            exit();
        } else {
            $message = 'Failed to submit request. Error: ' . $stmt->error;
            $messageType = 'error';
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

// Fetch data for form
try {
    // Get all staff for reference (but logged-in user will be pre-selected)
    $staffList = $conn->query("SELECT id, name FROM users ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    
    // Get vehicle assigned to logged-in user OR all vehicles from user's facility if none assigned
    $assigned_vehicle_query = "
        SELECT 
            v.id, 
            v.vehicle_name, 
            v.number_plate, 
            v.float_account_name, 
            COALESCE(v.float_balance, 0) as float_balance,
            COALESCE(v.current_mileage, 0) as current_mileage,
            v.current_driver_id,
            v.facility_id,
            va.assigned_date
        FROM vehicles v
        LEFT JOIN vehicle_assignments va ON v.id = va.vehicle_id AND va.is_active = 1
        WHERE va.user_id = ?
    ";
    
    // Add facility filter for non-super-admins
    if (!$is_super_admin && $user_facility_id) {
        $assigned_vehicle_query .= " AND v.facility_id = ?";
    }
    
    $assigned_vehicle_query .= " ORDER BY va.assigned_date DESC, v.vehicle_name";
    
    $stmt = $conn->prepare($assigned_vehicle_query);
    if (!$is_super_admin && $user_facility_id) {
        $stmt->bind_param("ii", $logged_in_user_id, $user_facility_id);
    } else {
        $stmt->bind_param("i", $logged_in_user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $assignedVehicles = $result->fetch_all(MYSQLI_ASSOC);
    
    // If no vehicles assigned, get all vehicles from user's facility (for admins)
    if (empty($assignedVehicles) && ($logged_in_user_role === 'admin' || $logged_in_user_role === 'super_admin')) {
        $all_vehicles_query = "
            SELECT 
                id, 
                vehicle_name, 
                number_plate, 
                float_account_name, 
                COALESCE(float_balance, 0) as float_balance,
                COALESCE(current_mileage, 0) as current_mileage,
                current_driver_id,
                facility_id,
                NULL as assigned_date
            FROM vehicles 
            WHERE 1=1
        ";
        
        // Add facility filter for non-super-admins
        if (!$is_super_admin && $user_facility_id) {
            $all_vehicles_query .= " AND facility_id = ?";
        }
        
        $all_vehicles_query .= " ORDER BY vehicle_name";
        
        $stmt = $conn->prepare($all_vehicles_query);
        if (!$is_super_admin && $user_facility_id) {
            $stmt->bind_param("i", $user_facility_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $assignedVehicles = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $assignedVehicles = $conn->query($all_vehicles_query)->fetch_all(MYSQLI_ASSOC);
        }
    }
    
    // Get approvers from user's facility (only admins and super_admins can approve)
    $approvers_query = "SELECT id, name FROM users WHERE role IN ('admin', 'super_admin')";
    if (!$is_super_admin && $user_facility_id) {
        $approvers_query .= " AND facility_id = ?";
    }
    $approvers_query .= " ORDER BY name";
    
    if (!$is_super_admin && $user_facility_id) {
        $stmt = $conn->prepare($approvers_query);
        $stmt->bind_param("i", $user_facility_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $adminsList = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $adminsList = $conn->query($approvers_query)->fetch_all(MYSQLI_ASSOC);
    }

    // Get fuel price
    $stmt = $conn->query("SELECT fuel_price FROM settings LIMIT 1");
    $priceData = $stmt->fetch_assoc();
    $currentFuelPrice = $priceData['fuel_price'] ?? 25.50;
    
    // Get facility name for display
    $facility_display = $is_super_admin ? "All Facilities" : getUserFacilityName($conn);
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

$currentDate = date('Y-m-d');
$currentTime = date('H:i');

// Check if user has assigned vehicles
$hasAssignedVehicles = !empty($assignedVehicles);
$assignedVehicleCount = count($assignedVehicles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Request - <?php echo htmlspecialchars($logged_in_user_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <link rel="stylesheet" type="text/css" href="request.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/request.css')); ?>">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-gas-pump"></i> Request Fuel
                    <span class="facility-badge <?php echo $is_super_admin ? 'super-admin' : ''; ?>">
                        <i class="fas fa-<?php echo $is_super_admin ? 'crown' : 'building'; ?>"></i> 
                        <?php echo htmlspecialchars($facility_display); ?>
                    </span>
                </h1>
                <div class="header-user">
                    <div class="user-name-badge">
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($logged_in_user_name); ?>
                    </div>
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

        <!-- No Vehicle Warning -->
        <?php if (empty($assignedVehicles)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div style="flex: 1;">
                    <strong>No Vehicle Assigned:</strong> You don't have any vehicles assigned to you<?php if (!$is_super_admin): ?> in your facility<?php endif; ?>. Please contact your administrator to assign a vehicle before making fuel requests.
                </div>
            </div>
        <?php endif; ?>

        <!-- Info Box -->
        <div class="info-box">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Current Price</div>
                        <div class="info-value">K <?php echo number_format($currentFuelPrice, 2); ?>/L</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Request Date</div>
                        <div class="info-value"><?php echo date('d M Y'); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Request Time</div>
                        <div class="info-value"><?php echo date('h:i A'); ?></div>
                    </div>
                </div>
                <?php if ($hasAssignedVehicles): ?>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-car"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Your Vehicle<?php echo $assignedVehicleCount > 1 ? 's' : ''; ?></div>
                        <div class="info-value"><?php echo $assignedVehicleCount; ?> Assigned</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form Card -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-file-alt"></i> Submit Fuel Requisition
            </div>

            <form method="POST" action="" enctype="multipart/form-data" id="fuelRequestForm">
                <input type="hidden" name="request_date" value="<?php echo $currentDate; ?>">
                <input type="hidden" name="request_time" value="<?php echo $currentTime; ?>">

                <!-- Driver & Account Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i> Driver & Account Information
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-user"></i> Driver <span class="auto-filled"></span>
                            </label>
                            <input 
                                type="text" 
                                value="<?php echo htmlspecialchars($logged_in_user_name); ?>" 
                                disabled
                                style="background-color: #dcfce7; border-color: #86efac;"
                            >
                            <!-- <small style="color: #166534; margin-top: 5px; font-weight: 600;">
                                <i class="fas fa-info-circle"></i> Based on your logged-in account
                            </small> -->
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-wallet"></i> Float Account <span class="auto-filled"></span>
                            </label>
                            <input 
                                type="text" 
                                name="float_account" 
                                id="float_account" 
                                class="form-input" 
                                readonly 
                                placeholder="Select a vehicle first"
                                required
                                style="background-color: var(--gray-100); cursor: not-allowed;"
                            >
                            <!-- <div id="balanceInfo" style="display: none; margin-top: 8px; padding: 10px; background: #dcfce7; border: 1px solid #86efac; border-radius: 8px;">
                                <span style="color: #166534; font-weight: 600; font-size: 13px;">
                                    <i class="fas fa-check-circle"></i> Available Balance: <span id="balanceAmount">K 0.00</span>
                                </span>
                            </div> -->
                        </div>
                    </div>
                </div>

                <!-- Vehicle Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-car"></i> Vehicle Information
                        <?php if ($hasAssignedVehicles && $assignedVehicleCount == 1): ?>
                        <span class="auto-filled">(Your assigned vehicle)</span>
                        <?php elseif ($hasAssignedVehicles && $assignedVehicleCount > 1): ?>
                        <span class="auto-filled">(Choose from your <?php echo $assignedVehicleCount; ?> assigned vehicles)</span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>
                            <i class="fas fa-car-side"></i> Vehicle <span class="required">*</span>
                        </label>
                        <div class="vehicle-search-group">
                            <select name="vehicle_id" id="vehicle_id" required onchange="updateFloatAccount()">
                                <option value="">Select Vehicle</option>
                                <?php foreach ($assignedVehicles as $index => $vehicle): ?>
                                    <option 
                                        value="<?php echo $vehicle['id']; ?>" 
                                        data-plate="<?php echo htmlspecialchars($vehicle['number_plate']); ?>"
                                        data-float-account="<?php echo htmlspecialchars($vehicle['float_account_name']); ?>"
                                        data-float-balance="<?php echo $vehicle['float_balance']; ?>"
                                        data-current-mileage="<?php echo $vehicle['current_mileage']; ?>"
                                        <?php if ($hasAssignedVehicles && $assignedVehicleCount == 1) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_name'] . ' (' . $vehicle['number_plate'] . ')'); ?>
                                        <?php if (isset($vehicle['assigned_date'])): ?>
                                         
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (count($assignedVehicles) > 1): ?>
                            <button type="button" class="search-btn" onclick="searchVehicle()">
                                <i class="fas fa-search"></i> Search Plate
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Fuel Details Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-gas-pump"></i> Fuel Details
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-droplet"></i> Requested Amount (Liters) <span class="required">*</span>
                            </label>
                            <input 
                                type="number" 
                                name="requested_amount" 
                                step="0.01" 
                                min="0.01" 
                                placeholder="e.g., 45.50"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-tachometer-alt"></i> Current Mileage (KM) <span class="auto-filled"></span>
                            </label>
                            <input 
                                type="number" 
                                name="mileage" 
                                id="mileage"
                                step="1" 
                                min="0" 
                                placeholder="e.g. 12000"
                                required
                                readonly
                                style="background-color: var(--gray-100); cursor: not-allowed;"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-map-marker-alt"></i> Filling Station <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="filling_station" 
                                placeholder="e.g., Total Petrol Station"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-receipt"></i> Receipt Number <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="receipt_number" 
                                placeholder="e.g., REC-2024-0001"
                                required
                            >
                        </div>
                    </div>
                </div>

                <!-- Activity & Approval Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-tasks"></i> Activity & Approval
                    </div>
                    <div class="form-group">
                        <label>
                            <i class="fas fa-clipboard-list"></i> Activity/Purpose <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="activity_name" 
                            placeholder="e.g., Client meeting in Ndola, Field visit to project site"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-user-check"></i> Approver <span class="required">*</span>
                        </label>
                        <select name="approver_id" required>
                            <option value="">Select Approver</option>
                            <?php foreach ($adminsList as $admin): ?>
                                <option value="<?php echo $admin['id']; ?>">
                                    <?php echo htmlspecialchars($admin['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Receipt Upload Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-paperclip"></i> Receipt Attachment <span class="optional">(Optional)</span>
                    </div>
                    <div class="form-group">
                        <label for="fuel_receipt" class="file-upload-area" id="uploadArea">
                            <div class="upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="upload-text">Click to upload receipt (Optional)</div>
                            <div class="upload-hint">Support: JPG, PNG, PDF, DOC, DOCX (Max 5MB)</div>
                        </label>
                        <input 
                            type="file" 
                            id="fuel_receipt" 
                            name="fuel_receipt" 
                            accept="image/*,.pdf,.doc,.docx" 
                            onchange="handleFileSelect(this)"
                        >
                        <div class="file-name-display" id="fileNameDisplay">
                            <i class="fas fa-check-circle"></i>
                            <span id="fileName"></span>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" name="submit_request" class="submit-btn" id="submitBtn" <?php if (!$hasAssignedVehicles) echo 'disabled'; ?>>
                    <i class="fas fa-paper-plane"></i>
                    Submit Fuel Request
                </button>
            </form>
        </div>
    </div>

    <script>
        // Auto-populate on page load if vehicle is pre-selected
        window.addEventListener('DOMContentLoaded', function() {
            updateFloatAccount();
        });

        function updateFloatAccount() {
            const vehicleSelect = document.getElementById('vehicle_id');
            const floatAccountInput = document.getElementById('float_account');
            const mileageInput = document.getElementById('mileage');
            const balanceInfo = document.getElementById('balanceInfo');
            const balanceAmount = document.getElementById('balanceAmount');
            const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
            
            if (selectedOption.value) {
                const floatAccount = selectedOption.getAttribute('data-float-account');
                const floatBalance = parseFloat(selectedOption.getAttribute('data-float-balance'));
                const currentMileage = parseFloat(selectedOption.getAttribute('data-current-mileage'));
                
                // Update float account
                floatAccountInput.value = floatAccount;
                floatAccountInput.style.backgroundColor = '#dcfce7';
                floatAccountInput.style.borderColor = '#86efac';
                floatAccountInput.style.cursor = 'default';
                
                // Update mileage
                mileageInput.value = Math.round(currentMileage).toString();
                mileageInput.style.backgroundColor = '#dcfce7';
                mileageInput.style.borderColor = '#86efac';
                mileageInput.style.cursor = 'default';
                
                // Show balance info
                balanceInfo.style.display = 'block';
                balanceAmount.textContent = 'K ' + floatBalance.toFixed(2);
                
                // Change color based on balance
                if (floatBalance < 100) {
                    balanceInfo.style.background = '#fee2e2';
                    balanceInfo.style.borderColor = '#fca5a5';
                    balanceAmount.parentElement.style.color = '#991b1b';
                } else {
                    balanceInfo.style.background = '#dcfce7';
                    balanceInfo.style.borderColor = '#86efac';
                    balanceAmount.parentElement.style.color = '#166534';
                }
            } else {
                floatAccountInput.value = '';
                floatAccountInput.placeholder = 'Select a vehicle first';
                floatAccountInput.style.backgroundColor = 'var(--gray-100)';
                floatAccountInput.style.borderColor = 'var(--gray-300)';
                floatAccountInput.style.cursor = 'not-allowed';
                
                mileageInput.value = '';
                mileageInput.placeholder = 'Select vehicle first';
                mileageInput.style.backgroundColor = 'var(--gray-100)';
                mileageInput.style.borderColor = 'var(--gray-300)';
                mileageInput.style.cursor = 'not-allowed';
                
                balanceInfo.style.display = 'none';
            }
        }

        function searchVehicle() {
            const searchTerm = prompt('Enter vehicle number plate to search:');
            if (!searchTerm) return;
            
            const select = document.getElementById('vehicle_id');
            const options = select.options;
            const searchLower = searchTerm.toLowerCase();
            
            for (let i = 0; i < options.length; i++) {
                const plate = options[i].getAttribute('data-plate');
                if (plate && plate.toLowerCase().includes(searchLower)) {
                    select.selectedIndex = i;
                    updateFloatAccount();
                    select.focus();
                    return;
                }
            }
            
            alert('No vehicle found with that number plate.');
        }

        function handleFileSelect(input) {
            const uploadArea = document.getElementById('uploadArea');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            const fileName = document.getElementById('fileName');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = file.size / 1024 / 1024; // Convert to MB
                
                if (fileSize > 5) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                
                uploadArea.classList.add('has-file');
                fileNameDisplay.classList.add('show');
                fileName.textContent = file.name;
                
                // Change icon
                uploadArea.querySelector('.upload-icon i').className = 'fas fa-check-circle';
                uploadArea.querySelector('.upload-text').textContent = 'Receipt uploaded successfully';
            }
        }

        // Form submission handling
        document.getElementById('fuelRequestForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
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
