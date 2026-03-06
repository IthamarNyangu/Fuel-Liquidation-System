<?php
require_once('vendor/autoload.php'); // For Composer autoload

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Database configuration
require_once __DIR__ . '/db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get parameters
$reportType = $_GET['report_type'] ?? '';
$format = $_GET['format'] ?? 'pdf';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// Month names
$monthNames = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

// Generate report based on type
switch($reportType) {
    case 'monthly_consumption':
        generateMonthlyConsumptionReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'vehicle_usage':
        generateVehicleUsageReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'staff_requisition':
        generateStaffRequisitionReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'approval_analysis':
        generateApprovalAnalysisReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'fuel_inventory':
        generateFuelInventoryReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'cost_analysis':
        generateCostAnalysisReport($pdo, $month, $year, $format, $monthNames);
        break;
    case 'price_history':
        generatePriceHistoryReport($pdo, $startDate, $endDate, $format);
        break;
    case 'yearly_summary':
        generateYearlySummaryReport($pdo, $year, $format);
        break;
    default:
        die("Invalid report type");
}

// 1. Monthly Fuel Consumption Report
function generateMonthlyConsumptionReport($pdo, $month, $year, $format, $monthNames) {
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            u.name as staff_name,
            v.vehicle_name,
            v.number_plate,
            r.requested_amount,
            r.fuel_price_per_liter,
            (r.requested_amount * r.fuel_price_per_liter) as total_cost,
            r.request_date,
            r.status,
            r.notes
        FROM requisitions r
        JOIN users u ON r.staff_id = u.id
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE DATE_FORMAT(r.request_date, '%Y-%m') = ?
        ORDER BY r.request_date DESC
    ");
    $stmt->execute([$year . '-' . $month]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $totalApproved = 0;
    $totalRejected = 0;
    $totalPending = 0;
    $costApproved = 0;
    $costRejected = 0;
    $costPending = 0;
    
    foreach ($data as $row) {
        if ($row['status'] === 'approved') {
            $totalApproved += $row['requested_amount'];
            $costApproved += $row['total_cost'];
        } elseif ($row['status'] === 'rejected') {
            $totalRejected += $row['requested_amount'];
            $costRejected += $row['total_cost'];
        } else {
            $totalPending += $row['requested_amount'];
            $costPending += $row['total_cost'];
        }
    }
    
    $reportTitle = "Monthly Fuel Consumption Report - " . $monthNames[$month] . " $year";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Staff', 'Vehicle', 'Number Plate', 'Amount (L)', 'Price/L (ZMW)', 
            'Total (ZMW)', 'Date', 'Status', 'Notes'
        ], [
            ['Summary', ''],
            ['Total Approved', number_format($totalApproved, 2) . ' L'],
            ['Cost Approved', 'ZMW ' . number_format($costApproved, 2)],
            ['Total Rejected', number_format($totalRejected, 2) . ' L'],
            ['Cost Rejected', 'ZMW ' . number_format($costRejected, 2)],
            ['Total Pending', number_format($totalPending, 2) . ' L'],
            ['Cost Pending', 'ZMW ' . number_format($costPending, 2)],
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Staff', 'Vehicle', 'Plate', 'Amt (L)', 'Price', 'Total', 'Date', 'Status'
        ]);
    }
}

// 2. Vehicle Fuel Usage Report
function generateVehicleUsageReport($pdo, $month, $year, $format, $monthNames) {
    $whereClause = "WHERE r.status = 'approved'";
    $params = [];
    
    if ($month && $year) {
        $whereClause .= " AND DATE_FORMAT(r.request_date, '%Y-%m') = ?";
        $params[] = $year . '-' . $month;
    } elseif ($year) {
        $whereClause .= " AND YEAR(r.request_date) = ?";
        $params[] = $year;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            v.vehicle_name,
            v.number_plate,
            COUNT(r.id) as total_requisitions,
            SUM(r.requested_amount) as total_fuel,
            AVG(r.fuel_price_per_liter) as avg_price,
            SUM(r.requested_amount * r.fuel_price_per_liter) as total_cost
        FROM vehicles v
        LEFT JOIN requisitions r ON v.id = r.vehicle_id
        $whereClause
        GROUP BY v.id
        ORDER BY total_fuel DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $periodText = $month && $year ? $monthNames[$month] . " $year" : ($year ? "Year $year" : "All Time");
    $reportTitle = "Vehicle Fuel Usage Report - $periodText";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Vehicle', 'Number Plate', 'Total Requisitions', 'Total Fuel (L)', 
            'Avg Price/L (ZMW)', 'Total Cost (ZMW)'
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Vehicle', 'Plate', 'Requisitions', 'Fuel (L)', 'Avg Price', 'Cost'
        ]);
    }
}

