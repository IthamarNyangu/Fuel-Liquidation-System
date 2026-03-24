<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'My Vehicle', 'my_vehicle');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$weekStart = fleet_week_start_from_date($_GET['week_start'] ?? date('Y-m-d'));
$weekEnd = fleet_week_end_from_start($weekStart);

fleet_render_shell_start(
    'My Vehicle',
    'my_vehicle',
    $user,
    ''
);

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-car-side"></i></div>';
    echo '<h2>No vehicle assignment found</h2>';
    echo '<p>This account does not have an active vehicle assignment yet. Once a vehicle is assigned, this page will become the driver landing page for movement legs, fuel purchases, and weekly review.</p>';
    echo '<div class="button-row"><a class="button-secondary" href="dashboard.php"><i class="fas fa-arrow-left"></i>Back to Dashboard</a></div>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

$latestOdometer = fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']);

$tripSummaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS trip_count,
        COALESCE(SUM(total_km), 0) AS total_km
    FROM trip_legs
    WHERE driver_id = ?
      AND vehicle_id = ?
      AND week_start_date = ?
      AND record_status <> 'voided'
");
$tripSummaryStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
$tripSummary = $tripSummaryStmt->fetch() ?: ['trip_count' => 0, 'total_km' => 0];

$fuelSummaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS fuel_count,
        COALESCE(SUM(litres), 0) AS total_litres,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM fuel_purchases
    WHERE driver_id = ?
      AND vehicle_id = ?
      AND week_start_date = ?
      AND record_status <> 'voided'
");
$fuelSummaryStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
$fuelSummary = $fuelSummaryStmt->fetch() ?: ['fuel_count' => 0, 'total_litres' => 0, 'total_amount' => 0];

$weekly = fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
if (!$weekly && ((int) $tripSummary['trip_count'] > 0 || (int) $fuelSummary['fuel_count'] > 0)) {
    $weekly = fleet_ensure_weekly_liquidation(
        $pdo,
        $user['id'],
        (int) $selectedVehicle['id'],
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
        $weekStart
    );
}
if ($weekly) {
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
}

