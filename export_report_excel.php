<?php
// export_report_excel.php
session_start();
require_once 'db_config.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

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

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(substr($reportTitle, 0, 31)); // Excel limit

$currentRow = 1;

// Header Section
$sheet->setCellValue('A' . $currentRow, strtoupper($reportTitle));
$sheet->mergeCells('A' . $currentRow . ':J' . $currentRow);
$sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
$sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension($currentRow)->setRowHeight(35);
$currentRow++;

// Report Info
$currentRow++;
$sheet->setCellValue('A' . $currentRow, 'Facility:');
$sheet->setCellValue('B' . $currentRow, $facilityName);
$sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
$currentRow++;

$sheet->setCellValue('A' . $currentRow, 'Period:');
$sheet->setCellValue('B' . $currentRow, date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate)));
$sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
$currentRow++;

$sheet->setCellValue('A' . $currentRow, 'Generated:');
$sheet->setCellValue('B' . $currentRow, date('d M Y H:i'));
$sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
$currentRow++;

// Summary Section
if (!empty($reportStats)) {
    $currentRow++;
    $sheet->setCellValue('A' . $currentRow, 'SUMMARY');
    $sheet->mergeCells('A' . $currentRow . ':F' . $currentRow);
    $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    $currentRow++;

    switch ($reportType) {
        case 'vehicle_activity':
            $sheet->setCellValue('A' . $currentRow, 'Total Activities:');
            $sheet->setCellValue('B' . $currentRow, $reportStats['total_activities']);
            $sheet->setCellValue('C' . $currentRow, 'Total Requisitions:');
            $sheet->setCellValue('D' . $currentRow, $reportStats['total_requisitions']);
            $sheet->setCellValue('E' . $currentRow, 'Logbook Entries:');
            $sheet->setCellValue('F' . $currentRow, $reportStats['total_logbook_entries']);
            $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFont()->setBold(true);
            $currentRow++;
            
            $sheet->setCellValue('A' . $currentRow, 'Total Fuel:');
            $sheet->setCellValue('B' . $currentRow, number_format($reportStats['total_fuel'], 2) . ' L');
            $sheet->setCellValue('C' . $currentRow, 'Total Cost:');
            $sheet->setCellValue('D' . $currentRow, 'K ' . number_format($reportStats['total_cost'], 2));
            $sheet->setCellValue('E' . $currentRow, 'Total Distance:');
            $sheet->setCellValue('F' . $currentRow, number_format($reportStats['total_distance'], 2) . ' km');
            $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle('B' . $currentRow . ':F' . $currentRow)->getFont()->getColor()->setRGB('DC2626');
            break;
            
        case 'vehicle_consumption':
        case 'user_consumption':
            $sheet->setCellValue('A' . $currentRow, 'Total ' . ($reportType === 'vehicle_consumption' ? 'Vehicles:' : 'Drivers:'));
            $sheet->setCellValue('B' . $currentRow, $reportStats['total_' . ($reportType === 'vehicle_consumption' ? 'vehicles' : 'users')]);
            $sheet->setCellValue('C' . $currentRow, 'Total Consumption:');
            $sheet->setCellValue('D' . $currentRow, number_format($reportStats['total_consumption'], 2) . ' L');
            $sheet->setCellValue('E' . $currentRow, 'Total Cost:');
            $sheet->setCellValue('F' . $currentRow, 'K ' . number_format($reportStats['total_cost'], 2));
            $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle('B' . $currentRow . ':F' . $currentRow)->getFont()->getColor()->setRGB('DC2626');
            break;
    }
    
    $currentRow++;
}

// Data Section
$currentRow += 2;
$dataStartRow = $currentRow;