// 3. Staff Fuel Requisition Report
function generateStaffRequisitionReport($pdo, $month, $year, $format, $monthNames) {
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if ($month && $year) {
        $whereClause .= " AND DATE_FORMAT(r.request_date, '%Y-%m') = ?";
        $params[] = $year . '-' . $month;
    } elseif ($year) {
        $whereClause .= " AND YEAR(r.request_date) = ?";
        $params[] = $year;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            u.name as staff_name,
            COUNT(r.id) as total_requests,
            SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN r.status = 'approved' THEN r.requested_amount ELSE 0 END) as total_fuel,
            SUM(CASE WHEN r.status = 'approved' THEN r.requested_amount * r.fuel_price_per_liter ELSE 0 END) as total_cost
        FROM users u
        LEFT JOIN requisitions r ON u.id = r.staff_id
        $whereClause
        GROUP BY u.id
        ORDER BY total_fuel DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $periodText = $month && $year ? $monthNames[$month] . " $year" : ($year ? "Year $year" : "All Time");
    $reportTitle = "Staff Fuel Requisition Report - $periodText";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Staff Name', 'Total Requests', 'Approved', 'Rejected', 'Pending', 
            'Total Fuel (L)', 'Total Cost (ZMW)'
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Staff', 'Requests', 'Approved', 'Rejected', 'Pending', 'Fuel (L)', 'Cost'
        ]);
    }
}

// 4. Approval Analysis Report
function generateApprovalAnalysisReport($pdo, $month, $year, $format, $monthNames) {
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if ($month && $year) {
        $whereClause .= " AND DATE_FORMAT(request_date, '%Y-%m') = ?";
        $params[] = $year . '-' . $month;
    } elseif ($year) {
        $whereClause .= " AND YEAR(request_date) = ?";
        $params[] = $year;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(requested_amount) as total_fuel,
            SUM(requested_amount * fuel_price_per_liter) as total_cost,
            notes
        FROM requisitions
        $whereClause
        GROUP BY status, notes
        ORDER BY status, count DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $periodText = $month && $year ? $monthNames[$month] . " $year" : ($year ? "Year $year" : "All Time");
    $reportTitle = "Approval vs Rejection Analysis - $periodText";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Status', 'Count', 'Total Fuel (L)', 'Total Cost (ZMW)', 'Notes/Reason'
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Status', 'Count', 'Fuel (L)', 'Cost', 'Notes'
        ]);
    }
}

// 5. Fuel Inventory Report
function generateFuelInventoryReport($pdo, $month, $year, $format, $monthNames) {
    // Get current inventory
    $stmt = $pdo->query("SELECT available_fuel, fuel_price FROM settings LIMIT 1");
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if ($month && $year) {
        $whereClause .= " AND DATE_FORMAT(created_at, '%Y-%m') = ?";
        $params[] = $year . '-' . $month;
    } elseif ($year) {
        $whereClause .= " AND YEAR(created_at) = ?";
        $params[] = $year;
    }
    
    // Get top-ups
    $stmt = $pdo->prepare("
        SELECT 
            created_at as date,
            'Top Up' as type,
            added_amount as amount,
            reason
        FROM fuel_topup_history
        $whereClause
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $topups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get consumption
    $stmt = $pdo->prepare("
        SELECT 
            request_date as date,
            'Consumption' as type,
            requested_amount as amount,
            CONCAT(u.name, ' - ', v.vehicle_name) as reason
        FROM requisitions r
        JOIN users u ON r.staff_id = u.id
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'approved'
        " . str_replace('created_at', 'request_date', $whereClause) . "
        ORDER BY request_date DESC
    ");
    $stmt->execute($params);
    $consumption = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge data
    $data = array_merge($topups, $consumption);
    usort($data, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    $periodText = $month && $year ? $monthNames[$month] . " $year" : ($year ? "Year $year" : "All Time");
    $reportTitle = "Fuel Inventory Report - $periodText";
    
    $summary = [
        ['Current Available Fuel', number_format($current['available_fuel'], 2) . ' L'],
        ['Current Fuel Price', 'ZMW ' . number_format($current['fuel_price'], 2)],
    ];
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Date', 'Type', 'Amount (L)', 'Reason/Details'
        ], $summary);
    } else {
        exportToPDF($data, $reportTitle, [
            'Date', 'Type', 'Amount (L)', 'Details'
        ]);
    }
}

// 6. Cost Analysis Report
function generateCostAnalysisReport($pdo, $month, $year, $format, $monthNames) {
    $whereClause = "WHERE r.status = 'approved'";
    $params = [];
    
    if ($month && $year) {
        $whereClause .= " AND DATE_FORMAT(r.request_date, '%Y-%m') = ?";
        $params[] = $year . '-' . $month;
    } elseif ($year) {
        $whereClause .= " AND YEAR(r.request_date) = ?";
        $params[] = $year;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            r.request_date,
            r.fuel_price_per_liter,
            u.name as staff_name,
            v.vehicle_name,
            r.requested_amount,
            (r.requested_amount * r.fuel_price_per_liter) as total_cost
        FROM requisitions r
        JOIN users u ON r.staff_id = u.id
        JOIN vehicles v ON r.vehicle_id = v.id
        $whereClause
        ORDER BY r.request_date DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCost = array_sum(array_column($data, 'total_cost'));
    $totalFuel = array_sum(array_column($data, 'requested_amount'));
    $avgPrice = $totalFuel > 0 ? $totalCost / $totalFuel : 0;
    
    $periodText = $month && $year ? $monthNames[$month] . " $year" : ($year ? "Year $year" : "All Time");
    $reportTitle = "Cost Analysis Report - $periodText";
    
    $summary = [
        ['Total Expenditure', 'ZMW ' . number_format($totalCost, 2)],
        ['Total Fuel Consumed', number_format($totalFuel, 2) . ' L'],
        ['Average Price per Liter', 'ZMW ' . number_format($avgPrice, 2)],
    ];
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Date', 'Price/L (ZMW)', 'Staff', 'Vehicle', 'Amount (L)', 'Total Cost (ZMW)'
        ], $summary);
    } else {
        exportToPDF($data, $reportTitle, [
            'Date', 'Price/L', 'Staff', 'Vehicle', 'Fuel (L)', 'Cost'
        ]);
    }
}

