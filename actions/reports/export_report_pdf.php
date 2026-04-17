<?php
// export_report_pdf.php
session_start();
require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'staff';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
$isFacilityAdmin = $_SESSION['is_facility_admin'] ?? false;

// Get user's facility
$facilityStmt = $pdo->prepare("SELECT facility_id FROM users WHERE id = ?");
$facilityStmt->execute([$userId]);
$userFacility = $facilityStmt->fetchColumn();

// Check if user is a facility admin for specific facilities
$facilityAdminFor = [];
if ($isFacilityAdmin) {
    $faStmt = $pdo->prepare("SELECT facility_id FROM facility_admins WHERE user_id = ? AND is_active = 1");
    $faStmt->execute([$userId]);
    $facilityAdminFor = $faStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get filter parameters
$reportType = $_GET['type'] ?? 'vehicle_activity';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedUser = $_GET['user_id'] ?? '';
$selectedVehicle = $_GET['vehicle_id'] ?? '';
$selectedAccount = $_GET['account'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$selectedFacility = $_GET['facility_id'] ?? '';

// Build facility filter
$facilityFilter = '';
$facilityParam = null;
if (!$isSuperAdmin) {
    if ($isFacilityAdmin && !empty($facilityAdminFor)) {
        if ($selectedFacility && in_array($selectedFacility, $facilityAdminFor)) {
            $facilityFilter = ' AND facility_id = ?';
            $facilityParam = $selectedFacility;
        } else {
            $placeholders = str_repeat('?,', count($facilityAdminFor) - 1) . '?';
            $facilityFilter = " AND facility_id IN ($placeholders)";
        }
    } elseif ($userFacility) {
        $facilityFilter = ' AND facility_id = ?';
        $facilityParam = $userFacility;
    }
}

// Get facility name if selected
$facilityName = "All Facilities";
if ($selectedFacility) {
    $facilityStmt = $pdo->prepare("SELECT facility_name FROM facilities WHERE id = ?");
    $facilityStmt->execute([$selectedFacility]);
    $facilityName = $facilityStmt->fetchColumn() ?: "All Facilities";
}

// Generate report data based on type
$reportData = [];
$reportStats = [];
$reportTitle = '';

switch ($reportType) {
    case 'price_history':
        if (!$isSuperAdmin) {
            die('Access denied');
        }
        
        $reportTitle = 'Price Change History';
        $stmt = $pdo->prepare("
            SELECT * FROM fuel_price_history 
            WHERE DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_changes' => count($reportData),
            'avg_price' => count($reportData) > 0 ? array_sum(array_column($reportData, 'new_price')) / count($reportData) : 0,
            'highest_price' => count($reportData) > 0 ? max(array_column($reportData, 'new_price')) : 0,
            'lowest_price' => count($reportData) > 0 ? min(array_column($reportData, 'new_price')) : 0
        ];
        break;
        
    case 'float_adjustments':
        if (!$isSuperAdmin) {
            die('Access denied');
        }
        
        $reportTitle = 'Float Adjustment History';
        $query = "
            SELECT * FROM float_transactions 
            WHERE transaction_type IN ('addition', 'deduction', 'adjustment')
            AND DATE(created_at) BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($selectedAccount) {
            $query .= " AND float_account = ?";
            $params[] = $selectedAccount;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'vehicle_activity':
        $reportTitle = 'Vehicle Activity Report';
        $query = "
            SELECT 
                'requisition' as activity_type,
                r.id,
                r.request_date as activity_date,
                r.request_time as activity_time,
                u.name as staff_name,
                v.vehicle_name,
                v.number_plate,
                f.facility_name,
                r.requested_amount as fuel_amount,
                (r.requested_amount * r.fuel_price_per_liter) as total_cost,
                r.activity_name,
                r.filling_station,
                r.status,
                r.receipt_number,
                r.receipt_data,
                r.receipt_filename,
                r.receipt_type,
                NULL as purpose,
                NULL as route,
                NULL as distance,
                NULL as approver_name
            FROM requisitions r
            JOIN users u ON r.staff_id = u.id
            JOIN vehicles v ON r.vehicle_id = v.id
            LEFT JOIN facilities f ON r.facility_id = f.id
            WHERE DATE(r.request_date) BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($facilityFilter) {
            $query = str_replace('WHERE DATE(r.request_date)', 'WHERE r.facility_id IS NOT NULL ' . str_replace('facility_id', 'r.facility_id', $facilityFilter) . ' AND DATE(r.request_date)', $query);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedUser) {
            $query .= " AND r.staff_id = ?";
            $params[] = $selectedUser;
        }
        
        if ($selectedVehicle) {
            $query .= " AND r.vehicle_id = ?";
            $params[] = $selectedVehicle;
        }
        
        if ($selectedStatus) {
            $query .= " AND r.status = ?";
            $params[] = $selectedStatus;
        }
        
        if ($selectedFacility) {
            $query .= " AND r.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= "
            UNION ALL
            
            SELECT 
                'logbook' as activity_type,
                l.id,
                l.log_date as activity_date,
                l.time_out as activity_time,
                d.name as staff_name,
                v.vehicle_name,
                v.number_plate,
                f.facility_name,
                NULL as fuel_amount,
                NULL as total_cost,
                NULL as activity_name,
                NULL as filling_station,
                NULL as status,
                NULL as receipt_number,
                NULL as receipt_data,
                NULL as receipt_filename,
                NULL as receipt_type,
                l.purpose,
                CONCAT(l.location_from, ' → ', l.location_to) as route,
                l.total_kms as distance,
                a.name as approver_name
            FROM logbook l
            JOIN vehicles v ON l.vehicle_id = v.id
            JOIN users d ON l.driver_id = d.id
            LEFT JOIN users a ON l.approver_id = a.id
            LEFT JOIN facilities f ON l.facility_id = f.id
            WHERE DATE(l.log_date) BETWEEN ? AND ?
        ";
        
        $params[] = $startDate;
        $params[] = $endDate;
        
        if ($facilityFilter) {
            $query .= ' AND l.facility_id IS NOT NULL ' . str_replace('facility_id', 'l.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedUser) {
            $query .= " AND l.driver_id = ?";
            $params[] = $selectedUser;
        }
        
        if ($selectedVehicle) {
            $query .= " AND l.vehicle_id = ?";
            $params[] = $selectedVehicle;
        }
        
        if ($selectedFacility) {
            $query .= " AND l.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " ORDER BY activity_date DESC, activity_time DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $requisitions = array_filter($reportData, fn($r) => $r['activity_type'] === 'requisition');
        $logbookEntries = array_filter($reportData, fn($r) => $r['activity_type'] === 'logbook');
        
        $reportStats = [
            'total_activities' => count($reportData),
            'total_requisitions' => count($requisitions),
            'total_logbook_entries' => count($logbookEntries),
            'total_fuel' => array_sum(array_column($requisitions, 'fuel_amount')),
            'total_cost' => array_sum(array_column($requisitions, 'total_cost')),
            'total_distance' => array_sum(array_column($logbookEntries, 'distance'))
        ];
        break;
        
    case 'vehicle_consumption':
        $reportTitle = 'Vehicle Fuel Consumption';
        $query = "
            SELECT v.vehicle_name, v.number_plate, f.facility_name,
                   COUNT(r.id) as trip_count,
                   COALESCE(SUM(r.requested_amount), 0) as total_liters,
                   COALESCE(SUM(r.requested_amount * r.fuel_price_per_liter), 0) as total_cost,
                   COALESCE(AVG(r.requested_amount), 0) as avg_liters
            FROM vehicles v
            LEFT JOIN facilities f ON v.facility_id = f.id
            LEFT JOIN requisitions r ON v.id = r.vehicle_id 
                AND r.status = 'approved'
                AND DATE(r.request_date) BETWEEN ? AND ?
            WHERE 1=1
        ";
        
        $params = [$startDate, $endDate];
        
        if ($facilityFilter) {
            $query .= str_replace('facility_id', 'v.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedFacility) {
            $query .= " AND v.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " GROUP BY v.id, v.vehicle_name, v.number_plate, f.facility_name
                    ORDER BY total_liters DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_vehicles' => count($reportData),
            'total_consumption' => array_sum(array_column($reportData, 'total_liters')),
            'total_cost' => array_sum(array_column($reportData, 'total_cost'))
        ];
        break;
        
    case 'user_consumption':
        $reportTitle = 'Driver Fuel Consumption';
        $query = "
            SELECT u.name, f.facility_name,
                   COUNT(r.id) as trip_count,
                   COALESCE(SUM(r.requested_amount), 0) as total_liters,
                   COALESCE(SUM(r.requested_amount * r.fuel_price_per_liter), 0) as total_cost,
                   COALESCE(AVG(r.requested_amount), 0) as avg_liters
            FROM users u
            LEFT JOIN facilities f ON u.facility_id = f.id
            LEFT JOIN requisitions r ON u.id = r.staff_id 
                AND r.status = 'approved'
                AND DATE(r.request_date) BETWEEN ? AND ?
            WHERE 1=1
        ";
        
        $params = [$startDate, $endDate];
        
        if ($facilityFilter) {
            $query .= str_replace('facility_id', 'u.facility_id', $facilityFilter);
            if ($facilityParam) {
                $params[] = $facilityParam;
            } elseif ($isFacilityAdmin && !empty($facilityAdminFor)) {
                $params = array_merge($params, $facilityAdminFor);
            }
        }
        
        if ($selectedFacility) {
            $query .= " AND u.facility_id = ?";
            $params[] = $selectedFacility;
        }
        
        $query .= " GROUP BY u.id, u.name, f.facility_name
                    HAVING trip_count > 0
                    ORDER BY total_liters DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportStats = [
            'total_users' => count($reportData),
            'total_consumption' => array_sum(array_column($reportData, 'total_liters')),
            'total_cost' => array_sum(array_column($reportData, 'total_cost'))
        ];
        break;
}

// Generate HTML for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm;
        }
        body {
            font-family: Arial, sans-serif;
            color: #111827;
            line-height: 1.4;
            font-size: 11px;
        }
        .header {
            background: #dc2626;
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 22px;
        }
        .header p {
            margin: 3px 0;
            font-size: 11px;
        }
        .summary {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary-grid {
            display: table;
            width: 100%;
        }
        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 10px 5px;
        }
        .summary-label {
            font-size: 9px;
            color: #6b7280;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .summary-value {
            font-size: 18px;
            color: #dc2626;
            font-weight: bold;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10px;
        }
        .report-table th {
            background: #dc2626;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .report-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #e5e7eb;
        }
        .report-table tr:nth-child(even) {
            background: #f9fafb;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            display: inline-block;
        }
        .badge-requisition { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .badge-logbook { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; }
        .badge-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .activity-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .activity-header {
            background: #fef2f2;
            padding: 10px;
            margin: -15px -15px 12px -15px;
            border-radius: 6px 6px 0 0;
            border-bottom: 2px solid #dc2626;
        }
        .activity-type {
            font-size: 11px;
            font-weight: bold;
            color: #dc2626;
        }
        .activity-date {
            float: right;
            color: #6b7280;
            font-weight: bold;
            font-size: 10px;
        }
        .activity-grid {
            display: table;
            width: 100%;
        }
        .activity-row {
            display: table-row;
        }
        .activity-cell {
            display: table-cell;
            width: 25%;
            padding: 6px 4px;
            vertical-align: top;
        }
        .field-label {
            font-size: 8px;
            color: #6b7280;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .field-value {
            font-size: 10px;
            color: #111827;
            font-weight: 600;
        }
        .receipt-section {
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            border-radius: 6px;
            padding: 12px;
            margin-top: 12px;
        }
        .receipt-section h4 {
            margin: 0 0 8px 0;
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .receipt-image {
            max-width: 100%;
            max-height: 250px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
        }
        .no-receipt {
            text-align: center;
            color: #9ca3af;
            font-style: italic;
            padding: 15px;
            font-size: 10px;
        }
        .footer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 9px;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . htmlspecialchars($reportTitle) . '</h1>
        <p><strong>Facility:</strong> ' . htmlspecialchars($facilityName) . '</p>
        <p><strong>Period:</strong> ' . date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate)) . '</p>
        <p><strong>Generated:</strong> ' . date('d M Y H:i') . '</p>
    </div>
';

// Add summary section
if (!empty($reportStats)) {
    $html .= '<div class="summary"><div class="summary-grid">';
    
    switch ($reportType) {
        case 'vehicle_activity':
            $html .= '
                <div class="summary-item">
                    <div class="summary-label">Total Activities</div>
                    <div class="summary-value">' . $reportStats['total_activities'] . '</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Requisitions</div>
                    <div class="summary-value">' . $reportStats['total_requisitions'] . '</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Logbook Entries</div>
                    <div class="summary-value">' . $reportStats['total_logbook_entries'] . '</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Fuel</div>
                    <div class="summary-value">' . number_format($reportStats['total_fuel'], 2) . ' L</div>
                </div>
            ';
            break;
            
        case 'vehicle_consumption':
        case 'user_consumption':
            $html .= '
                <div class="summary-item">
                    <div class="summary-label">Total ' . ($reportType === 'vehicle_consumption' ? 'Vehicles' : 'Drivers') . '</div>
                    <div class="summary-value">' . $reportStats['total_' . ($reportType === 'vehicle_consumption' ? 'vehicles' : 'users')] . '</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Consumption</div>
                    <div class="summary-value">' . number_format($reportStats['total_consumption'], 2) . ' L</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Cost</div>
                    <div class="summary-value">K ' . number_format($reportStats['total_cost'], 2) . '</div>
                </div>
            ';
            break;
    }
    
    $html .= '</div></div>';
}

// Add report data
if ($reportType === 'vehicle_activity') {
    // Detailed card view for vehicle activity with attachments
    foreach ($reportData as $row) {
        $html .= '
        <div class="activity-card">
            <div class="activity-header">
                <span class="activity-type">' . strtoupper($row['activity_type']) . '</span>
                <span class="activity-date">' . date('d M Y', strtotime($row['activity_date'])) . '</span>
                <div style="clear:both;"></div>
            </div>
            
            <div class="activity-grid">
                <div class="activity-row">
                    <div class="activity-cell">
                        <div class="field-label">Driver</div>
                        <div class="field-value">' . htmlspecialchars($row['staff_name']) . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Vehicle</div>
                        <div class="field-value">' . htmlspecialchars($row['vehicle_name'] . ' (' . $row['number_plate'] . ')') . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Facility</div>
                        <div class="field-value">' . htmlspecialchars($row['facility_name'] ?? 'N/A') . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Type</div>
                        <div class="field-value"><span class="badge badge-' . $row['activity_type'] . '">' . ucfirst($row['activity_type']) . '</span></div>
                    </div>
                </div>';
        
        if ($row['activity_type'] === 'requisition') {
            $html .= '
                <div class="activity-row">
                    <div class="activity-cell">
                        <div class="field-label">Receipt Number</div>
                        <div class="field-value">' . htmlspecialchars($row['receipt_number'] ?? 'N/A') . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Fuel Amount</div>
                        <div class="field-value" style="color: #dc2626;">' . number_format($row['fuel_amount'], 2) . ' L</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Total Cost</div>
                        <div class="field-value" style="color: #dc2626;">K ' . number_format($row['total_cost'], 2) . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Status</div>
                        <div class="field-value"><span class="badge badge-' . $row['status'] . '">' . ucfirst($row['status']) . '</span></div>
                    </div>
                </div>
                <div class="activity-row">
                    <div class="activity-cell">
                        <div class="field-label">Activity</div>
                        <div class="field-value">' . htmlspecialchars($row['activity_name'] ?? 'N/A') . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Filling Station</div>
                        <div class="field-value">' . htmlspecialchars($row['filling_station'] ?? 'N/A') . '</div>
                    </div>
                </div>';
            
            // Add receipt attachment
            $html .= '<div class="receipt-section"><h4>Attached Receipt</h4>';
            if ($row['receipt_filename'] && $row['receipt_data']) {
                if (strpos($row['receipt_type'], 'image') !== false) {
                    $imageData = base64_encode($row['receipt_data']);
                    $html .= '<img src="data:' . $row['receipt_type'] . ';base64,' . $imageData . '" class="receipt-image" />';
                } else {
                    $html .= '<p style="text-align:center; padding: 15px;">
                        <strong>Document:</strong> ' . htmlspecialchars($row['receipt_filename']) . '<br>
                        <em style="color: #6b7280; font-size: 9px;">(' . htmlspecialchars($row['receipt_type']) . ')</em>
                    </p>';
                }
            } else {
                $html .= '<div class="no-receipt">No receipt attached</div>';
            }
            $html .= '</div>';
            
        } else {
            // Logbook entry
            $html .= '
                <div class="activity-row">
                    <div class="activity-cell">
                        <div class="field-label">Purpose</div>
                        <div class="field-value">' . htmlspecialchars($row['purpose']) . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Route</div>
                        <div class="field-value">' . htmlspecialchars($row['route']) . '</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Distance</div>
                        <div class="field-value" style="color: #dc2626;">' . number_format($row['distance'], 2) . ' km</div>
                    </div>
                    <div class="activity-cell">
                        <div class="field-label">Approved By</div>
                        <div class="field-value">' . htmlspecialchars($row['approver_name'] ?? 'Pending') . '</div>
                    </div>
                </div>';
        }
        
        $html .= '</div></div>';
    }
} else {
    // Table view for other reports
    $html .= '<table class="report-table"><thead><tr>';
    
    switch ($reportType) {
        case 'price_history':
            $html .= '<th>Date & Time</th><th>Old Price</th><th>New Price</th><th>% Change</th><th>Changed By</th><th>Reason</th>';
            break;
        case 'vehicle_consumption':
            $html .= '<th>Vehicle</th><th>Facility</th><th>Trips</th><th>Total Liters</th><th>Avg/Trip</th><th>Total Cost</th>';
            break;
        case 'user_consumption':
            $html .= '<th>Driver</th><th>Facility</th><th>Trips</th><th>Total Liters</th><th>Avg/Trip</th><th>Total Cost</th>';
            break;
    }
    
    $html .= '</tr></thead><tbody>';
    
    foreach ($reportData as $row) {
        $html .= '<tr>';
        
        switch ($reportType) {
            case 'price_history':
                $change = $row['new_price'] - $row['old_price'];
                $changePercent = (($change / $row['old_price']) * 100);
                $html .= '<td>' . date('d M Y, h:i A', strtotime($row['created_at'])) . '</td>';
                $html .= '<td>K ' . number_format($row['old_price'], 2) . '</td>';
                $html .= '<td>K ' . number_format($row['new_price'], 2) . '</td>';
                $html .= '<td>' . ($change > 0 ? '+' : '') . number_format($changePercent, 2) . '%</td>';
                $html .= '<td>' . htmlspecialchars($row['changed_by']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['reason']) . '</td>';
                break;
            case 'vehicle_consumption':
                $html .= '<td>' . htmlspecialchars($row['vehicle_name'] . ' (' . $row['number_plate'] . ')') . '</td>';
                $html .= '<td>' . htmlspecialchars($row['facility_name'] ?? 'N/A') . '</td>';
                $html .= '<td>' . $row['trip_count'] . '</td>';
                $html .= '<td>' . number_format($row['total_liters'], 2) . '</td>';
                $html .= '<td>' . number_format($row['avg_liters'], 2) . '</td>';
                $html .= '<td>K ' . number_format($row['total_cost'], 2) . '</td>';
                break;
            case 'user_consumption':
                $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['facility_name'] ?? 'N/A') . '</td>';
                $html .= '<td>' . $row['trip_count'] . '</td>';
                $html .= '<td>' . number_format($row['total_liters'], 2) . '</td>';
                $html .= '<td>' . number_format($row['avg_liters'], 2) . '</td>';
                $html .= '<td>K ' . number_format($row['total_cost'], 2) . '</td>';
                break;
        }
        
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
}

$html .= '
    <div class="footer">
        <p>This is an automatically generated report from the Fuel Liquidation Management System</p>
        <p>© ' . date('Y') . ' All Rights Reserved</p>
    </div>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$filename = str_replace(' ', '_', $reportTitle) . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