if ($reportType === 'vehicle_activity') {
    // Vehicle Activity Headers
    $headers = [
        'A' => 'Type',
        'B' => 'Date',
        'C' => 'Driver',
        'D' => 'Vehicle',
        'E' => 'Facility',
        'F' => 'Activity/Purpose',
        'G' => 'Fuel (L)',
        'H' => 'Cost (K)',
        'I' => 'Distance (km)',
        'J' => 'Status/Approver'
    ];
    
    foreach ($headers as $col => $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $sheet->getColumnDimension($col)->setWidth($col === 'F' ? 30 : 18);
    }
    
    $sheet->getStyle('A' . $currentRow . ':J' . $currentRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $currentRow . ':J' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
    $sheet->getStyle('A' . $currentRow . ':J' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $currentRow++;
    
    // Data rows
    foreach ($reportData as $row) {
        $sheet->setCellValue('A' . $currentRow, ucfirst($row['activity_type']));
        $sheet->setCellValue('B' . $currentRow, date('d M Y', strtotime($row['activity_date'])));
        $sheet->setCellValue('C' . $currentRow, $row['staff_name']);
        $sheet->setCellValue('D' . $currentRow, $row['vehicle_name'] . ' (' . $row['number_plate'] . ')');
        $sheet->setCellValue('E' . $currentRow, $row['facility_name'] ?? 'N/A');
        
        if ($row['activity_type'] === 'requisition') {
            $sheet->setCellValue('F' . $currentRow, $row['activity_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $currentRow, number_format($row['fuel_amount'], 2));
            $sheet->setCellValue('H' . $currentRow, number_format($row['total_cost'], 2));
            $sheet->setCellValue('I' . $currentRow, '-');
            $sheet->setCellValue('J' . $currentRow, ucfirst($row['status']));
        } else {
            $sheet->setCellValue('F' . $currentRow, $row['purpose']);
            $sheet->setCellValue('G' . $currentRow, '-');
            $sheet->setCellValue('H' . $currentRow, '-');
            $sheet->setCellValue('I' . $currentRow, number_format($row['distance'], 2));
            $sheet->setCellValue('J' . $currentRow, $row['approver_name'] ?? 'Pending');
        }
        
        // Alternate row colors
        if (($currentRow - $dataStartRow) % 2 == 1) {
            $sheet->getStyle('A' . $currentRow . ':J' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        }
        
        $sheet->getStyle('A' . $currentRow . ':J' . $currentRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        $currentRow++;
    }
    
    // Create attachments sheet
    $attachmentSheet = $spreadsheet->createSheet();
    $attachmentSheet->setTitle('Attachments');
    
    $attachmentSheet->setCellValue('A1', 'RECEIPT ATTACHMENTS');
    $attachmentSheet->mergeCells('A1:E1');
    $attachmentSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
    $attachmentSheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
    $attachmentSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $attachmentSheet->getRowDimension(1)->setRowHeight(30);
    
    $attachmentSheet->setCellValue('A3', 'Type');
    $attachmentSheet->setCellValue('B3', 'Date');
    $attachmentSheet->setCellValue('C3', 'Receipt Number');
    $attachmentSheet->setCellValue('D3', 'Filename');
    $attachmentSheet->setCellValue('E3', 'File Type');
    
    $attachmentSheet->getStyle('A3:E3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $attachmentSheet->getStyle('A3:E3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
    $attachmentSheet->getStyle('A3:E3')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    $attachmentSheet->getColumnDimension('A')->setWidth(15);
    $attachmentSheet->getColumnDimension('B')->setWidth(15);
    $attachmentSheet->getColumnDimension('C')->setWidth(20);
    $attachmentSheet->getColumnDimension('D')->setWidth(40);
    $attachmentSheet->getColumnDimension('E')->setWidth(25);
    
    $attachmentRow = 4;
    foreach ($reportData as $row) {
        if ($row['activity_type'] === 'requisition') {
            $attachmentSheet->setCellValue('A' . $attachmentRow, 'Requisition');
            $attachmentSheet->setCellValue('B' . $attachmentRow, date('d M Y', strtotime($row['activity_date'])));
            $attachmentSheet->setCellValue('C' . $attachmentRow, $row['receipt_number'] ?? 'N/A');
            $attachmentSheet->setCellValue('D' . $attachmentRow, $row['receipt_filename'] ?? 'No receipt attached');
            $attachmentSheet->setCellValue('E' . $attachmentRow, $row['receipt_type'] ?? 'N/A');
            
            if (($attachmentRow - 4) % 2 == 0) {
                $attachmentSheet->getStyle('A' . $attachmentRow . ':E' . $attachmentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
            }
            
            $attachmentSheet->getStyle('A' . $attachmentRow . ':E' . $attachmentRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
            
            // Try to embed image if it's an image file
            if ($row['receipt_data'] && $row['receipt_type'] && strpos($row['receipt_type'], 'image') !== false) {
                try {
                    $drawing = new MemoryDrawing();
                    $drawing->setName('Receipt');
                    $drawing->setDescription('Receipt Image');
                    
                    // Create image resource from blob
                    $imageResource = imagecreatefromstring($row['receipt_data']);
                    if ($imageResource !== false) {
                        $drawing->setImageResource($imageResource);
                        $drawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
                        $drawing->setMimeType(MemoryDrawing::MIMETYPE_DEFAULT);
                        $drawing->setHeight(150);
                        $drawing->setCoordinates('F' . $attachmentRow);
                        $drawing->setWorksheet($attachmentSheet);
                        
                        $attachmentSheet->getRowDimension($attachmentRow)->setRowHeight(120);
                    }
                } catch (Exception $e) {
                    // Image embedding failed, just continue
                }
            }
            
            $attachmentRow++;
        }
    }
    
} else {
    // Other report types - table format
    $headers = [];
    
    switch ($reportType) {
        case 'price_history':
            $headers = [
                'A' => 'Date & Time',
                'B' => 'Old Price (K)',
                'C' => 'New Price (K)',
                'D' => '% Change',
                'E' => 'Changed By',
                'F' => 'Reason'
            ];
            break;
        case 'vehicle_consumption':
            $headers = [
                'A' => 'Vehicle',
                'B' => 'Facility',
                'C' => 'Trips',
                'D' => 'Total Liters',
                'E' => 'Avg/Trip',
                'F' => 'Total Cost (K)'
            ];
            break;
        case 'user_consumption':
            $headers = [
                'A' => 'Driver',
                'B' => 'Facility',
                'C' => 'Trips',
                'D' => 'Total Liters',
                'E' => 'Avg/Trip',
                'F' => 'Total Cost (K)'
            ];
            break;
    }
    
    foreach ($headers as $col => $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $sheet->getColumnDimension($col)->setWidth(20);
    }
    
    $lastCol = array_key_last($headers);
    $sheet->getStyle('A' . $currentRow . ':' . $lastCol . $currentRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $currentRow . ':' . $lastCol . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
    $sheet->getStyle('A' . $currentRow . ':' . $lastCol . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $currentRow++;
    
    // Data rows
    foreach ($reportData as $row) {
        switch ($reportType) {
            case 'price_history':
                $change = $row['new_price'] - $row['old_price'];
                $changePercent = (($change / $row['old_price']) * 100);
                $sheet->setCellValue('A' . $currentRow, date('d M Y, h:i A', strtotime($row['created_at'])));
                $sheet->setCellValue('B' . $currentRow, number_format($row['old_price'], 2));
                $sheet->setCellValue('C' . $currentRow, number_format($row['new_price'], 2));
                $sheet->setCellValue('D' . $currentRow, ($change > 0 ? '+' : '') . number_format($changePercent, 2) . '%');
                $sheet->setCellValue('E' . $currentRow, $row['changed_by']);
                $sheet->setCellValue('F' . $currentRow, $row['reason']);
                break;
            case 'vehicle_consumption':
                $sheet->setCellValue('A' . $currentRow, $row['vehicle_name'] . ' (' . $row['number_plate'] . ')');
                $sheet->setCellValue('B' . $currentRow, $row['facility_name'] ?? 'N/A');
                $sheet->setCellValue('C' . $currentRow, $row['trip_count']);
                $sheet->setCellValue('D' . $currentRow, number_format($row['total_liters'], 2));
                $sheet->setCellValue('E' . $currentRow, number_format($row['avg_liters'], 2));
                $sheet->setCellValue('F' . $currentRow, number_format($row['total_cost'], 2));
                break;
            case 'user_consumption':
                $sheet->setCellValue('A' . $currentRow, $row['name']);
                $sheet->setCellValue('B' . $currentRow, $row['facility_name'] ?? 'N/A');
                $sheet->setCellValue('C' . $currentRow, $row['trip_count']);
                $sheet->setCellValue('D' . $currentRow, number_format($row['total_liters'], 2));
                $sheet->setCellValue('E' . $currentRow, number_format($row['avg_liters'], 2));
                $sheet->setCellValue('F' . $currentRow, number_format($row['total_cost'], 2));
                break;
        }
        
        if (($currentRow - $dataStartRow) % 2 == 1) {
            $sheet->getStyle('A' . $currentRow . ':' . $lastCol . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        }
        
        $sheet->getStyle('A' . $currentRow . ':' . $lastCol . $currentRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        $currentRow++;
    }
}

// Set active sheet back to main sheet
$spreadsheet->setActiveSheetIndex(0);

// Create Excel file
$writer = new Xlsx($spreadsheet);

// Set headers for download
$filename = str_replace(' ', '_', $reportTitle) . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
