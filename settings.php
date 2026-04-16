<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Admin authentication - only admins and approvers can access
require_once 'admin_auth.php';
require_once 'db_connect.php';

// Check if required tables exist
$tables_check = ['vehicles', 'users', 'settings'];
$missing_tables = [];

foreach ($tables_check as $table) {
    $check_query = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($check_query);
    if (!$result || $result->num_rows == 0) {
        $missing_tables[] = $table;
    }
}

// Check if vehicle_assignments table exists
$va_check = "SHOW TABLES LIKE 'vehicle_assignments'";
$va_result = $conn->query($va_check);
$has_vehicle_assignments = ($va_result && $va_result->num_rows > 0);

// Handle form submissions
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';

// Clear session messages
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Fetch all vehicles
$vehicles_query = "SELECT v.*, u.name as driver_name 
                   FROM vehicles v 
                   LEFT JOIN users u ON v.current_driver_id = u.id 
                   ORDER BY v.vehicle_name";
$vehicles_result = $conn->query($vehicles_query);

// Fetch active users (potential drivers)
$users_query = "SELECT id, name, email, role FROM users WHERE user_status = 'active' ORDER BY name";
$users_result = $conn->query($users_query);

// Fetch current system settings
$settings_query = "SELECT * FROM settings LIMIT 1";
$settings_result = $conn->query($settings_query);
$settings = $settings_result->fetch_assoc();

