<?php
// weekly_report.php
require_once 'db_config.php'; // Your database connection

// Get filter parameters
$selectedUser = $_GET['user_id'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
$endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week'));

// Fetch all users for dropdown
$usersStmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query
$query = "
    SELECT 
        r.id,
        r.receipt_number,
        r.request_date,
        r.requested_amount,
        r.fuel_price_per_liter,
        r.activity_name,
        r.receipt_filename,
        r.receipt_type,
        r.mileage,
        r.filling_station,
        u.name as staff_name,
        v.vehicle_name,
        v.number_plate
    FROM requisitions r
    JOIN users u ON r.staff_id = u.id
    JOIN vehicles v ON r.vehicle_id = v.id
    WHERE r.status = 'approved'
    AND r.request_date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];

if ($selectedUser) {
    $query .= " AND r.staff_id = ?";
    $params[] = $selectedUser;
}

$query .= " ORDER BY r.request_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = 0;
$totalLiters = 0;

foreach ($requisitions as $req) {
    $totalLiters += $req['requested_amount'];
    $totalAmount += ($req['requested_amount'] * $req['fuel_price_per_liter']);
}

// Get user name for display
$userName = "All Users";
if ($selectedUser) {
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$selectedUser]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userData['name'] ?? "Unknown User";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Fuel Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="weekly_report.css">
    <style>      
    </style>
    <script> /////New File View
        function toggleDetails(index) {
            const row = document.getElementById('details-' + index);

    if (row.style.display === "none") {
        row.style.display = "table-row";
    } else {
        row.style.display = "none";
    }
    }
    </script>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-chart-bar"></i> Weekly Fuel Report</h1>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Filters -->
            <form method="GET" class="filters">
                <div class="filter-group">
                    <label><i class="fas fa-user"></i> User/Driver</label>
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    <?php echo $selectedUser == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>" required>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar-check"></i> End Date</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>" required>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-apply">
                        <i class="fas fa-search"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <h3><i class="fas fa-user-circle"></i> Driver</h3>
                <div class="summary-value"><?php echo htmlspecialchars($userName); ?></div>
            </div>

            <div class="summary-card">
                <h3><i class="fas fa-calendar-week"></i> Period</h3>
                <div class="summary-value"><?php echo count($requisitions); ?></div>
                <div class="summary-subtitle">Transactions</div>
            </div>

            <div class="summary-card">
                <h3><i class="fas fa-gas-pump"></i> Total Liters</h3>
                <div class="summary-value"><?php echo number_format($totalLiters, 2); ?></div>
                <div class="summary-subtitle">Liters Used</div>
            </div>

            <div class="summary-card">
                <h3><i class="fas fa-money-bill-wave"></i> Total Amount</h3>
                <div class="summary-value">K <?php echo number_format($totalAmount, 2); ?></div>
                <div class="summary-subtitle">Kwacha</div>
            </div>
        </div>

        <!-- Export Actions -->
        <div class="export-actions">
            <button onclick="window.print()" class="btn-export btn-pdf">
                <i class="fas fa-print"></i> Print Report
            </button>
            <a href="export_report_pdf.php?user_id=<?php echo $selectedUser; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
               class="btn-export btn-pdf">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
            <a href="export_report_excel.php?user_id=<?php echo $selectedUser; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" 
               class="btn-export btn-excel">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>

        <!-- Report Content /////////-->
        <div class="report-content">
    <div class="report-title">
        <i class="fas fa-list-alt"></i>
        Transaction Details: 
        <?php echo date('d M Y', strtotime($startDate)); ?> - 
        <?php echo date('d M Y', strtotime($endDate)); ?>
    </div>

    <?php if (count($requisitions) > 0): ?>

        <div class="table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt #</th>
                        <th>Driver</th>
                        <th>Vehicle</th>
                        <th>Liters</th>
                        <th>Total (K)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php foreach ($requisitions as $index => $req): ?>
                    <?php 
                        $total = $req['requested_amount'] * $req['fuel_price_per_liter'];
                    ?>

                    <!-- Summary Row -->
                    <tr class="summary-row" onclick="toggleDetails(<?php echo $index; ?>)">
                        <td><?php echo date('d M Y', strtotime($req['request_date'])); ?></td>
                        <td><?php echo htmlspecialchars($req['receipt_number'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                        <td><?php echo htmlspecialchars($req['number_plate']); ?></td>
                        <td><?php echo number_format($req['requested_amount'], 2); ?> L</td>
                        <td>K <?php echo number_format($total, 2); ?></td>
                        <td><i class="fas fa-chevron-down"></i></td>
                    </tr>

                    <!-- Hidden Detail Row -->
                    <tr class="details-row" id="details-<?php echo $index; ?>" style="display:none;">
                        <td colspan="7">
                            <div class="details-content">

                                <p><strong>Fuel Price/Liter:</strong> 
                                    K <?php echo number_format($req['fuel_price_per_liter'], 2); ?>
                                </p>

                                <p><strong>Filling Station:</strong> 
                                    <?php echo htmlspecialchars($req['filling_station'] ?? 'N/A'); ?>
                                </p>

                                <p><strong>Mileage:</strong> 
                                    <?php echo $req['mileage'] ? number_format($req['mileage'],1).' KM' : 'N/A'; ?>
                                </p>

                                <p><strong>Activity:</strong> 
                                    <?php echo htmlspecialchars($req['activity_name'] ?? 'N/A'); ?>
                                </p>

                                <!-- Receipt -->
                                <?php if ($req['receipt_filename']): ?>
                                    <p>
                                        <a href="download_receipt.php?id=<?php echo $req['id']; ?>" target="_blank">
                                            <i class="fas fa-paperclip"></i> View Receipt
                                        </a>
                                    </p>
                                <?php else: ?>
                                    <p>No receipt attached</p>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox"></i>
            <h3>No Transactions Found</h3>
            <p>No approved fuel requisitions found for the selected period and user.</p>
        </div>
    <?php endif; ?>
</div>
    </div>
</body>
</html>