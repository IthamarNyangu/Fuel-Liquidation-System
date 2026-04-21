<?php
$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_reviewer_access($user);

$selfPath = basename((string) ($_SERVER['PHP_SELF'] ?? 'province_liquidation.php'));
$isFinanceReviewer = fleet_is_finance($user);
$hasGlobalScope = fleet_has_global_review_scope($user);
$selectedProvinceId = $hasGlobalScope ? max(0, (int) ($_GET['province_id'] ?? 0)) : (int) ($user['facility_id'] ?? 0);
$provinceFilterQuery = $selectedProvinceId > 0 ? '&province_id=' . urlencode((string) $selectedProvinceId) : '';
$pageTitle = match ($user['role']) {
    'finance' => 'Reconciliation History',
    'fleet_manager' => 'Fleet Review',
    default => 'Province Review',
};
$queueHeading = match ($user['role']) {
    'finance' => 'Finance Reconciliation History',
    'fleet_manager' => 'Fleet Queue',
    default => 'Province Reconciliation Queue',
};

if (!fleet_review_workflow_ready($pdo)) {
    fleet_render_schema_required($user, $pageTitle, 'province_liquidation');
}

$scopeSql = '';
$scopeParams = [];
if (!$hasGlobalScope) {
    $scopeSql = ' AND wl.facility_id = :facility_id';
    $scopeParams[':facility_id'] = (int) ($user['facility_id'] ?? 0);
} elseif ($selectedProvinceId > 0) {
    $scopeSql = ' AND wl.facility_id = :facility_id';
    $scopeParams[':facility_id'] = $selectedProvinceId;
}

