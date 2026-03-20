<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Weekly Liquidation', 'weekly_liquidation');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_REQUEST['vehicle_id']) ? (int) $_REQUEST['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$weekStart = fleet_week_start_from_date($_REQUEST['week_start'] ?? date('Y-m-d'));
$pageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_weekly']) && $selectedVehicle) {
    try {
        $weekly = fleet_ensure_weekly_liquidation(
            $pdo,
            $user['id'],
            (int) $selectedVehicle['id'],
            $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
            $weekStart
        );
        $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);

        if (!fleet_week_is_editable($weekly)) {
            throw new RuntimeException('This liquidation has already been submitted or approved.');
        }

        $itemCount = (int) $weekly['total_trip_legs'] + (int) $weekly['total_fuel_purchases'];
        if ($itemCount === 0) {
            throw new RuntimeException('Nothing has been recorded for this week yet. Add movement legs or fuel purchases before submitting.');
        }

        if ((int) $weekly['has_issues'] === 1) {
            throw new RuntimeException('This weekly liquidation still has issues: ' . ($weekly['issue_notes'] ?: 'resolve the flagged items before submitting.'));
        }

        $pdo->beginTransaction();

        $submitStmt = $pdo->prepare("
            UPDATE weekly_liquidations
            SET status = 'submitted',
                submitted_at = CURRENT_TIMESTAMP,
                submitted_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $submitStmt->execute([$user['id'], $weekly['id']]);

        $lockTripStmt = $pdo->prepare("
            UPDATE trip_legs tl
            JOIN weekly_liquidation_items wli
                ON wli.trip_leg_id = tl.id
            SET tl.record_status = 'locked',
                tl.updated_by = ?
            WHERE wli.weekly_liquidation_id = ?
              AND wli.item_type = 'trip_leg'
              AND tl.record_status <> 'voided'
        ");
        $lockTripStmt->execute([$user['id'], $weekly['id']]);

        $lockFuelStmt = $pdo->prepare("
            UPDATE fuel_purchases fp
            JOIN weekly_liquidation_items wli
                ON wli.fuel_purchase_id = fp.id
            SET fp.record_status = 'locked',
                fp.updated_by = ?
            WHERE wli.weekly_liquidation_id = ?
              AND wli.item_type = 'fuel_purchase'
              AND fp.record_status <> 'voided'
        ");
        $lockFuelStmt->execute([$user['id'], $weekly['id']]);

        $pdo->commit();

        fleet_set_flash('success', 'Weekly liquidation submitted successfully for review.');
        header('Location: weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
    }
}

$weekly = null;
$tripLegs = [];
$fuelPurchases = [];
if ($selectedVehicle) {
    $weekly = fleet_ensure_weekly_liquidation(
        $pdo,
        $user['id'],
        (int) $selectedVehicle['id'],
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
        $weekStart
    );
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
    $tripLegs = fleet_fetch_weekly_trip_legs($pdo, (int) $weekly['id']);
    $fuelPurchases = fleet_fetch_weekly_fuel_purchases($pdo, (int) $weekly['id']);
}

fleet_render_shell_start(
    'Weekly Liquidation',
    'weekly_liquidation',
    $user,
    'Review everything recorded from Sunday to Saturday for one vehicle, check for missing receipts or odometer problems, then submit the full weekly package to the facility admin.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-clipboard-check"></i></div>';
    echo '<h2>No vehicle available</h2>';
    echo '<p>This review page becomes active once a vehicle is assigned and you start recording movement legs or fuel purchases.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

$previousWeek = (new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d');
$isEditable = fleet_week_is_editable($weekly);
$hasItems = ((int) $weekly['total_trip_legs'] + (int) $weekly['total_fuel_purchases']) > 0;

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Select Vehicle</h2><p>Each weekly liquidation is grouped by driver, vehicle, and Sunday to Saturday week.</p></div>';
echo '<a class="inline-link-button" href="my_vehicle.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-car-side"></i>Back to My Vehicle</a>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i><span>' . fleet_h($vehicle['vehicle_name']) . ' · ' . fleet_h($vehicle['number_plate']) . '</span></a>';
}
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="weekly-navigator">';
echo '<div class="week-badge"><i class="fas fa-calendar-week"></i><span>' . fleet_h(fleet_format_week_label($weekStart)) . '</span></div>';
echo '<div class="button-row">';
echo '<a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($previousWeek) . '"><i class="fas fa-arrow-left"></i>Previous Week</a>';
echo '<a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode(fleet_week_start_from_date(date('Y-m-d'))) . '"><i class="fas fa-bullseye"></i>This Week</a>';
echo '<a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($nextWeek) . '">Next Week<i class="fas fa-arrow-right"></i></a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>' . fleet_h($selectedVehicle['vehicle_name']) . ' · ' . fleet_h($selectedVehicle['number_plate']) . '</h2><p>Driver: ' . fleet_h($user['name']) . ' · Facility: ' . fleet_h($selectedVehicle['facility_name'] ?? 'Not set') . '</p></div>';
echo '<span class="status-pill ' . fleet_h($weekly['status']) . '">' . fleet_h($weekly['status']) . '</span>';
echo '</div>';
echo '<div class="metric-grid">';
echo '<div class="metric-card"><div class="metric-label">Movement Legs</div><div class="metric-value">' . number_format((int) $weekly['total_trip_legs']) . '</div><div class="metric-caption">Recorded leg by leg</div></div>';
echo '<div class="metric-card"><div class="metric-label">Distance</div><div class="metric-value">' . number_format((float) $weekly['total_km'], 1) . '</div><div class="metric-caption">Kilometres for the week</div></div>';
echo '<div class="metric-card"><div class="metric-label">Fuel Purchases</div><div class="metric-value">' . number_format((int) $weekly['total_fuel_purchases']) . '</div><div class="metric-caption">' . number_format((float) $weekly['total_fuel_litres'], 2) . ' litres purchased</div></div>';
echo '<div class="metric-card"><div class="metric-label">Fuel Amount</div><div class="metric-value">K ' . number_format((float) $weekly['total_fuel_amount'], 2) . '</div><div class="metric-caption">' . ((int) $weekly['missing_receipts'] > 0 ? number_format((int) $weekly['missing_receipts']) . ' receipt(s) missing' : 'Receipts attached') . '</div></div>';
echo '</div>';
if (!empty($weekly['return_reason'])) {
    echo '<div class="alert alert-info" style="margin-top:18px;"><i class="fas fa-reply"></i><span>Returned by reviewer: ' . fleet_h($weekly['return_reason']) . '</span></div>';
}
if (!empty($weekly['review_notes'])) {
    echo '<p class="helper-text">Reviewer notes: ' . fleet_h($weekly['review_notes']) . '</p>';
}
if (!empty($weekly['issue_notes'])) {
    echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($weekly['issue_notes']) . '</p>';
}
echo '<div class="button-row" style="margin-top:18px;">';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-route"></i>Add Movement Leg</a>';
echo '<a class="button-secondary" href="record_fuel_purchase.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-receipt"></i>Add Fuel Purchase</a>';
echo '<form method="post" style="display:inline-flex;">';
echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
echo '<input type="hidden" name="week_start" value="' . fleet_h($weekStart) . '">';
echo '<button class="button" type="submit" name="submit_weekly" value="1" ' . ((!$isEditable || !$hasItems || (int) $weekly['has_issues'] === 1) ? 'disabled' : '') . '><i class="fas fa-paper-plane"></i>Submit Weekly Liquidation</button>';
echo '</form>';
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Movement Legs Included</h2><p>Every leg below is part of this weekly liquidation package.</p></div></div>';
if (!$tripLegs) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h3>No movement legs yet</h3>';
    echo '<p>Add leg-by-leg movement entries first. The weekly draft updates automatically as you save them.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($tripLegs as $trip) {
        $passengerName = trim((string) ($trip['passenger_name'] ?? ''));
        $passengerDisplay = $passengerName !== '' ? $passengerName : trim((string) ($trip['confirmed_by_name'] ?? ''));
        $confirmerDisplay = $passengerName !== '' ? trim((string) ($trip['confirmed_by_name'] ?? '')) : '';
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($trip['from_location']) . ' to ' . fleet_h($trip['to_location']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($trip['movement_date'])) . ' · ' . fleet_h(substr((string) $trip['time_out'], 0, 5)) . ' to ' . fleet_h(substr((string) $trip['time_in'], 0, 5)) . ' · Confirmed by ' . fleet_h($trip['confirmed_by_name']) . '</p></div>';
        echo '<span class="status-pill ' . fleet_h($trip['record_status']) . '">' . fleet_h($trip['record_status']) . '</span>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Purpose</span><span class="detail-pair-value">' . fleet_h($trip['purpose']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Start km</span><span class="detail-pair-value">' . number_format((float) $trip['odometer_start_km'], 1) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">End km</span><span class="detail-pair-value">' . number_format((float) $trip['odometer_end_km'], 1) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Total km</span><span class="detail-pair-value">' . number_format((float) $trip['total_km'], 1) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Passenger / Requester</span><span class="detail-pair-value">' . fleet_h($passengerDisplay ?: 'Not captured') . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Confirmed By</span><span class="detail-pair-value">' . fleet_h($confirmerDisplay ?: 'Not captured') . '</span></div>';
        echo '</div>';
        if (!empty($trip['issue_notes'])) {
            echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($trip['issue_notes']) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
}
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Fuel Purchases Included</h2><p>Each purchase below is reviewed together with the movement legs for the week.</p></div></div>';
if (!$fuelPurchases) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-receipt"></i></div>';
    echo '<h3>No fuel purchases yet</h3>';
    echo '<p>If fuel was bought during the week, add the refill record and attach the receipt before submitting.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($fuelPurchases as $purchase) {
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($purchase['station_name']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($purchase['purchase_date'])) . ' · Receipt ' . fleet_h($purchase['receipt_number']) . ' · ' . fleet_h($purchase['account_name'] ?: 'Card not linked') . '</p></div>';
        echo '<span class="status-pill ' . fleet_h($purchase['record_status']) . '">' . fleet_h($purchase['record_status']) . '</span>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Litres</span><span class="detail-pair-value">' . number_format((float) $purchase['litres'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Amount</span><span class="detail-pair-value">K ' . number_format((float) $purchase['amount'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Unit Price</span><span class="detail-pair-value">K ' . number_format((float) $purchase['unit_price'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Refill Odometer</span><span class="detail-pair-value">' . number_format((float) $purchase['odometer_at_refill_km'], 1) . '</span></div>';
        echo '</div>';
        echo '<div class="button-row" style="margin-top:12px;">';
        if (!empty($purchase['receipt_attachment_id'])) {
            echo '<a class="button-ghost" href="view_attachment.php?id=' . urlencode((string) $purchase['receipt_attachment_id']) . '" target="_blank"><i class="fas fa-paperclip"></i>View Receipt</a>';
        } else {
            echo '<span class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> Receipt missing</span>';
        }
        echo '</div>';
        if (!empty($purchase['issue_notes'])) {
            echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($purchase['issue_notes']) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
}
echo '</section>';

fleet_render_shell_end();
