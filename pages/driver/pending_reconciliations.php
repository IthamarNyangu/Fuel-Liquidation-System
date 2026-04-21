<?php
$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Pending Reconciliations', 'pending_reconciliations');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_REQUEST['vehicle_id']) ? (int) $_REQUEST['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$weekStart = fleet_week_start_from_date($_REQUEST['week_start'] ?? date('Y-m-d'));
$pageError = '';

$weekly = null;
$availableTripLegs = [];
$availableFuelPurchases = [];
$selectedTripLegIds = [];
$selectedFuelPurchaseIds = [];
$cardAccount = null;
$cardContext = null;

if ($selectedVehicle) {
    $weekly = fleet_ensure_weekly_liquidation(
        $pdo,
        $user['id'],
        (int) $selectedVehicle['id'],
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
        $weekStart
    );
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);

    $availableTripLegs = fleet_fetch_pending_trip_legs($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
    $availableFuelPurchases = fleet_fetch_pending_fuel_purchases($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);

    $selection = fleet_fetch_weekly_item_selection($pdo, (int) $weekly['id']);
    $selectedTripLegIds = array_map('intval', $selection['trip_leg_ids'] ?? []);
    $selectedFuelPurchaseIds = array_map('intval', $selection['fuel_purchase_ids'] ?? []);

    $cardAccountStmt = $pdo->prepare("
        SELECT *
        FROM card_accounts
        WHERE vehicle_id = ?
        ORDER BY
            CASE status
                WHEN 'active' THEN 0
                WHEN 'inactive' THEN 1
                ELSE 2
            END,
            id
        LIMIT 1
    ");
    $cardAccountStmt->execute([(int) $selectedVehicle['id']]);
    $cardAccount = $cardAccountStmt->fetch() ?: null;
    if (!$cardAccount && !empty($selectedVehicle['float_account_name'])) {
        $cardAccount = fleet_ensure_vehicle_card_account($pdo, $selectedVehicle, $user['id']);
    }
    $cardContext = fleet_vehicle_card_context($selectedVehicle, $cardAccount);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedVehicle && isset($_POST['vehicle_id'], $_POST['week_start'])) {
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
            throw new RuntimeException('This reconciliation package has already been submitted or approved.');
        }

        $availableTripLegs = fleet_fetch_pending_trip_legs($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
        $availableFuelPurchases = fleet_fetch_pending_fuel_purchases($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);

        $allowedTripIds = array_map(static fn(array $trip): int => (int) $trip['id'], $availableTripLegs);
        $allowedFuelIds = array_map(static fn(array $purchase): int => (int) $purchase['id'], $availableFuelPurchases);

        if (isset($_POST['include_all_pending'])) {
            $selectedTripLegIds = $allowedTripIds;
            $selectedFuelPurchaseIds = $allowedFuelIds;
        } else {
            $selectedTripLegIds = array_values(array_intersect(
                $allowedTripIds,
                array_map('intval', (array) ($_POST['trip_leg_ids'] ?? []))
            ));
            $selectedFuelPurchaseIds = array_values(array_intersect(
                $allowedFuelIds,
                array_map('intval', (array) ($_POST['fuel_purchase_ids'] ?? []))
            ));
        }

        $pdo->beginTransaction();
        fleet_replace_weekly_item_selection($pdo, (int) $weekly['id'], $selectedTripLegIds, $selectedFuelPurchaseIds);
        $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);

        if (isset($_POST['submit_reconciliation'])) {
            $selectedFuelCount = count($selectedFuelPurchaseIds);
            $selectedTripCount = count($selectedTripLegIds);
            if (($selectedFuelCount + $selectedTripCount) === 0) {
                throw new RuntimeException('Select at least one pending item before submitting a reconciliation package.');
            }

            if ($selectedFuelCount === 0) {
                throw new RuntimeException('A reconciliation package must include at least one fuel purchase. Movement-only activity can stay pending until there is a related refuel.');
            }

            if ((int) $weekly['has_issues'] === 1) {
                throw new RuntimeException('This reconciliation package still has issues: ' . ($weekly['issue_notes'] ?: 'resolve the flagged items before submitting.'));
            }

            if (fleet_review_workflow_ready($pdo)) {
                $submitStmt = $pdo->prepare("
                    UPDATE weekly_liquidations
                    SET status = 'submitted',
                        submission_round = COALESCE(submission_round, 0) + 1,
                        submitted_at = CURRENT_TIMESTAMP,
                        submitted_by = ?,
                        reviewed_by = NULL,
                        reviewed_at = NULL,
                        review_notes = NULL,
                        return_reason = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $submitStmt->execute([$user['id'], $weekly['id']]);
            } else {
                $submitStmt = $pdo->prepare("
                    UPDATE weekly_liquidations
                    SET status = 'submitted',
                        submitted_at = CURRENT_TIMESTAMP,
                        submitted_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $submitStmt->execute([$user['id'], $weekly['id']]);
            }

            if ($selectedTripLegIds) {
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
            }

            if ($selectedFuelPurchaseIds) {
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
            }

            $pdo->commit();
            fleet_set_flash('success', 'Pending reconciliation submitted successfully for review.');
        } else {
            $pdo->commit();
            if (isset($_POST['include_all_pending'])) {
                fleet_set_flash('success', 'All pending items in this reconciliation window have been included.');
            } else {
                fleet_set_flash('success', 'Reconciliation selection saved.');
            }
        }

        header('Location: pending_reconciliations.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
    }
}

if ($selectedVehicle) {
    $weekly = fleet_ensure_weekly_liquidation(
        $pdo,
        $user['id'],
        (int) $selectedVehicle['id'],
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
        $weekStart
    );
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
    $availableTripLegs = fleet_fetch_pending_trip_legs($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
    $availableFuelPurchases = fleet_fetch_pending_fuel_purchases($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
    $selection = fleet_fetch_weekly_item_selection($pdo, (int) $weekly['id']);
    $selectedTripLegIds = array_map('intval', $selection['trip_leg_ids'] ?? []);
    $selectedFuelPurchaseIds = array_map('intval', $selection['fuel_purchase_ids'] ?? []);
}

$selectedTripLookup = array_fill_keys($selectedTripLegIds, true);
$selectedFuelLookup = array_fill_keys($selectedFuelPurchaseIds, true);
$selectedTripLegs = array_values(array_filter($availableTripLegs, static fn(array $trip): bool => isset($selectedTripLookup[(int) $trip['id']])));
$selectedFuelPurchases = array_values(array_filter($availableFuelPurchases, static fn(array $purchase): bool => isset($selectedFuelLookup[(int) $purchase['id']])));
$selectedTripCount = count($selectedTripLegs);
$selectedFuelCount = count($selectedFuelPurchases);
$selectedTotalKm = array_sum(array_map(static fn(array $trip): float => (float) ($trip['total_km'] ?? 0), $selectedTripLegs));
$selectedTotalLitres = array_sum(array_map(static fn(array $purchase): float => (float) ($purchase['litres'] ?? 0), $selectedFuelPurchases));
$selectedTotalAmount = array_sum(array_map(static fn(array $purchase): float => (float) ($purchase['amount'] ?? 0), $selectedFuelPurchases));

fleet_render_shell_start(
    'Pending Reconciliations',
    'pending_reconciliations',
    $user,
    'Choose the pending movement legs and fuel purchases to include, then submit a reconciliation package when the receipts and odometer trail are ready.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-clipboard-check"></i></div>';
    echo '<h2>No vehicle available</h2>';
    echo '<p>This page becomes active once a vehicle is assigned and you start recording movement legs or fuel purchases.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

$previousWeek = (new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d');
$isEditable = fleet_week_is_editable($weekly);

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Select Vehicle</h2><p>Pending items are shown per vehicle. The current compatibility layer still groups them inside a reconciliation window while the system moves off weekly liquidation.</p></div>';
echo '<a class="inline-link-button" href="my_vehicle.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-car-side"></i>Back to My Vehicle</a>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="pending_reconciliations.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i><span>' . fleet_h($vehicle['vehicle_name']) . ' &middot; ' . fleet_h($vehicle['number_plate']) . '</span></a>';
}
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="weekly-navigator">';
echo '<div class="week-badge"><i class="fas fa-calendar-week"></i><span>' . fleet_h(fleet_format_week_label($weekStart)) . '</span></div>';
echo '<div class="button-row">';
echo '<a class="button-secondary" href="pending_reconciliations.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($previousWeek) . '"><i class="fas fa-arrow-left"></i>Previous Window</a>';
echo '<a class="button-secondary" href="pending_reconciliations.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode(fleet_week_start_from_date(date('Y-m-d'))) . '">Current Window</a>';
echo '<a class="button-secondary" href="pending_reconciliations.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($nextWeek) . '">Next Window<i class="fas fa-arrow-right"></i></a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<form method="post" class="stack">';
echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
echo '<input type="hidden" name="week_start" value="' . fleet_h($weekStart) . '">';

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>' . fleet_h($selectedVehicle['vehicle_name']) . ' &middot; ' . fleet_h($selectedVehicle['number_plate']) . '</h2><p>Driver: ' . fleet_h($user['name']) . ' &middot; Province: ' . fleet_h($selectedVehicle['facility_name'] ?? 'Not set') . '</p></div>';
echo '<span class="status-pill ' . fleet_h($weekly['status']) . '">' . fleet_h(ucwords(str_replace('_', ' ', (string) $weekly['status']))) . '</span>';
echo '</div>';
echo '<div class="metric-grid">';
echo '<div class="metric-card"><div class="metric-label">Selected Movements</div><div class="metric-value">' . number_format($selectedTripCount) . '</div><div class="metric-caption">' . fleet_format_km($selectedTotalKm) . '</div></div>';
echo '<div class="metric-card"><div class="metric-label">Selected Fuel Purchases</div><div class="metric-value">' . number_format($selectedFuelCount) . '</div><div class="metric-caption">' . number_format($selectedTotalLitres, 2) . ' litres</div></div>';
echo '<div class="metric-card"><div class="metric-label">Selected Spend</div><div class="metric-value">' . fleet_currency($selectedTotalAmount) . '</div><div class="metric-caption">For this package</div></div>';
echo '<div class="metric-card"><div class="metric-label">Card Balance</div><div class="metric-value">' . fleet_currency($cardContext['balance'] ?? 0) . '</div><div class="metric-caption">' . fleet_h(($cardContext['threshold_label'] ?? 'Healthy') . ' · Suggested top-up ' . fleet_currency($cardContext['suggested_replenishment'] ?? 0)) . '</div></div>';
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
echo '<button class="button-secondary" type="submit" name="save_selection" value="1" ' . ($isEditable ? '' : 'disabled') . '>Save Selection</button>';
echo '<button class="button-secondary" type="submit" name="include_all_pending" value="1" ' . ($isEditable ? '' : 'disabled') . '>Include All Pending</button>';
echo '<button class="button" type="submit" name="submit_reconciliation" value="1" ' . ($isEditable ? '' : 'disabled') . '><i class="fas fa-paper-plane"></i>Submit Reconciliation</button>';
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Pending Movement Legs</h2><p>Tick the legs that belong in this reconciliation package. In-progress or inconsistent legs can be left out until corrected.</p></div></div>';
if (!$availableTripLegs) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h3>No movement legs available</h3>';
    echo '<p>Movement legs recorded in this window will appear here and can be added to the reconciliation package when ready.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($availableTripLegs as $trip) {
        $tripId = (int) $trip['id'];
        $isChecked = isset($selectedTripLookup[$tripId]);
        $passengerName = trim((string) ($trip['passenger_name'] ?? ''));
        $passengerDisplay = $passengerName !== '' ? $passengerName : trim((string) ($trip['confirmed_by_name'] ?? ''));
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($trip['from_location']) . ' to ' . fleet_h($trip['to_location']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($trip['movement_date'])) . ' &middot; ' . fleet_h(substr((string) $trip['time_out'], 0, 5)) . (!empty($trip['time_in']) ? ' to ' . fleet_h(substr((string) $trip['time_in'], 0, 5)) : ' onward') . ' &middot; ' . fleet_h($trip['purpose']) . '</p></div>';
        echo '<div style="display:flex;align-items:center;gap:12px;">';
        echo '<label class="helper-text" style="display:inline-flex;align-items:center;gap:8px;"><input type="checkbox" name="trip_leg_ids[]" value="' . fleet_h($tripId) . '"' . ($isChecked ? ' checked' : '') . ($isEditable ? '' : ' disabled') . '>Include</label>';
        echo '<span class="status-pill ' . fleet_h($trip['record_status']) . '">' . fleet_h(ucwords(str_replace('_', ' ', (string) $trip['record_status']))) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Purpose</span><span class="detail-pair-value">' . fleet_h($trip['purpose']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Start km</span><span class="detail-pair-value">' . fleet_format_km($trip['odometer_start_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">End km</span><span class="detail-pair-value">' . fleet_format_km($trip['odometer_end_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Total km</span><span class="detail-pair-value">' . fleet_format_km($trip['total_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Passenger / Requester</span><span class="detail-pair-value">' . fleet_h($passengerDisplay ?: 'Not captured') . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Confirmed By</span><span class="detail-pair-value">' . fleet_h($trip['confirmed_by_name'] ?: 'Not captured') . '</span></div>';
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
echo '<div class="panel-header"><div><h2>Pending Fuel Purchases</h2><p>Fuel purchases are the trigger for reconciliation. Select the refills whose receipt and pump photo are ready to submit in this package.</p></div></div>';
if (!$availableFuelPurchases) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-receipt"></i></div>';
    echo '<h3>No fuel purchases available</h3>';
    echo '<p>Once a refuel is recorded, it will appear here as pending until it is submitted for review.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($availableFuelPurchases as $purchase) {
        $purchaseId = (int) $purchase['id'];
        $isChecked = isset($selectedFuelLookup[$purchaseId]);
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($purchase['station_name']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($purchase['purchase_date'])) . ' &middot; Receipt ' . fleet_h($purchase['receipt_number']) . ' &middot; ' . fleet_h($purchase['account_name'] ?: 'Card not linked') . '</p></div>';
        echo '<div style="display:flex;align-items:center;gap:12px;">';
        echo '<label class="helper-text" style="display:inline-flex;align-items:center;gap:8px;"><input type="checkbox" name="fuel_purchase_ids[]" value="' . fleet_h($purchaseId) . '"' . ($isChecked ? ' checked' : '') . ($isEditable ? '' : ' disabled') . '>Include</label>';
        echo '<span class="status-pill ' . fleet_h($purchase['record_status']) . '">' . fleet_h(ucwords(str_replace('_', ' ', (string) $purchase['record_status']))) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Litres</span><span class="detail-pair-value">' . number_format((float) $purchase['litres'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Amount</span><span class="detail-pair-value">' . fleet_currency($purchase['amount']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Unit Price</span><span class="detail-pair-value">' . fleet_currency($purchase['unit_price']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Refill Odometer</span><span class="detail-pair-value">' . fleet_format_km($purchase['odometer_at_refill_km']) . '</span></div>';
        echo '</div>';
        echo '<div class="button-row" style="margin-top:12px;">';
        if (!empty($purchase['receipt_attachment_id'])) {
            echo '<a class="button-ghost" href="view_attachment.php?id=' . urlencode((string) $purchase['receipt_attachment_id']) . '" target="_blank"><i class="fas fa-paperclip"></i>View Receipt</a>';
        } else {
            echo '<span class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> Receipt missing</span>';
        }
        if (!empty($purchase['pump_photo_attachment_id'])) {
            echo '<a class="button-ghost" href="view_attachment.php?id=' . urlencode((string) $purchase['pump_photo_attachment_id']) . '" target="_blank"><i class="fas fa-camera"></i>View Pump Photo</a>';
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
echo '</form>';

fleet_render_shell_end();
