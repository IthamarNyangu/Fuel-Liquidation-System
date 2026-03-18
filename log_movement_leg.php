<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Log Movement Leg', 'movement_leg');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_REQUEST['vehicle_id']) ? (int) $_REQUEST['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$pageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedVehicle) {
    $movementDate = $_POST['movement_date'] ?? date('Y-m-d');
    $timeOut = trim((string) ($_POST['time_out'] ?? ''));
    $timeIn = trim((string) ($_POST['time_in'] ?? ''));
    $fromLocation = trim((string) ($_POST['from_location'] ?? ''));
    $toLocation = trim((string) ($_POST['to_location'] ?? ''));
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $startKm = (float) ($_POST['odometer_start_km'] ?? 0);
    $endKm = (float) ($_POST['odometer_end_km'] ?? 0);
    $confirmedByName = trim((string) ($_POST['confirmed_by_name'] ?? ''));
    $confirmedByTitle = trim((string) ($_POST['confirmed_by_title'] ?? ''));
    $confirmedByContact = trim((string) ($_POST['confirmed_by_contact'] ?? ''));
    $weekStart = fleet_week_start_from_date($movementDate);

    try {
        if ($fromLocation === '' || $toLocation === '' || $purpose === '' || $confirmedByName === '') {
            throw new RuntimeException('From, to, purpose, and confirmed by are required.');
        }

        if ($timeOut === '' || $timeIn === '') {
            throw new RuntimeException('Time out and time in are required.');
        }

        if ($timeIn <= $timeOut) {
            throw new RuntimeException('Time in must be after time out for the same movement leg.');
        }

        if ($endKm < $startKm) {
            throw new RuntimeException('End odometer must be greater than or equal to start odometer.');
        }

        $existingWeekly = fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
        if ($existingWeekly && !fleet_week_is_editable($existingWeekly)) {
            throw new RuntimeException('This week has already been submitted or approved. It must be returned before you can add more movement legs.');
        }

        $latestKnown = fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']);
        if ($startKm < $latestKnown) {
            throw new RuntimeException('Start odometer cannot be lower than the last known reading of ' . number_format($latestKnown, 1) . ' km.');
        }

        $overlapStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM trip_legs
            WHERE vehicle_id = ?
              AND movement_date = ?
              AND record_status <> 'voided'
              AND NOT (? >= time_in OR ? <= time_out)
        ");
        $overlapStmt->execute([
            $selectedVehicle['id'],
            $movementDate,
            $timeOut,
            $timeIn,
        ]);
        if ((int) $overlapStmt->fetchColumn() > 0) {
            throw new RuntimeException('This movement time overlaps with another recorded leg for the same vehicle.');
        }

        $issueNotes = [];
        if ($startKm > $latestKnown) {
            $issueNotes[] = 'Start odometer is above the last known reading. Confirm that no leg is missing before this one.';
        }

        $pdo->beginTransaction();

        $insertStmt = $pdo->prepare("
            INSERT INTO trip_legs (
                vehicle_id,
                driver_id,
                facility_id,
                movement_date,
                week_start_date,
                time_out,
                time_in,
                from_location,
                to_location,
                purpose,
                odometer_start_km,
                odometer_end_km,
                total_km,
                confirmed_by_name,
                confirmed_by_title,
                confirmed_by_contact,
                record_status,
                has_issues,
                issue_notes,
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recorded', ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $selectedVehicle['id'],
            $user['id'],
            $selectedVehicle['facility_id'],
            $movementDate,
            $weekStart,
            $timeOut,
            $timeIn,
            $fromLocation,
            $toLocation,
            $purpose,
            $startKm,
            $endKm,
            $endKm - $startKm,
            $confirmedByName,
            $confirmedByTitle ?: null,
            $confirmedByContact ?: null,
            $issueNotes ? 1 : 0,
            $issueNotes ? implode(' ', $issueNotes) : null,
            $user['id'],
            $user['id'],
        ]);

        $tripLegId = (int) $pdo->lastInsertId();

        $updateVehicleStmt = $pdo->prepare("
            UPDATE vehicles
            SET current_mileage = CASE
                WHEN current_mileage IS NULL OR current_mileage < ? THEN ?
                ELSE current_mileage
            END
            WHERE id = ?
        ");
        $updateVehicleStmt->execute([$endKm, $endKm, $selectedVehicle['id']]);

        $weekly = fleet_ensure_weekly_liquidation(
            $pdo,
            $user['id'],
            (int) $selectedVehicle['id'],
            $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
            $weekStart
        );
        fleet_attach_item_to_weekly($pdo, (int) $weekly['id'], 'trip_leg', $tripLegId);
        fleet_recalculate_weekly($pdo, (int) $weekly['id']);

        $pdo->commit();

        fleet_set_flash(
            'success',
            'Movement leg saved for ' . $selectedVehicle['vehicle_name'] . ' (' . $selectedVehicle['number_plate'] . ').'
        );
        header('Location: log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
    }
}

$weekStart = fleet_week_start_from_date($_GET['week_start'] ?? date('Y-m-d'));
$weekEnd = fleet_week_end_from_start($weekStart);
$latestKnown = $selectedVehicle ? fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']) : 0;
$weekly = $selectedVehicle ? fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart) : null;
if ($weekly) {
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
}

$recentTrips = [];
if ($selectedVehicle) {
    $recentStmt = $pdo->prepare("
        SELECT *
        FROM trip_legs
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND week_start_date = ?
          AND record_status <> 'voided'
        ORDER BY movement_date DESC, time_out DESC, id DESC
        LIMIT 6
    ");
    $recentStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
    $recentTrips = $recentStmt->fetchAll();
}

fleet_render_shell_start(
    'Log Movement Leg',
    'movement_leg',
    $user,
    'Capture one movement leg at a time. This should reflect the paper logbook pattern, not one whole day grouped together.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h2>No vehicle available</h2>';
    echo '<p>A movement leg can only be logged against an assigned or accessible vehicle. Once a vehicle is assigned, return here and the form will be ready.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Select Vehicle</h2><p>Pick the vehicle you are logging for, then enter only one route segment in the form below.</p></div>';
echo '<a class="inline-link-button" href="my_vehicle.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-car-side"></i>Back to My Vehicle</a>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i><span>' . fleet_h($vehicle['vehicle_name']) . ' · ' . fleet_h($vehicle['number_plate']) . '</span></a>';
}
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="weekly-navigator">';
echo '<div class="week-badge"><i class="fas fa-calendar-week"></i><span>' . fleet_h(fleet_format_week_label($weekStart)) . '</span></div>';
echo '<div class="button-row">';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode((new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d')) . '"><i class="fas fa-arrow-left"></i>Previous Week</a>';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode(fleet_week_start_from_date(date('Y-m-d'))) . '"><i class="fas fa-bullseye"></i>This Week</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>' . fleet_h($selectedVehicle['vehicle_name']) . ' · ' . fleet_h($selectedVehicle['number_plate']) . '</h2><p>Week window: ' . fleet_h($weekStart) . ' to ' . fleet_h($weekEnd) . '. Latest known odometer is ' . number_format($latestKnown, 1) . ' km.</p></div>';
if ($weekly) {
    echo '<span class="status-pill ' . fleet_h($weekly['status']) . '">' . fleet_h($weekly['status']) . '</span>';
}
echo '</div>';
echo '<form method="post" class="form-grid" novalidate>';
echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
echo '<div class="form-group span-4"><label for="movement_date">Movement Date</label><input id="movement_date" type="date" name="movement_date" value="' . fleet_h($_POST['movement_date'] ?? date('Y-m-d')) . '" required></div>';
echo '<div class="form-group span-4"><label for="time_out">Time Out</label><input id="time_out" type="time" name="time_out" value="' . fleet_h($_POST['time_out'] ?? date('H:i')) . '" required></div>';
echo '<div class="form-group span-4"><label for="time_in">Time In</label><input id="time_in" type="time" name="time_in" value="' . fleet_h($_POST['time_in'] ?? date('H:i', strtotime('+1 hour'))) . '" required></div>';
echo '<div class="form-group span-6"><label for="from_location">From</label><input id="from_location" type="text" name="from_location" value="' . fleet_h($_POST['from_location'] ?? '') . '" placeholder="Departure point" required></div>';
echo '<div class="form-group span-6"><label for="to_location">To</label><input id="to_location" type="text" name="to_location" value="' . fleet_h($_POST['to_location'] ?? '') . '" placeholder="Arrival point" required></div>';
echo '<div class="form-group span-12"><label for="purpose">Purpose / Activity</label><input id="purpose" type="text" name="purpose" value="' . fleet_h($_POST['purpose'] ?? '') . '" placeholder="Why was this leg taken?" required></div>';
echo '<div class="form-group span-4"><label for="odometer_start_km">Start KM</label><input id="odometer_start_km" type="number" step="0.1" min="0" name="odometer_start_km" value="' . fleet_h($_POST['odometer_start_km'] ?? number_format($latestKnown, 1, '.', '')) . '" required><div class="input-hint">Must not be below ' . number_format($latestKnown, 1) . ' km.</div></div>';
echo '<div class="form-group span-4"><label for="odometer_end_km">End KM</label><input id="odometer_end_km" type="number" step="0.1" min="0" name="odometer_end_km" value="' . fleet_h($_POST['odometer_end_km'] ?? number_format($latestKnown, 1, '.', '')) . '" required></div>';
echo '<div class="form-group span-4"><label for="total_km_preview">Total KM</label><input id="total_km_preview" type="text" value="0.0 km" readonly><div class="input-hint">Calculated automatically from start and end odometer.</div></div>';
echo '<div class="form-group span-6"><label for="confirmed_by_name">Confirmed By</label><input id="confirmed_by_name" type="text" name="confirmed_by_name" value="' . fleet_h($_POST['confirmed_by_name'] ?? '') . '" placeholder="Passenger or requesting officer" required></div>';
echo '<div class="form-group span-3"><label for="confirmed_by_title">Title</label><input id="confirmed_by_title" type="text" name="confirmed_by_title" value="' . fleet_h($_POST['confirmed_by_title'] ?? '') . '" placeholder="Optional"></div>';
echo '<div class="form-group span-3"><label for="confirmed_by_contact">Contact</label><input id="confirmed_by_contact" type="text" name="confirmed_by_contact" value="' . fleet_h($_POST['confirmed_by_contact'] ?? '') . '" placeholder="Optional"></div>';
echo '<div class="form-group span-12"><div class="button-row"><button class="button" type="submit"><i class="fas fa-save"></i>Save Movement Leg</button><a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-clipboard-check"></i>Review Weekly Liquidation</a></div></div>';
echo '</form>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Current Week Movement Legs</h2><p>These are the leg-by-leg entries already recorded for the selected week.</p></div></div>';
if (!$recentTrips) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h3>No legs recorded yet</h3>';
    echo '<p>Once you save the first movement leg, it will appear here and be added into the weekly liquidation draft automatically.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($recentTrips as $trip) {
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($trip['from_location']) . ' to ' . fleet_h($trip['to_location']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($trip['movement_date'])) . ' · ' . fleet_h(substr((string) $trip['time_out'], 0, 5)) . ' to ' . fleet_h(substr((string) $trip['time_in'], 0, 5)) . '</p></div>';
        echo '<span class="status-pill ' . fleet_h($trip['record_status']) . '">' . fleet_h($trip['record_status']) . '</span>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Purpose</span><span class="detail-pair-value">' . fleet_h($trip['purpose']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Start km</span><span class="detail-pair-value">' . number_format((float) $trip['odometer_start_km'], 1) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">End km</span><span class="detail-pair-value">' . number_format((float) $trip['odometer_end_km'], 1) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Total km</span><span class="detail-pair-value">' . number_format((float) $trip['total_km'], 1) . '</span></div>';
        echo '</div>';
        echo '<p class="helper-text">Confirmed by ' . fleet_h($trip['confirmed_by_name']) . ($trip['confirmed_by_title'] ? ' · ' . fleet_h($trip['confirmed_by_title']) : '') . '</p>';
        if (!empty($trip['issue_notes'])) {
            echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($trip['issue_notes']) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
}
echo '</section>';

echo '<script>';
echo 'const startKmInput = document.getElementById("odometer_start_km");';
echo 'const endKmInput = document.getElementById("odometer_end_km");';
echo 'const totalKmPreview = document.getElementById("total_km_preview");';
echo 'function updateTripKmPreview(){const start=parseFloat(startKmInput.value)||0;const end=parseFloat(endKmInput.value)||0;const total=Math.max(0,end-start);totalKmPreview.value=total.toFixed(1)+" km";}';
echo 'startKmInput.addEventListener("input", updateTripKmPreview);';
echo 'endKmInput.addEventListener("input", updateTripKmPreview);';
echo 'updateTripKmPreview();';
echo '</script>';

fleet_render_shell_end();