$provinceOptions = [];
if ($hasGlobalScope) {
    $provinceStmt = $pdo->query("
        SELECT id, facility_name
        FROM facilities
        WHERE is_active = 1
        ORDER BY facility_name
    ");
    $provinceOptions = $provinceStmt->fetchAll();
}

$pageError = '';

$loadWeeklyInScope = static function (int $weeklyId) use ($pdo, $scopeSql, $scopeParams): ?array {
    $detailSql = "
        SELECT
            wl.*,
            u.name AS driver_name,
            u.email AS driver_email,
            v.vehicle_name,
            v.number_plate,
            f.facility_name
        FROM weekly_liquidations wl
        JOIN users u
            ON u.id = wl.driver_id
        JOIN vehicles v
            ON v.id = wl.vehicle_id
        LEFT JOIN facilities f
            ON f.id = wl.facility_id
        WHERE wl.id = :weekly_id" . $scopeSql . "
        LIMIT 1
    ";
    $detailStmt = $pdo->prepare($detailSql);
    $detailParams = array_merge([':weekly_id' => $weeklyId], $scopeParams);
    $detailStmt->execute($detailParams);
    $weekly = $detailStmt->fetch() ?: null;

    if (!$weekly) {
        return null;
    }

    $weekly = array_merge($weekly, fleet_recalculate_weekly($pdo, (int) $weekly['id']));
    $weekly['submission_round'] = fleet_current_submission_round($weekly);
    $weekly['workflow_reviews'] = fleet_fetch_reconciliation_reviews($pdo, (int) $weekly['id'], (int) $weekly['submission_round']);
    $weekly['workflow_stage'] = fleet_resolve_reconciliation_stage($weekly, $weekly['workflow_reviews']);

    return $weekly;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weekly_liquidation_id'], $_POST['review_action'])) {
    $weeklyId = (int) $_POST['weekly_liquidation_id'];
    $reviewAction = trim((string) ($_POST['review_action'] ?? ''));
    $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
    $reviewConfirmed = !empty($_POST['review_confirmed']);

    try {
        $weekly = $loadWeeklyInScope($weeklyId);
        if (!$weekly) {
            throw new RuntimeException('The selected reconciliation package could not be found in your review scope.');
        }

        $workflowStage = $weekly['workflow_stage'];
        $canActNow = $workflowStage['can_progress'] && ($workflowStage['next_role'] === ($user['role'] ?? ''));
        if (!$canActNow) {
            throw new RuntimeException('This reconciliation package is not waiting for your review stage.');
        }

        if (in_array($reviewAction, ['checked', 'approve'], true) && !$reviewConfirmed) {
            throw new RuntimeException('Tick the confirmation box before continuing.');
        }

        if ($reviewAction === 'checked' && !in_array($user['role'], ['provincial_admin', 'fleet_manager'], true)) {
            throw new RuntimeException('Only Provincial Admin and Fleet Manager can mark a package as checked.');
        }

        if ($reviewAction === 'approve' && !$isFinanceReviewer) {
            throw new RuntimeException('Only Finance can give the final approval.');
        }

        if ($reviewAction === 'return' && $reviewNotes === '') {
            throw new RuntimeException('Review notes are required when returning a reconciliation package.');
        }

        $pdo->beginTransaction();

        if ($reviewAction === 'checked') {
            fleet_record_reconciliation_review(
                $pdo,
                (int) $weekly['id'],
                (int) $weekly['submission_round'],
                $user,
                'checked',
                $reviewNotes
            );

            $actionStmt = $pdo->prepare("
                UPDATE weekly_liquidations
                SET status = 'under_review',
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    review_notes = ?,
                    return_reason = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $actionStmt->execute([
                $user['id'],
                $reviewNotes !== '' ? $reviewNotes : null,
                $weeklyId,
            ]);
        } elseif ($reviewAction === 'approve') {
            fleet_record_reconciliation_review(
                $pdo,
                (int) $weekly['id'],
                (int) $weekly['submission_round'],
                $user,
                'approved',
                $reviewNotes
            );

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
                $reviewNotes !== '' ? $reviewNotes : null,
                $weeklyId,
            ]);
        } elseif ($reviewAction === 'return') {
            fleet_record_reconciliation_review(
                $pdo,
                (int) $weekly['id'],
                (int) $weekly['submission_round'],
                $user,
                'returned',
                $reviewNotes
            );

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

        $successMessage = match ($reviewAction) {
            'checked' => $user['role'] === 'provincial_admin'
                ? 'Province check saved and sent to Fleet Manager.'
                : 'Fleet check saved and sent to Finance.',
            'approve' => 'Finance approved the reconciliation package.',
            'return' => 'Reconciliation package returned to the driver for correction.',
            default => 'Review saved.',
        };
        fleet_set_flash('success', $successMessage);
        header('Location: ' . $selfPath . '?weekly_id=' . urlencode((string) $weeklyId) . $provinceFilterQuery);
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
        wl.id,
        wl.driver_id,
        wl.vehicle_id,
        wl.facility_id,
        wl.week_start_date,
        wl.status,
        wl.submission_round,
        wl.total_trip_legs,
        wl.total_fuel_amount,
        u.name AS driver_name,
        u.email AS driver_email,
        v.vehicle_name,
        v.number_plate,
        f.facility_name
    FROM weekly_liquidations wl
    JOIN users u
        ON u.id = wl.driver_id
    JOIN vehicles v
        ON v.id = wl.vehicle_id
    LEFT JOIN facilities f
        ON f.id = wl.facility_id
    WHERE wl.status IN ('submitted', 'under_review', 'returned', 'approved')" . $scopeSql . "
    ORDER BY wl.week_start_date DESC, wl.id DESC
";
$queueStmt = $pdo->prepare($queueSql);
$queueStmt->execute($scopeParams);
$queueCandidates = $queueStmt->fetchAll();

$queue = [];
foreach ($queueCandidates as $weeklyItem) {
    $weeklyItem['submission_round'] = fleet_current_submission_round($weeklyItem);
    $reviews = fleet_fetch_reconciliation_reviews($pdo, (int) $weeklyItem['id'], (int) $weeklyItem['submission_round']);
    $stage = fleet_resolve_reconciliation_stage($weeklyItem, $reviews);
    $weeklyItem['workflow_stage'] = $stage;
    $weeklyItem['workflow_reviews'] = $reviews;

    $isFinalHistory = in_array($weeklyItem['status'], ['approved', 'returned'], true);
    $isWaitingForCurrentRole = $stage['next_role'] === ($user['role'] ?? '');
    if ($isWaitingForCurrentRole || $isFinalHistory) {
        $queue[] = $weeklyItem;
    }
}

usort($queue, static function (array $left, array $right) use ($user): int {
    $leftActive = (($left['workflow_stage']['next_role'] ?? null) === ($user['role'] ?? '')) ? 0 : 1;
    $rightActive = (($right['workflow_stage']['next_role'] ?? null) === ($user['role'] ?? '')) ? 0 : 1;
    if ($leftActive !== $rightActive) {
        return $leftActive <=> $rightActive;
    }

    return strtotime((string) $right['week_start_date']) <=> strtotime((string) $left['week_start_date']);
});

$selectedWeeklyId = isset($_GET['weekly_id']) ? (int) $_GET['weekly_id'] : 0;
if ($selectedWeeklyId === 0 && $queue) {
    $selectedWeeklyId = (int) $queue[0]['id'];
}

$selectedWeekly = $selectedWeeklyId > 0 ? $loadWeeklyInScope($selectedWeeklyId) : null;
$selectedTripLegs = [];
$selectedFuelPurchases = [];
if ($selectedWeekly) {
    $selectedTripLegs = fleet_fetch_weekly_trip_legs($pdo, (int) $selectedWeekly['id']);
    $selectedFuelPurchases = fleet_fetch_weekly_fuel_purchases($pdo, (int) $selectedWeekly['id']);
}

$selectedStage = $selectedWeekly['workflow_stage'] ?? null;
$selectedReviews = $selectedWeekly['workflow_reviews'] ?? [];
$canReviewNow = $selectedStage && ($selectedStage['can_progress'] ?? false) && (($selectedStage['next_role'] ?? null) === ($user['role'] ?? ''));
$progressButtonLabel = match ($user['role']) {
    'provincial_admin' => 'Mark Checked',
    'fleet_manager' => 'Send to Finance',
    'finance' => 'Approve Reconciliation',
    default => 'Save Review',
};
$progressActionValue = $user['role'] === 'finance' ? 'approve' : 'checked';
$confirmationLabel = match ($user['role']) {
    'provincial_admin' => 'I have checked this package and it is ready for Fleet Manager review.',
    'fleet_manager' => 'I have checked this package and it is ready for Finance review.',
    'finance' => 'I have checked this package and approve it for final finance clearance.',
    default => 'I have reviewed this package.',
};
$workflowSubtitle = $isFinanceReviewer
    ? 'Finance can see packages after Provincial Admin and Fleet Manager checks, then give the final approval.'
    : 'Review follows a staged workflow: Provincial Admin first, then Fleet Manager, then Finance.';

ob_start();

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>' . fleet_h($queueHeading) . '</h2></div></div>';
if ($provinceOptions) {
    echo '<div class="vehicle-switcher" style="margin-bottom:18px;">';
    echo '<a class="vehicle-pill' . ($selectedProvinceId === 0 ? ' active' : '') . '" href="' . fleet_h($selfPath) . '"><span>All Provinces</span></a>';
    foreach ($provinceOptions as $province) {
        $isActiveProvince = (int) $province['id'] === $selectedProvinceId;
        echo '<a class="vehicle-pill' . ($isActiveProvince ? ' active' : '') . '" href="' . fleet_h($selfPath) . '?province_id=' . urlencode((string) $province['id']) . '"><span>' . fleet_h($province['facility_name']) . '</span></a>';
    }
    echo '</div>';
}
echo '<div class="review-layout">';
echo '<div class="queue-list">';
if (!$queue) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-user-check"></i></div>';
    echo '<h3>No reconciliation packages waiting</h3>';
    echo '<p>' . fleet_h($isFinanceReviewer ? 'When Fleet Manager completes a review, the package will arrive here for final finance approval.' : 'When a reconciliation package reaches your review stage, it will appear here.') . '</p>';
    echo '</div>';
} else {
    foreach ($queue as $weeklyItem) {
        $isActive = (int) $weeklyItem['id'] === (int) $selectedWeeklyId;
        $stageLabel = (string) ($weeklyItem['workflow_stage']['stage_label'] ?? ucwords(str_replace('_', ' ', (string) $weeklyItem['status'])));
        echo '<a class="queue-item' . ($isActive ? ' active' : '') . '" href="' . fleet_h($selfPath) . '?weekly_id=' . urlencode((string) $weeklyItem['id']) . $provinceFilterQuery . '">';
        echo '<h3>' . fleet_h($weeklyItem['driver_name']) . ' · ' . fleet_h($weeklyItem['number_plate']) . '</h3>';
        echo '<p>' . fleet_h($weeklyItem['vehicle_name']) . ' · ' . fleet_h($weeklyItem['facility_name'] ?? 'Province not set') . '</p>';
        echo '<p>' . fleet_h(fleet_format_week_label($weeklyItem['week_start_date'])) . ' · Round ' . fleet_h((string) max(1, (int) $weeklyItem['submission_round'])) . '</p>';
        echo '<div class="queue-meta">';
        echo '<span class="status-pill ' . fleet_h($weeklyItem['status']) . '">' . fleet_h($weeklyItem['status']) . '</span>';
        echo '<span class="helper-text">' . fleet_h($stageLabel) . '</span>';
        echo '</div>';
        echo '</a>';
    }
}
echo '</div>';

echo '<div class="stack">';
if (!$selectedWeekly) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-clipboard-check"></i></div>';
    echo '<h3>Select a reconciliation package</h3>';
    echo '<p>Choose an item from the queue to inspect the movement legs, fuel purchases, review trail, and supporting evidence.</p>';
    echo '</div>';
} else {
    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header-split">';
    echo '<div><h2>' . fleet_h($selectedWeekly['driver_name']) . ' · ' . fleet_h($selectedWeekly['number_plate']) . '</h2><p>' . fleet_h($selectedWeekly['vehicle_name']) . ' · ' . fleet_h($selectedWeekly['facility_name'] ?? 'Province not set') . ' · ' . fleet_h(fleet_format_week_label($selectedWeekly['week_start_date'])) . ' · Submission Round ' . fleet_h((string) max(1, (int) $selectedWeekly['submission_round'])) . '</p></div>';
    echo '<span class="status-pill ' . fleet_h($selectedWeekly['status']) . '">' . fleet_h($selectedStage['stage_label'] ?? $selectedWeekly['status']) . '</span>';
    echo '</div>';
    echo '<div class="metric-grid">';
    echo '<div class="metric-card"><div class="metric-label">Movement Legs</div><div class="metric-value">' . number_format((int) $selectedWeekly['total_trip_legs']) . '</div><div class="metric-caption">' . fleet_format_km($selectedWeekly['total_km']) . '</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Fuel Purchases</div><div class="metric-value">' . number_format((int) $selectedWeekly['total_fuel_purchases']) . '</div><div class="metric-caption">' . number_format((float) $selectedWeekly['total_fuel_litres'], 2) . ' litres</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Fuel Amount</div><div class="metric-value">K ' . number_format((float) $selectedWeekly['total_fuel_amount'], 2) . '</div><div class="metric-caption">' . ((int) $selectedWeekly['missing_receipts'] > 0 ? number_format((int) $selectedWeekly['missing_receipts']) . ' missing receipt(s)' : 'Receipts present') . '</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Issues</div><div class="metric-value">' . (($selectedWeekly['has_issues'] ?? 0) ? 'Yes' : 'No') . '</div><div class="metric-caption">' . fleet_h($selectedWeekly['issue_notes'] ?: 'No validation issues recorded.') . '</div></div>';
    echo '</div>';
    if (!empty($selectedWeekly['return_reason'])) {
        echo '<div class="alert alert-info" style="margin-top:18px;"><i class="fas fa-reply"></i><span>Return reason: ' . fleet_h($selectedWeekly['return_reason']) . '</span></div>';
    }
    echo '</section>';

    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header"><div><h2>Review Trail</h2><p>Each stage is recorded separately for this submission round.</p></div></div>';
    echo '<div class="metric-grid">';
    foreach (fleet_review_role_sequence() as $roleCode) {
        $roleReview = $selectedStage['latest_by_role'][$roleCode] ?? null;
        $roleLabel = fleet_review_role_action_label($roleCode);
        $caption = 'Waiting';
        $value = 'Pending';
        if ($roleReview) {
            $value = fleet_review_action_display_label((string) $roleReview['review_action']);
            $caption = trim((string) ($roleReview['reviewer_name_display'] ?? ''));
            $reviewerEmail = trim((string) ($roleReview['reviewer_email_display'] ?? ''));
            $reviewedAt = !empty($roleReview['reviewed_at']) ? date('d M Y H:i', strtotime((string) $roleReview['reviewed_at'])) : '';
            if ($reviewerEmail !== '') {
                $caption .= ' · ' . $reviewerEmail;
            }
            if ($reviewedAt !== '') {
                $caption .= ' · ' . $reviewedAt;
            }
        } elseif (($selectedStage['next_role'] ?? null) !== $roleCode && in_array($selectedWeekly['status'], ['approved', 'returned'], true)) {
            $value = 'Not Used';
            $caption = 'No action recorded in this round.';
        }

        echo '<div class="metric-card">';
        echo '<div class="metric-label">' . fleet_h($roleLabel) . '</div>';
        echo '<div class="metric-value">' . fleet_h($value) . '</div>';
        echo '<div class="metric-caption">' . fleet_h($caption) . '</div>';
        if (!empty($roleReview['review_notes'])) {
            echo '<p class="helper-text" style="margin-top:10px;">' . fleet_h($roleReview['review_notes']) . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';

    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header"><div><h2>Current Action</h2><p>' . fleet_h($selectedStage['stage_label'] ?? 'Review in progress') . '</p></div></div>';
    if ($canReviewNow) {
        echo '<form method="post" class="form-grid" style="margin-top:16px;">';
        echo '<input type="hidden" name="weekly_liquidation_id" value="' . fleet_h($selectedWeekly['id']) . '">';
        echo '<div class="form-group span-12"><label for="review_notes">Review Notes</label><textarea id="review_notes" name="review_notes" placeholder="Add notes for audit history or for the driver if you are returning the package."></textarea></div>';
        echo '<div class="form-group span-12"><label style="display:inline-flex;align-items:flex-start;gap:10px;"><input type="checkbox" name="review_confirmed" value="1" style="margin-top:4px;"> <span>' . fleet_h($confirmationLabel) . '</span></label></div>';
        echo '<div class="form-group span-12"><div class="button-row">';
        echo '<button class="button" type="submit" name="review_action" value="' . fleet_h($progressActionValue) . '"><i class="fas fa-check"></i>' . fleet_h($progressButtonLabel) . '</button>';
        echo '<button class="button-secondary" type="submit" name="review_action" value="return"><i class="fas fa-reply"></i>Return to Driver</button>';
        echo '</div></div>';
        echo '</form>';
    } else {
        echo '<p class="helper-text">This package is not waiting for your review stage right now. The audit trail above shows how far it has moved.</p>';
    }
    echo '</section>';

    echo '<section class="panel" style="margin-bottom:0;">';
    echo '<div class="panel-header"><div><h2>Movement Legs</h2><p>Review the trip legs for completeness, odometer continuity, and purpose clarity.</p></div></div>';
    if (!$selectedTripLegs) {
        echo '<p class="helper-text">No movement legs are attached to this reconciliation package.</p>';
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
            echo '<div class="detail-pair"><span class="detail-pair-label">Start km</span><span class="detail-pair-value">' . fleet_format_km($trip['odometer_start_km']) . '</span></div>';
            echo '<div class="detail-pair"><span class="detail-pair-label">End km</span><span class="detail-pair-value">' . fleet_format_km($trip['odometer_end_km']) . '</span></div>';
            echo '<div class="detail-pair"><span class="detail-pair-label">Total km</span><span class="detail-pair-value">' . fleet_format_km($trip['total_km']) . '</span></div>';
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
    echo '<div class="panel-header"><div><h2>Fuel Purchases</h2><p>Check amount, litres, station, receipt number, receipt attachment, and fuel pump photo for each purchase.</p></div></div>';
    if (!$selectedFuelPurchases) {
        echo '<p class="helper-text">No fuel purchases are attached to this reconciliation package.</p>';
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
}
echo '</div>';
echo '</div>';
echo '</section>';
$pageContent = ob_get_clean();

if ($isFinanceReviewer && ($useFinanceDashboardShell ?? false)) {
    $dashboardCssVersion = @filemtime($appRoot . '/assets/css/dashboard.css') ?: time();
    $fleetCssVersion = @filemtime($appRoot . '/assets/css/fleet_redesign.css') ?: time();

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . fleet_h($pageTitle) . '</title>';
    require $appRoot . '/favicon_links.php';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
    echo '<link rel="stylesheet" type="text/css" href="assets/css/dashboard.css?v=' . urlencode((string) $dashboardCssVersion) . '">';
    echo '<link rel="stylesheet" type="text/css" href="assets/css/fleet_redesign.css?v=' . urlencode((string) $fleetCssVersion) . '">';
    echo '<style>
        .finance-history-shell .page-subtitle { margin-top: 6px; color: var(--gray-600); font-size: 14px; }
        .finance-history-shell .header-title-block { display: flex; flex-direction: column; gap: 4px; }
        .finance-history-shell .panel:first-child { margin-top: 0; }
        .finance-history-shell .panel,
        .finance-history-shell .metric-card,
        .finance-history-shell .data-item,
        .finance-history-shell .queue-item { border-radius: 12px; }
    </style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="container finance-history-shell">';
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-header">';
    echo '<button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>';
    echo '<h2 class="sidebar-brand-finance"><span>Finance Hub</span></h2>';
    echo '</div>';
    echo '<ul class="menu">';
    echo '<li><a href="dashboard.php"><span class="menu-icon"><i class="fas fa-home"></i></span><span class="menu-text">Dashboard</span></a></li>';
    echo '<li><a href="finance_hub.php" class="active"><span class="menu-icon"><i class="fas fa-user-check"></i></span><span class="menu-text">Reconciliation History</span></a></li>';
    echo '<li><a href="reports.php"><span class="menu-icon"><i class="fas fa-chart-line"></i></span><span class="menu-text">Reports</span></a></li>';
    echo '</ul>';
    echo '</aside>';
    echo '<main class="main-content">';
    echo '<div class="header">';
    echo '<div class="header-title-block">';
    echo '<h1>' . fleet_h($pageTitle) . '</h1>';
    echo '<p class="page-subtitle">' . fleet_h($workflowSubtitle) . '</p>';
    echo '</div>';
    echo '<div class="user-header">';
    echo '<div class="user-role-header">Finance</div>';
    echo '<a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>';
    echo '</div>';
    echo '</div>';
    echo $pageContent;
    echo '</main>';
    echo '</div>';
    echo '<script>function toggleSidebar(){document.getElementById("sidebar").classList.toggle("collapsed");}</script>';
    echo '</body>';
    echo '</html>';
} else {
    fleet_render_shell_start(
        $pageTitle,
        'province_liquidation',
        $user,
        $workflowSubtitle
    );
    echo $pageContent;
    fleet_render_shell_end();
}
