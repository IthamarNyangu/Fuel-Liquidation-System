<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_reviewer_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Weekly Review', 'weekly_review');
}

$scopeSql = '';
$scopeParams = [];
if (!$user['is_super_admin']) {
    $scopeSql = ' AND wl.facility_id = :facility_id';
    $scopeParams[':facility_id'] = $user['facility_id'];
}

$pageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weekly_liquidation_id'], $_POST['review_action'])) {
    $weeklyId = (int) $_POST['weekly_liquidation_id'];
    $reviewAction = $_POST['review_action'];
    $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));

    try {
        $detailSql = "
            SELECT wl.*, u.name AS driver_name, v.vehicle_name, v.number_plate, f.facility_name
            FROM weekly_liquidations wl
            JOIN users u ON u.id = wl.driver_id
            JOIN vehicles v ON v.id = wl.vehicle_id
            LEFT JOIN facilities f ON f.id = wl.facility_id
            WHERE wl.id = :weekly_id" . $scopeSql . "
            LIMIT 1
        ";
        $detailStmt = $pdo->prepare($detailSql);
        $detailParams = array_merge([':weekly_id' => $weeklyId], $scopeParams);
        $detailStmt->execute($detailParams);
        $weekly = $detailStmt->fetch();

        if (!$weekly) {
            throw new RuntimeException('The selected weekly liquidation could not be found in your review scope.');
        }

        $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);

        if (!in_array($weekly['status'], ['submitted', 'under_review'], true)) {
            throw new RuntimeException('Only submitted weekly liquidations can be approved or returned.');
        }

        if ($reviewAction === 'return' && $reviewNotes === '') {
            throw new RuntimeException('Review notes are required when returning a weekly liquidation.');
        }

        $pdo->beginTransaction();

        if ($reviewAction === 'approve') {
            $actionStmt = $pdo->prepare("
                UPDATE weekly_liquidations
                SET status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    review_notes = ?,
                    return_reason = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $actionStmt->execute([
                $user['id'],
                $reviewNotes ?: null,
                $weeklyId,
            ]);
        } elseif ($reviewAction === 'return') {
            $actionStmt = $pdo->prepare("
                UPDATE weekly_liquidations
                SET status = 'returned',
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    review_notes = ?,
                    return_reason = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $actionStmt->execute([
                $user['id'],
                $reviewNotes,
                $reviewNotes,
                $weeklyId,
            ]);

            $unlockTripStmt = $pdo->prepare("
                UPDATE trip_legs tl
                JOIN weekly_liquidation_items wli
                    ON wli.trip_leg_id = tl.id
                SET tl.record_status = 'recorded',
                    tl.updated_by = ?
                WHERE wli.weekly_liquidation_id = ?
                  AND wli.item_type = 'trip_leg'
                  AND tl.record_status = 'locked'
            ");
            $unlockTripStmt->execute([$user['id'], $weeklyId]);

            $unlockFuelStmt = $pdo->prepare("
                UPDATE fuel_purchases fp
                JOIN weekly_liquidation_items wli
                    ON wli.fuel_purchase_id = fp.id
                SET fp.record_status = 'recorded',
                    fp.updated_by = ?
                WHERE wli.weekly_liquidation_id = ?
                  AND wli.item_type = 'fuel_purchase'
                  AND fp.record_status = 'locked'
            ");
            $unlockFuelStmt->execute([$user['id'], $weeklyId]);
        } else {
            throw new RuntimeException('Unknown review action.');
        }

        $pdo->commit();

        fleet_set_flash(
            'success',
            $reviewAction === 'approve'
                ? 'Weekly liquidation approved.'
                : 'Weekly liquidation returned to the driver for correction.'
        );
        header('Location: facility_liquidation_review.php?weekly_id=' . urlencode((string) $weeklyId));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
    }
}

$queueSql = "
    SELECT
        wl.*,
        u.name AS driver_name,
        v.vehicle_name,
        v.number_plate,
        f.facility_name
    FROM weekly_liquidations wl
    JOIN users u ON u.id = wl.driver_id
    JOIN vehicles v ON v.id = wl.vehicle_id
    LEFT JOIN facilities f ON f.id = wl.facility_id
    WHERE wl.status IN ('submitted', 'under_review', 'returned', 'approved')" . $scopeSql . "
    ORDER BY
        FIELD(wl.status, 'submitted', 'under_review', 'returned', 'approved'),
        wl.week_start_date DESC,
        wl.id DESC
";
$queueStmt = $pdo->prepare($queueSql);
$queueStmt->execute($scopeParams);
$queue = $queueStmt->fetchAll();

$selectedWeeklyId = isset($_GET['weekly_id']) ? (int) $_GET['weekly_id'] : 0;
if ($selectedWeeklyId === 0 && $queue) {
    $selectedWeeklyId = (int) $queue[0]['id'];
}

$selectedWeekly = null;
$selectedTripLegs = [];
$selectedFuelPurchases = [];
if ($selectedWeeklyId > 0) {
    $detailSql = "
        SELECT
            wl.*,
            u.name AS driver_name,
            v.vehicle_name,
            v.number_plate,
            f.facility_name
        FROM weekly_liquidations wl
        JOIN users u ON u.id = wl.driver_id
        JOIN vehicles v ON v.id = wl.vehicle_id
        LEFT JOIN facilities f ON f.id = wl.facility_id
        WHERE wl.id = :weekly_id" . $scopeSql . "
        LIMIT 1
    ";
    $detailStmt = $pdo->prepare($detailSql);
    $detailParams = array_merge([':weekly_id' => $selectedWeeklyId], $scopeParams);
    $detailStmt->execute($detailParams);
    $selectedWeekly = $detailStmt->fetch() ?: null;

    if ($selectedWeekly) {
        $selectedWeekly = fleet_recalculate_weekly($pdo, (int) $selectedWeekly['id']);
        $selectedTripLegs = fleet_fetch_weekly_trip_legs($pdo, (int) $selectedWeekly['id']);
        $selectedFuelPurchases = fleet_fetch_weekly_fuel_purchases($pdo, (int) $selectedWeekly['id']);
    }
}

fleet_render_shell_start(
    'Weekly Review',
    'weekly_review',
    $user,
    'Facility review is where the driver handoff becomes official. Review one weekly package at a time, check receipts and odometer continuity, then approve or return it with notes.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Review Queue</h2><p>Submitted weekly liquidations are sorted first so the facility review workload stays visible.</p></div></div>';
echo '<div class="review-layout">';
echo '<div class="queue-list">';
if (!$queue) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-user-check"></i></div>';
    echo '<h3>No weekly liquidations waiting</h3>';
    echo '<p>When drivers submit their weekly packages, they will appear here for facility review.</p>';
    echo '</div>';
} else {
    foreach ($queue as $weeklyItem) {
        $isActive = (int) $weeklyItem['id'] === (int) $selectedWeeklyId;
        echo '<a class="queue-item' . ($isActive ? ' active' : '') . '" href="facility_liquidation_review.php?weekly_id=' . urlencode((string) $weeklyItem['id']) . '">';
        echo '<h3>' . fleet_h($weeklyItem['driver_name']) . ' · ' . fleet_h($weeklyItem['number_plate']) . '</h3>';
        echo '<p>' . fleet_h($weeklyItem['vehicle_name']) . ' · ' . fleet_h($weeklyItem['facility_name'] ?? 'Facility not set') . '</p>';
        echo '<p>' . fleet_h(fleet_format_week_label($weeklyItem['week_start_date'])) . '</p>';
        echo '<div class="queue-meta">';
        echo '<span class="status-pill ' . fleet_h($weeklyItem['status']) . '">' . fleet_h($weeklyItem['status']) . '</span>';
        echo '<span class="helper-text">' . number_format((int) $weeklyItem['total_trip_legs']) . ' legs · K ' . number_format((float) $weeklyItem['total_fuel_amount'], 2) . '</span>';
        echo '</div>';
        echo '</a>';
    }
}
echo '</div>';

echo '<div class="stack">';
if (!$selectedWeekly) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-clipboard-check"></i></div>';
    echo '<h3>Select a weekly liquidation</h3>';
    echo '<p>Choose an item from the queue to inspect the movement legs, fuel purchases, and receipts in detail.</p>';
    echo '</div>';
} else {
    $canReviewNow = in_array($selectedWeekly['status'], ['submitted', 'under_review'], true);
    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header-split">';
    echo '<div><h2>' . fleet_h($selectedWeekly['driver_name']) . ' · ' . fleet_h($selectedWeekly['number_plate']) . '</h2><p>' . fleet_h($selectedWeekly['vehicle_name']) . ' · ' . fleet_h($selectedWeekly['facility_name'] ?? 'Facility not set') . ' · ' . fleet_h(fleet_format_week_label($selectedWeekly['week_start_date'])) . '</p></div>';
    echo '<span class="status-pill ' . fleet_h($selectedWeekly['status']) . '">' . fleet_h($selectedWeekly['status']) . '</span>';
    echo '</div>';
    echo '<div class="metric-grid">';
    echo '<div class="metric-card"><div class="metric-label">Movement Legs</div><div class="metric-value">' . number_format((int) $selectedWeekly['total_trip_legs']) . '</div><div class="metric-caption">' . number_format((float) $selectedWeekly['total_km'], 1) . ' km</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Fuel Purchases</div><div class="metric-value">' . number_format((int) $selectedWeekly['total_fuel_purchases']) . '</div><div class="metric-caption">' . number_format((float) $selectedWeekly['total_fuel_litres'], 2) . ' litres</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Fuel Amount</div><div class="metric-value">K ' . number_format((float) $selectedWeekly['total_fuel_amount'], 2) . '</div><div class="metric-caption">' . ((int) $selectedWeekly['missing_receipts'] > 0 ? number_format((int) $selectedWeekly['missing_receipts']) . ' missing receipt(s)' : 'Receipts present') . '</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Issues</div><div class="metric-value">' . (($selectedWeekly['has_issues'] ?? 0) ? 'Yes' : 'No') . '</div><div class="metric-caption">' . fleet_h($selectedWeekly['issue_notes'] ?: 'No validation issues recorded.') . '</div></div>';
    echo '</div>';
    if (!empty($selectedWeekly['return_reason'])) {
        echo '<div class="alert alert-info" style="margin-top:18px;"><i class="fas fa-reply"></i><span>Return reason: ' . fleet_h($selectedWeekly['return_reason']) . '</span></div>';
    }
    if (!empty($selectedWeekly['review_notes'])) {
        echo '<p class="helper-text">Review notes: ' . fleet_h($selectedWeekly['review_notes']) . '</p>';
    }
    echo '<form method="post" class="form-grid" style="margin-top:16px;">';
    echo '<input type="hidden" name="weekly_liquidation_id" value="' . fleet_h($selectedWeekly['id']) . '">';
    echo '<div class="form-group span-12"><label for="review_notes">Review Notes</label><textarea id="review_notes" name="review_notes" placeholder="Add notes for the driver or for audit history. Notes are required if you return the weekly package."></textarea></div>';
    echo '<div class="form-group span-12"><div class="button-row">';
    echo '<button class="button" type="submit" name="review_action" value="approve" ' . ($canReviewNow ? '' : 'disabled') . '><i class="fas fa-check"></i>Approve Weekly Liquidation</button>';
    echo '<button class="button-secondary" type="submit" name="review_action" value="return" ' . ($canReviewNow ? '' : 'disabled') . '><i class="fas fa-reply"></i>Return to Driver</button>';
    echo '</div></div>';
    echo '</form>';
    echo '</section>';

    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header"><div><h2>Movement Legs</h2><p>Review the trip legs for completeness, odometer continuity, and purpose clarity.</p></div></div>';
    if (!$selectedTripLegs) {
        echo '<p class="helper-text">No movement legs are attached to this weekly package.</p>';
    } else {
        echo '<div class="data-list">';
        foreach ($selectedTripLegs as $trip) {
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

    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header"><div><h2>Fuel Purchases</h2><p>Check amount, litres, station, receipt number, and the attached receipt for each purchase.</p></div></div>';
    if (!$selectedFuelPurchases) {
        echo '<p class="helper-text">No fuel purchases are attached to this weekly package.</p>';
    } else {
        echo '<div class="data-list">';
        foreach ($selectedFuelPurchases as $purchase) {
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
}
echo '</div>';
echo '</div>';
echo '</section>';

fleet_render_shell_end();