$cardAccountStmt = $pdo->prepare("
    SELECT
        account_name,
        current_balance,
        status,
        fuel_type
    FROM card_accounts
    WHERE vehicle_id = ?
       OR (vehicle_id IS NULL AND facility_id = ?)
    ORDER BY
        CASE WHEN vehicle_id = ? THEN 0 ELSE 1 END,
        CASE status
            WHEN 'active' THEN 0
            WHEN 'blocked' THEN 1
            ELSE 2
        END,
        id
    LIMIT 1
");
$cardAccountStmt->execute([
    $selectedVehicle['id'],
    $selectedVehicle['facility_id'],
    $selectedVehicle['id'],
]);
$cardAccount = $cardAccountStmt->fetch() ?: null;

$recentTripStmt = $pdo->prepare("
    SELECT *
    FROM trip_legs
    WHERE driver_id = ?
      AND vehicle_id = ?
      AND week_start_date = ?
      AND record_status <> 'voided'
    ORDER BY movement_date DESC, time_out DESC, id DESC
    LIMIT 5
");
$recentTripStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
$recentTrips = $recentTripStmt->fetchAll();

$recentFuelStmt = $pdo->prepare("
    SELECT
        fp.*,
        ca.account_name,
        a.id AS receipt_attachment_id
    FROM fuel_purchases fp
    LEFT JOIN card_accounts ca
        ON ca.id = fp.card_account_id
    LEFT JOIN attachments a
        ON a.fuel_purchase_id = fp.id
       AND a.attachment_type = 'receipt'
    WHERE fp.driver_id = ?
      AND fp.vehicle_id = ?
      AND fp.week_start_date = ?
      AND fp.record_status <> 'voided'
    ORDER BY fp.purchase_date DESC, fp.id DESC
    LIMIT 5
");
$recentFuelStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
$recentFuelPurchases = $recentFuelStmt->fetchAll();

$warnings = [];
if (!$cardAccount) {
    $warnings[] = [
        'class' => 'info',
        'title' => 'No card account linked',
        'text' => 'This vehicle does not have a TOM card account linked yet. A driver can still review the vehicle, but fuel purchases need a card account before they can be recorded properly.',
        'icon' => 'fa-wallet',
    ];
} elseif ($cardAccount['status'] === 'blocked') {
    $warnings[] = [
        'class' => 'critical',
        'title' => 'Card account is blocked',
        'text' => 'The linked card account is blocked. Fuel purchases should not continue on this account until it is resolved.',
        'icon' => 'fa-ban',
    ];
}

if ($weekly && (int) ($weekly['missing_receipts'] ?? 0) > 0) {
    $warnings[] = [
        'class' => 'critical',
        'title' => 'Missing receipt attachment',
        'text' => $weekly['missing_receipts'] . ' fuel purchase(s) still need receipt attachments before the week can be submitted cleanly.',
        'icon' => 'fa-paperclip',
    ];
}

if ($weekly && (((int) ($weekly['trip_issue_count'] ?? 0) > 0) || ((int) ($weekly['fuel_issue_count'] ?? 0) > 0))) {
    $warnings[] = [
        'class' => 'caution',
        'title' => 'Some entries need review',
        'text' => $weekly['issue_notes'] ?: 'One or more movement legs or fuel purchases need attention before submission.',
        'icon' => 'fa-triangle-exclamation',
    ];
}

if (((int) $tripSummary['trip_count'] > 0 || (int) $fuelSummary['fuel_count'] > 0)
    && (!$weekly || in_array($weekly['status'], ['draft', 'returned'], true))) {
    $warnings[] = [
        'class' => 'caution',
        'title' => 'This week is not submitted yet',
        'text' => 'You have activity recorded for this week, but the weekly liquidation is still open. Review it before the end of the week.',
        'icon' => 'fa-clipboard-check',
    ];
}

if (!$warnings) {
    $warnings[] = [
        'class' => 'info',
        'title' => 'No current blockers',
        'text' => 'This week is in good shape so far. Continue logging movement legs and fuel purchases as you work.',
        'icon' => 'fa-circle-check',
    ];
}

$previousWeek = (new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d');
$vehicleQueryBase = 'vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=';
$weekStatus = $weekly['status'] ?? 'draft';
$weekStatusLabel = $weekly ? ucwords(str_replace('_', ' ', $weekly['status'])) : 'Draft';
$fullWeekLabel = date('d F Y', strtotime($weekStart)) . ' to ' . date('d F Y', strtotime($weekEnd));
$cardBalanceLabel = $cardAccount ? 'K ' . number_format((float) $cardAccount['current_balance'], 2) : 'Not linked';
$cardBalanceNote = $cardAccount ? $cardAccount['account_name'] : 'No active card';

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div>';
echo '<h2 class="compact-heading">Vehicle And Week</h2>';
echo '</div>';
echo '<div class="week-inline-actions">';
echo '<a class="button-secondary" href="my_vehicle.php?' . $vehicleQueryBase . urlencode($previousWeek) . '"><i class="fas fa-arrow-left"></i>Previous Week</a>';
echo '<a class="button-secondary" href="my_vehicle.php?' . $vehicleQueryBase . urlencode(fleet_week_start_from_date(date('Y-m-d'))) . '">This Week</a>';
echo '<a class="button-secondary" href="my_vehicle.php?' . $vehicleQueryBase . urlencode($nextWeek) . '">Next Week<i class="fas fa-arrow-right"></i></a>';
echo '</div>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="my_vehicle.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i>';
    echo '<span>' . fleet_h($vehicle['vehicle_name']) . ' &middot; ' . fleet_h($vehicle['number_plate']) . '</span>';
    echo '</a>';
}
echo '</div>';
echo '</section>';

echo '<section class="driver-home-grid">';
echo '<div class="panel">';
echo '<div class="panel-header-split">';
echo '<div>';
echo '<h2>Assigned Vehicle</h2>';
echo '</div>';
echo '<span class="status-pill ' . fleet_h($weekStatus) . '">' . fleet_h($weekStatusLabel) . '</span>';
echo '</div>';
echo '<div class="vehicle-identity">';
echo '<div class="vehicle-identity-icon"><i class="fas ' . ($selectedVehicle['asset_type'] === 'motorcycle' ? 'fa-motorcycle' : 'fa-car-side') . '"></i></div>';
echo '<div>';
echo '<h2 class="vehicle-nameplate">' . fleet_h($selectedVehicle['vehicle_name']) . ' &middot; ' . fleet_h($selectedVehicle['number_plate']) . '</h2>';
echo '<p class="vehicle-subtitle">' . fleet_h($selectedVehicle['facility_name'] ?? 'Facility not set') . ($selectedVehicle['province_name'] ? ' &middot; ' . fleet_h($selectedVehicle['province_name']) : '') . '</p>';
echo '</div>';
echo '</div>';
echo '<div class="detail-pairs detail-pairs-quad" style="margin-top:14px;">';
echo '<div class="detail-pair"><span class="detail-pair-label">Fuel Type</span><span class="detail-pair-value">' . fleet_h($selectedVehicle['fuel_type'] ?: 'Not set') . '</span></div>';
echo '<div class="detail-pair"><span class="detail-pair-label">Assignment</span><span class="detail-pair-value">' . fleet_h(ucfirst($selectedVehicle['assignment_type'])) . '</span></div>';
echo '<div class="detail-pair"><span class="detail-pair-label">Card Account</span><span class="detail-pair-value">' . fleet_h($cardBalanceNote) . '</span></div>';
echo '<div class="detail-pair"><span class="detail-pair-label">Week Window</span><span class="detail-pair-value">' . fleet_h(date('d M', strtotime($weekStart)) . ' to ' . date('d M Y', strtotime($weekEnd))) . '</span></div>';
echo '</div>';
echo '<div class="metric-grid driver-key-metrics" style="margin-top:14px;">';
echo '<div class="metric-card metric-card-featured"><div class="metric-label">Current Odometer</div><div class="metric-value">' . fleet_format_km($latestOdometer, false) . '</div><div class="metric-caption">Kilometres</div></div>';
echo '<div class="metric-card metric-card-featured"><div class="metric-label">Fuel/Card Balance</div><div class="metric-value">' . $cardBalanceLabel . '</div><div class="metric-caption">' . fleet_h($cardBalanceNote) . '</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="panel">';
echo '<div class="panel-header-split">';
echo '<div>';
echo '<h2>This Week</h2>';
echo '</div>';
echo '<div class="week-badge"><i class="fas fa-calendar-week"></i><span>' . fleet_h($fullWeekLabel) . '</span></div>';
echo '</div>';
echo '<div class="metric-grid">';
echo '<div class="metric-card"><div class="metric-label">Movements</div><div class="metric-value">' . number_format((int) $tripSummary['trip_count']) . '</div><div class="metric-caption">' . fleet_format_km($tripSummary['total_km']) . '</div></div>';
echo '<div class="metric-card"><div class="metric-label">Fuel Purchases</div><div class="metric-value">' . number_format((int) $fuelSummary['fuel_count']) . '</div><div class="metric-caption">' . number_format((float) $fuelSummary['total_litres'], 2) . ' litres</div></div>';
echo '<div class="metric-card"><div class="metric-label">Fuel Spend</div><div class="metric-value">K ' . number_format((float) $fuelSummary['total_amount'], 2) . '</div><div class="metric-caption">Recorded spend</div></div>';
echo '<div class="metric-card"><div class="metric-label">Missing Receipts</div><div class="metric-value">' . number_format((int) ($weekly['missing_receipts'] ?? 0)) . '</div><div class="metric-caption">' . (((int) ($weekly['missing_receipts'] ?? 0) > 0) ? 'Needs attention' : 'Up to date') . '</div></div>';
echo '</div>';
echo '<div style="margin-top:14px;">';
echo '<h3 class="compact-heading">Quick Actions</h3>';
echo '</div>';
echo '<div class="action-grid action-grid-compact" style="margin-top:10px;">';
echo '<a class="button" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Log Movement</a>';
echo '<a class="button-secondary" href="record_fuel_purchase.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Record Fuel</a>';
echo '<a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Review Week</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header">';
echo '<div>';
echo '<h2>Attention Needed</h2>';
echo '</div>';
echo '</div>';
echo '<div class="warning-list">';
foreach ($warnings as $warning) {
    echo '<div class="warning-item ' . fleet_h($warning['class']) . '">';
    echo '<i class="fas ' . fleet_h($warning['icon']) . '"></i>';
    echo '<div><strong>' . fleet_h($warning['title']) . '</strong><p>' . fleet_h($warning['text']) . '</p></div>';
    echo '</div>';
}
echo '</div>';
echo '</section>';

echo '<section class="driver-home-grid bottom">';
echo '<div class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Recent Movement Legs</h2><p>The latest recorded route segments for the selected week.</p></div>';
echo '<a class="inline-link-button" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Add Leg</a>';
echo '</div>';
if (!$recentTrips) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h3>No movement legs yet</h3>';
    echo '<p>Start with the first actual route segment. Each stop-to-stop movement should be saved as its own leg.</p>';
    echo '</div>';
} else {
    echo '<div class="activity-list">';
    foreach ($recentTrips as $trip) {
        $tripStatusLabel = ucwords(str_replace('_', ' ', (string) $trip['record_status']));
        $tripTimeLabel = date('D, d M Y', strtotime($trip['movement_date'])) . ' &middot; ' . fleet_h(substr((string) $trip['time_out'], 0, 5));
        if ($trip['record_status'] === 'in_progress' || empty($trip['time_in'])) {
            $tripTimeLabel .= ' onward';
        } else {
            $tripTimeLabel .= ' to ' . fleet_h(substr((string) $trip['time_in'], 0, 5));
        }
        $tripTimeLabel .= ' &middot; ' . fleet_h($trip['purpose']);
        $tripValue = ($trip['record_status'] === 'in_progress' || $trip['total_km'] === null)
            ? 'In progress'
            : fleet_format_km($trip['total_km']);
        echo '<div class="activity-row">';
        echo '<div class="activity-main">';
        echo '<p class="activity-title">' . fleet_h($trip['from_location']) . ' to ' . fleet_h($trip['to_location']) . '</p>';
        echo '<p class="activity-meta">' . $tripTimeLabel . '</p>';
        echo '</div>';
        echo '<div class="activity-value">' . fleet_h($tripValue) . '</div>';
        echo '<span class="status-pill ' . fleet_h($trip['record_status']) . '">' . fleet_h($tripStatusLabel) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}
echo '</div>';

echo '<div class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Recent Fuel Purchases</h2></div>';
echo '<a class="inline-link-button" href="record_fuel_purchase.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Add Purchase</a>';
echo '</div>';
if (!$recentFuelPurchases) {
    echo '<div class="empty-state compact-empty">';
    echo '<div class="empty-state-icon"><i class="fas fa-receipt"></i></div>';
    echo '<h3>No fuel purchases yet</h3>';
    echo '<p>Once fuel is bought using the TOM card, record it here with the receipt number and attachment.</p>';
    echo '</div>';
} else {
    echo '<div class="activity-list">';
    foreach ($recentFuelPurchases as $purchase) {
        echo '<div class="activity-row">';
        echo '<div class="activity-main">';
        echo '<p class="activity-title">' . fleet_h($purchase['station_name']) . '</p>';
        echo '<p class="activity-meta">' . date('D, d M Y', strtotime($purchase['purchase_date'])) . ' &middot; Receipt ' . fleet_h($purchase['receipt_number'] ?: 'Not set') . ' &middot; ' . fleet_h($purchase['account_name'] ?: 'Card not linked') . '</p>';
        echo '</div>';
        echo '<div class="activity-value">K ' . number_format((float) $purchase['amount'], 2) . '</div>';
        if (!empty($purchase['receipt_attachment_id'])) {
            echo '<a class="inline-link-button" href="view_attachment.php?id=' . urlencode((string) $purchase['receipt_attachment_id']) . '" target="_blank"><i class="fas fa-paperclip"></i>Receipt</a>';
        } else {
            echo '<span class="status-pill voided">Missing</span>';
        }
        echo '</div>';
    }
    echo '</div>';
}
echo '</div>';
echo '</section>';

fleet_render_shell_end();