// 7. Price History Report
function generatePriceHistoryReport($pdo, $startDate, $endDate, $format) {
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if ($startDate) {
        $whereClause .= " AND DATE(created_at) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $whereClause .= " AND DATE(created_at) <= ?";
        $params[] = $endDate;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            created_at,
            old_price,
            new_price,
            (new_price - old_price) as price_change,
            reason,
            changed_by
        FROM fuel_price_history
        $whereClause
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $dateRange = $startDate && $endDate ? "$startDate to $endDate" : "All Time";
    $reportTitle = "Fuel Price History Report - $dateRange";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Date', 'Old Price (ZMW)', 'New Price (ZMW)', 'Change (ZMW)', 'Reason', 'Changed By'
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Date', 'Old Price', 'New Price', 'Change', 'Reason', 'Changed By'
        ]);
    }
}

// 8. Yearly Summary Report
function generateYearlySummaryReport($pdo, $year, $format) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(request_date, '%m') as month_num,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'approved' THEN requested_amount ELSE 0 END) as approved_fuel,
            SUM(CASE WHEN status = 'approved' THEN requested_amount * fuel_price_per_liter ELSE 0 END) as approved_cost,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
        FROM requisitions
        WHERE YEAR(request_date) = ?
        GROUP BY month_num
        ORDER BY month_num
    ");
    $stmt->execute([$year]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $months = [
        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
    ];
    
    // Add month names
    foreach ($data as &$row) {
        $row['month_name'] = $months[$row['month_num']];
    }
    
    $reportTitle = "Yearly Summary Report - $year";
    
    if ($format === 'excel') {
        exportToExcel($data, $reportTitle, [
            'Month', 'Total Requests', 'Approved Fuel (L)', 'Approved Cost (ZMW)', 
            'Rejected', 'Pending'
        ]);
    } else {
        exportToPDF($data, $reportTitle, [
            'Month', 'Requests', 'Fuel (L)', 'Cost', 'Rejected', 'Pending'
        ]);
    }
}

// Excel Export Function
function exportToExcel($data, $title, $headers, $summary = null) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set title
    $sheet->setCellValue('A1', $title);
    $sheet->mergeCells('A1:' . chr(64 + count($headers)) . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Add generation date
    $sheet->setCellValue('A2', 'Generated on: ' . date('Y-m-d H:i:s'));
    $sheet->mergeCells('A2:' . chr(64 + count($headers)) . '2');
    
    $row = 4;
    
    // Add summary if provided
    if ($summary) {
        $sheet->setCellValue('A' . $row, 'SUMMARY');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        
        foreach ($summary as $sumRow) {
            $sheet->setCellValue('A' . $row, $sumRow[0]);
            $sheet->setCellValue('B' . $row, $sumRow[1]);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
        }
        $row += 2;
    }
    
    // Set headers
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getStyle($col . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DC2626');
        $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
        $col++;
    }
    
    $row++;
    
    // Add data
    foreach ($data as $dataRow) {
        $col = 'A';
        foreach ($dataRow as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }
    
    // Auto-size columns
    foreach (range('A', chr(64 + count($headers))) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Output
    $filename = str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// PDF Export Function (Simple HTML to PDF)
function exportToPDF($data, $title, $headers) {
    // For PDF generation, we'll use a simple HTML approach
    // You can enhance this with TCPDF or similar library
    
    $html = '<html><head><style>
        body { font-family: Arial, sans-serif; }
        h1 { color: #dc2626; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }
        th { background-color: #dc2626; color: white; padding: 8px; text-align: left; }
        td { border: 1px solid #ddd; padding: 6px; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .date { text-align: center; color: #666; margin-bottom: 20px; }
    </style></head><body>';
    
    $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
    $html .= '<div class="date">Generated on: ' . date('Y-m-d H:i:s') . '</div>';
    $html .= '<table><thead><tr>';
    
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    
    $html .= '</tr></thead><tbody>';
    
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $value) {
            $html .= '<td>' . htmlspecialchars($value) . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table></body></html>';
    
    // Use DomPDF for better PDF generation
    require_once 'vendor/autoload.php';
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    $filename = str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
?>