// Fetch recent vehicle assignments
if ($has_vehicle_assignments) {
    $assignments_query = "SELECT va.*, v.vehicle_name, v.number_plate, 
                                 u.name as driver_name, au.name as assigned_by_name
                          FROM vehicle_assignments va
                          JOIN vehicles v ON va.vehicle_id = v.id
                          JOIN users u ON va.user_id = u.id
                          LEFT JOIN users au ON va.assigned_by = au.id
                          WHERE va.is_active = 1
                          ORDER BY va.created_at DESC";
    $assignments_result = $conn->query($assignments_query);
} else {
    $assignments_result = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Settings - Fuel Management System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
      background: #f5f5f5;
    }
    
    .navbar {
      background: #2c3e50;
      color: white;
      padding: 1rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .navbar-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.5rem;
      font-weight: bold;
    }
    
    .navbar-user {
      display: flex;
      align-items: center;
      gap: 20px;
    }
    
    .nav-links {
      display: flex;
      gap: 20px;
      align-items: center;
    }
    
    .nav-links a {
      color: white;
      text-decoration: none;
      padding: 8px 15px;
      border-radius: 4px;
      transition: background 0.3s;
    }
    
    .nav-links a:hover {
      background: rgba(255,255,255,0.1);
    }
    
    .nav-links a.active {
      background: rgba(255,255,255,0.2);
    }
    
    .user-info {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
    }
    
    .user-name {
      font-weight: 600;
    }
    
    .user-role {
      font-size: 0.85rem;
      color: #bdc3c7;
      text-transform: capitalize;
    }
    
    .logout-btn {
      background: #e74c3c;
      color: white;
      border: none;
      padding: 8px 20px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
      transition: background 0.3s;
      text-decoration: none;
      display: inline-block;
    }
    
    .logout-btn:hover {
      background: #c0392b;
    }
    
    .container {
      max-width: 1400px;
      margin: 2rem auto;
      padding: 0 2rem;
    }
    
    .page-header {
      background: white;
      padding: 1.5rem 2rem;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      margin-bottom: 2rem;
    }
    
    .page-header h1 {
      color: #2c3e50;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 2rem;
      border-bottom: 2px solid #e0e0e0;
    }
    
    .tab {
      padding: 12px 24px;
      background: white;
      border: none;
      cursor: pointer;
      font-size: 16px;
      color: #7f8c8d;
      border-radius: 8px 8px 0 0;
      transition: all 0.3s;
    }
    
    .tab:hover {
      background: #f8f9fa;
    }
    
    .tab.active {
      color: #2c3e50;
      background: white;
      border-bottom: 3px solid #3498db;
      font-weight: 600;
    }
    
    .tab-content {
      display: none;
    }
    
    .tab-content.active {
      display: block;
    }
    
    .card {
      background: white;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      margin-bottom: 2rem;
    }
    
    .card h2 {
      color: #2c3e50;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .alert {
      padding: 12px 20px;
      margin-bottom: 20px;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    .table-container {
      overflow-x: auto;
    }
    
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    
    table th,
    table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #e0e0e0;
    }
    
    table th {
      background: #f8f9fa;
      color: #2c3e50;
      font-weight: 600;
    }
    
    table tr:hover {
      background: #f8f9fa;
    }
    
    .btn {
      padding: 8px 16px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
      transition: all 0.3s;
      text-decoration: none;
      display: inline-block;
    }
    
    .btn-primary {
      background: #3498db;
      color: white;
    }
    
    .btn-primary:hover {
      background: #2980b9;
    }
    
    .btn-success {
      background: #27ae60;
      color: white;
    }
    
    .btn-success:hover {
      background: #229954;
    }
    
    .btn-danger {
      background: #e74c3c;
      color: white;
    }
    
    .btn-danger:hover {
      background: #c0392b;
    }
    
    .btn-secondary {
      background: #95a5a6;
      color: white;
    }
    
    .btn-secondary:hover {
      background: #7f8c8d;
    }
    
    .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
    }
    
    .badge {
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
    }
    
    .badge-success {
      background: #d4edda;
      color: #155724;
    }
    
    .badge-warning {
      background: #fff3cd;
      color: #856404;
    }
    
    .badge-info {
      background: #d1ecf1;
      color: #0c5460;
    }
    
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      animation: fadeIn 0.3s;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .modal.show {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .modal-content {
      background: white;
      padding: 2rem;
      border-radius: 8px;
      max-width: 500px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      animation: slideDown 0.3s;
    }
    
    @keyframes slideDown {
      from {
        transform: translateY(-50px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid #e0e0e0;
    }
    
    .modal-header h3 {
      color: #2c3e50;
      margin: 0;
    }
    
    .close {
      font-size: 28px;
      color: #7f8c8d;
      cursor: pointer;
      border: none;
      background: none;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .close:hover {
      color: #2c3e50;
    }
    
    .form-group {
      margin-bottom: 1.5rem;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: #2c3e50;
      font-weight: 500;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #3498db;
    }
    
    .form-group small {
      display: block;
      margin-top: 5px;
      color: #7f8c8d;
      font-size: 12px;
    }
    
    .modal-footer {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 2rem;
      padding-top: 1rem;
      border-top: 1px solid #e0e0e0;
    }
    
    .no-data {
      text-align: center;
      padding: 2rem;
      color: #7f8c8d;
    }
    
    .driver-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .driver-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #3498db;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="navbar-brand">
      <i class="fas fa-gas-pump"></i>
      <span>Fuel Management System</span>
    </div>
    <div class="nav-links">
      <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
      <a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
    </div>
    <div class="navbar-user">
      <div class="user-info">
        <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        <span class="user-role"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span>
      </div>
      <a href="logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </nav>
  
  <div class="container">
    <div class="page-header">
      <h1><i class="fas fa-cog"></i> System Settings</h1>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
      </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
      </div>
    <?php endif; ?>
    
    <?php if (!empty($missing_tables)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Missing Database Tables:</strong> <?php echo implode(', ', $missing_tables); ?>
        <br>Please import the required SQL files.
      </div>
    <?php endif; ?>
    
    <?php if (!$has_vehicle_assignments): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Vehicle Assignment Setup Required:</strong> The vehicle_assignments table is missing. 
        Please run the SQL script: <code>vehicle_assignments_table.sql</code>
        <br><small>Some features will be disabled until this is completed.</small>
      </div>
    <?php endif; ?>
    
    <div class="tabs">
      <button class="tab active" onclick="switchTab('vehicle-assignment')">
        <i class="fas fa-car"></i> Vehicle Assignment
      </button>
      <button class="tab" onclick="switchTab('fuel-settings')">
        <i class="fas fa-gas-pump"></i> Fuel Settings
      </button>
      <button class="tab" onclick="switchTab('users')">
        <i class="fas fa-users"></i> User Management
      </button>
    </div>
    
    <!-- Vehicle Assignment Tab -->
    <div id="vehicle-assignment" class="tab-content active">
      <div class="card">
        <h2>
          <i class="fas fa-car"></i> Vehicle Assignments
          <?php if ($has_vehicle_assignments): ?>
          <button class="btn btn-primary btn-sm" style="margin-left: auto;" onclick="openAssignModal()">
            <i class="fas fa-plus"></i> Assign Vehicle
          </button>
          <?php else: ?>
          <button class="btn btn-secondary btn-sm" style="margin-left: auto;" disabled title="Setup required">
            <i class="fas fa-exclamation-triangle"></i> Setup Required
          </button>
          <?php endif; ?>
        </h2>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Vehicle</th>
                <th>Number Plate</th>
                <th>Current Mileage</th>
                <th>Assigned Driver</th>
                <th>Float Balance</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($vehicles_result && $vehicles_result->num_rows > 0): ?>
                <?php while ($vehicle = $vehicles_result->fetch_assoc()): ?>
                  <tr>
                    <td><strong><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($vehicle['number_plate']); ?></td>
                    <td><?php echo number_format((int) round((float) $vehicle['current_mileage'])); ?> km</td>
                    <td>
                      <?php if ($vehicle['driver_name']): ?>
                        <div class="driver-info">
                          <div class="driver-avatar">
                            <?php echo strtoupper(substr($vehicle['driver_name'], 0, 1)); ?>
                          </div>
                          <span><?php echo htmlspecialchars($vehicle['driver_name']); ?></span>
                        </div>
                      <?php else: ?>
                        <span class="badge badge-warning">Unassigned</span>
                      <?php endif; ?>
                    </td>
                    <td>K <?php echo number_format($vehicle['float_balance'], 2); ?></td>
                    <td>
                      <?php if ($has_vehicle_assignments): ?>
                      <button class="btn btn-primary btn-sm" 
                              onclick="assignVehicle(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_name']); ?>')">
                        <i class="fas fa-user-check"></i> <?php echo $vehicle['driver_name'] ? 'Reassign' : 'Assign'; ?>
                      </button>
                      <?php if ($vehicle['driver_name']): ?>
                        <button class="btn btn-danger btn-sm" 
                                onclick="unassignVehicle(<?php echo $vehicle['id']; ?>)">
                          <i class="fas fa-user-times"></i> Unassign
                        </button>
                      <?php endif; ?>
                      <?php else: ?>
                      <button class="btn btn-secondary btn-sm" disabled>
                        <i class="fas fa-lock"></i> Setup Required
                      </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="no-data">No vehicles found</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <!-- Recent Assignments -->
      <div class="card">
        <h2><i class="fas fa-history"></i> Recent Assignments</h2>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Assigned By</th>
                <th>Notes</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$has_vehicle_assignments): ?>
                <tr>
                  <td colspan="6" class="no-data">
                    <i class="fas fa-database"></i> 
                    Vehicle assignments table not set up yet. 
                    <br>Please run <strong>vehicle_assignments_table.sql</strong>
                  </td>
                </tr>
              <?php elseif ($assignments_result && $assignments_result->num_rows > 0): ?>
                <?php while ($assignment = $assignments_result->fetch_assoc()): ?>
                  <tr>
                    <td><?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?></td>
                    <td>
                      <strong><?php echo htmlspecialchars($assignment['vehicle_name']); ?></strong><br>
                      <small><?php echo htmlspecialchars($assignment['number_plate']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($assignment['driver_name']); ?></td>
                    <td><?php echo htmlspecialchars($assignment['assigned_by_name'] ?? 'System'); ?></td>
                    <td><?php echo htmlspecialchars($assignment['notes'] ?? '-'); ?></td>
                    <td>
                      <span class="badge badge-success">Active</span>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="no-data">No assignment history</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <!-- Fuel Settings Tab -->
    <div id="fuel-settings" class="tab-content">
      <div class="card">
        <h2><i class="fas fa-gas-pump"></i> Fuel Price & Availability</h2>
        <p style="color: #7f8c8d; margin-bottom: 2rem;">Manage fuel pricing and available fuel quantities</p>
        
        <form action="update_settings.php" method="POST">
          <div class="form-group">
            <label>Fuel Price per Liter (K)</label>
            <input type="number" step="0.01" name="fuel_price" 
                   value="<?php echo $settings['fuel_price'] ?? '0.00'; ?>" required>
          </div>
          
          <div class="form-group">
            <label>Available Fuel (Liters)</label>
            <input type="number" step="0.01" name="available_fuel" 
                   value="<?php echo $settings['available_fuel'] ?? '0.00'; ?>" required>
          </div>
          
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Update Settings
          </button>
        </form>
      </div>
    </div>
    
    <!-- Users Tab -->
    <div id="users" class="tab-content">
      <div class="card">
        <h2><i class="fas fa-users"></i> System Users</h2>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $users_result->data_seek(0); // Reset pointer
              if ($users_result && $users_result->num_rows > 0): 
              ?>
                <?php while ($user = $users_result->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <div class="driver-info">
                        <div class="driver-avatar">
                          <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($user['name']); ?></span>
                      </div>
                    </td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td>
                      <span class="badge badge-info">
                        <?php echo htmlspecialchars($user['role'] === 'staff' ? 'Driver' : ucwords(str_replace('_', ' ', $user['role']))); ?>
                      </span>
                    </td>
                    <td>
                      <button class="btn btn-primary btn-sm" onclick="editUser(<?php echo $user['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit Role
                      </button>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="4" class="no-data">No users found</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Assign Vehicle Modal -->
  <div id="assignModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-user-check"></i> Assign Vehicle to Driver</h3>
        <button class="close" onclick="closeAssignModal()">&times;</button>
      </div>
      
      <form action="assign_vehicle.php" method="POST">
        <input type="hidden" name="vehicle_id" id="modal_vehicle_id">
        
        <div class="form-group">
          <label>Vehicle</label>
          <input type="text" id="modal_vehicle_name" readonly style="background: #f8f9fa;">
        </div>
        
        <div class="form-group">
          <label>Select Driver <span style="color: red;">*</span></label>
          <select name="user_id" required>
            <option value="">-- Select a driver --</option>
            <?php 
            $users_result->data_seek(0); // Reset pointer
            while ($user = $users_result->fetch_assoc()): 
            ?>
              <option value="<?php echo $user['id']; ?>">
                <?php echo htmlspecialchars($user['name']); ?> 
                (<?php echo htmlspecialchars($user['email']); ?>)
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label>Assignment Date <span style="color: red;">*</span></label>
          <input type="date" name="assigned_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Notes (Optional)</label>
          <textarea name="notes" rows="3" placeholder="Any additional notes about this assignment..."></textarea>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-check"></i> Assign Vehicle
          </button>
        </div>
      </form>
    </div>
  </div>
  
  <script>
    // Tab switching
    function switchTab(tabName) {
      // Hide all tab contents
      document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
      });
      
      // Remove active class from all tabs
      document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Show selected tab content
      document.getElementById(tabName).classList.add('active');
      
      // Add active class to clicked tab
      event.target.classList.add('active');
    }
    
    // Modal functions
    function openAssignModal() {
      document.getElementById('assignModal').classList.add('show');
    }
    
    function closeAssignModal() {
      document.getElementById('assignModal').classList.remove('show');
    }
    
    function assignVehicle(vehicleId, vehicleName) {
      document.getElementById('modal_vehicle_id').value = vehicleId;
      document.getElementById('modal_vehicle_name').value = vehicleName;
      openAssignModal();
    }
    
    function unassignVehicle(vehicleId) {
      if (confirm('Are you sure you want to unassign this vehicle from its current driver?')) {
        window.location.href = 'unassign_vehicle.php?vehicle_id=' + vehicleId;
      }
    }
    
    function editUser(userId) {
      alert('User role editing functionality coming soon!');
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('assignModal');
      if (event.target === modal) {
        closeAssignModal();
      }
    }
  </script>
</body>
</html>

<?php $conn->close(); ?>
