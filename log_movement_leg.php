<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

function movement_short_time(?string $time): string
{
    if ($time === null || $time === '') {
        return '--';
    }

    return substr((string) $time, 0, 5);
}

function movement_display_time(?string $time): string
{
    if ($time === null || $time === '') {
        return '--';
    }

    $trimmed = trim((string) $time);
    $normalized = strlen($trimmed) === 5 ? $trimmed . ':00' : $trimmed;
    $parsed = DateTimeImmutable::createFromFormat('H:i:s', $normalized);

    if ($parsed === false) {
        return movement_short_time($time);
    }

    return strtolower($parsed->format('h:i a'));
}

function movement_make_datetime(string $date, string $time): ?DateTimeImmutable
{
    if (!movement_is_valid_date($date) || !movement_is_valid_time($time)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);

    return $parsed !== false ? $parsed : null;
}

function movement_shift_datetime(string $date, ?string $time, int $minutes): ?array
{
    if ($time === null || $time === '') {
        return null;
    }

    $base = movement_make_datetime($date, movement_short_time($time));
    if (!$base) {
        return null;
    }

    $shifted = $base->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes');
    if ($shifted === false) {
        return null;
    }

    return [
        'date' => $shifted->format('Y-m-d'),
        'time' => $shifted->format('H:i'),
    ];
}

function movement_is_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function movement_is_valid_time(string $time): bool
{
    return preg_match('/^\d{2}:\d{2}$/', $time) === 1;
}

function movement_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function movement_format_km($value, string $suffix = ''): string
{
    if ($value === null || $value === '') {
        return '--';
    }

    return fleet_format_km($value, $suffix === '');
}

function movement_find_confirmer(array $confirmers, int $userId): ?array
{
    foreach ($confirmers as $confirmer) {
        if ((int) $confirmer['id'] === $userId) {
            return $confirmer;
        }
    }

    return null;
}

function movement_text_or_null(?string $value): ?string
{
    $trimmed = trim((string) $value);

    return $trimmed === '' ? null : $trimmed;
}

function movement_purpose_options(): array
{
    return ['Drop Off', 'Pick Up'];
}

function movement_match_purpose_option(?string $purpose): ?string
{
    $normalized = strtolower(trim((string) $purpose));

    foreach (movement_purpose_options() as $option) {
        if ($normalized === strtolower($option)) {
            return $option;
        }
    }

    return null;
}

function movement_resolve_purpose_input(string $choice, string $other): string
{
    $matchedOption = movement_match_purpose_option($choice);
    if ($matchedOption !== null) {
        return $matchedOption;
    }

    if (trim($choice) === 'Other') {
        return trim($other);
    }

    return '';
}

function movement_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function movement_trip_passenger_name(array $trip): string
{
    $passengerName = trim((string) ($trip['passenger_name'] ?? ''));
    if ($passengerName !== '') {
        return $passengerName;
    }

    return trim((string) ($trip['confirmed_by_name'] ?? ''));
}

function movement_trip_confirmer_name(array $trip): string
{
    $passengerName = trim((string) ($trip['passenger_name'] ?? ''));
    if ($passengerName === '') {
        return '';
    }

    return trim((string) ($trip['confirmed_by_name'] ?? ''));
}

function movement_trip_arrival_date(array $trip): string
{
    $arrivalDate = trim((string) ($trip['arrival_date'] ?? ''));

    return $arrivalDate !== '' ? $arrivalDate : (string) ($trip['movement_date'] ?? '');
}

function movement_trip_reference_date(array $trip): string
{
    return movement_trip_arrival_date($trip);
}

function movement_trip_start_datetime(array $trip): ?DateTimeImmutable
{
    return movement_make_datetime((string) ($trip['movement_date'] ?? ''), movement_short_time((string) ($trip['time_out'] ?? '')));
}

function movement_trip_end_datetime(array $trip): ?DateTimeImmutable
{
    if (empty($trip['time_in'])) {
        return null;
    }

    return movement_make_datetime(movement_trip_arrival_date($trip), movement_short_time((string) $trip['time_in']));
}

function movement_trip_date_label(array $trip): string
{
    $departureDate = trim((string) ($trip['movement_date'] ?? ''));
    $arrivalDate = movement_trip_arrival_date($trip);

    if ($departureDate !== '' && $arrivalDate !== '' && $departureDate !== $arrivalDate) {
        return date('D, d M Y', strtotime($departureDate)) . ' to ' . date('D, d M Y', strtotime($arrivalDate));
    }

    return $departureDate !== '' ? date('D, d M Y', strtotime($departureDate)) : '--';
}

function movement_trip_time_label(array $trip): string
{
    $start = movement_display_time((string) ($trip['time_out'] ?? ''));

    if (empty($trip['time_in'])) {
        return $start . ' onward';
    }

    $end = movement_display_time((string) $trip['time_in']);
    $departureDate = trim((string) ($trip['movement_date'] ?? ''));
    $arrivalDate = movement_trip_arrival_date($trip);

    if ($departureDate !== '' && $arrivalDate !== '' && $departureDate !== $arrivalDate) {
        return $start . ' to ' . date('d M', strtotime($arrivalDate)) . ' ' . $end;
    }

    return $start . ' to ' . $end;
}

function movement_trip_window_label(array $trip): string
{
    $start = movement_trip_start_datetime($trip);
    if (!$start) {
        return 'Unknown time window';
    }

    $end = movement_trip_end_datetime($trip);
    if (!$end) {
        return $start->format('d F Y h:i a') . ' onward';
    }

    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('d F Y h:i a') . ' to ' . strtolower($end->format('h:i a'));
    }

    return $start->format('d F Y h:i a') . ' to ' . $end->format('d F Y h:i a');
}

function movement_find_conflicting_trip(
    PDO $pdo,
    int $vehicleId,
    string $startDate,
    string $startTime,
    ?string $endDate = null,
    ?string $endTime = null,
    ?int $excludeTripId = null
): ?array {
    $candidateStart = movement_make_datetime($startDate, $startTime);
    if (!$candidateStart) {
        return null;
    }

    $candidateEnd = ($endDate !== null && $endTime !== null)
        ? movement_make_datetime($endDate, $endTime)
        : null;

    $sql = "
        SELECT *
        FROM trip_legs
        WHERE vehicle_id = ?
          AND record_status <> 'voided'
    ";
    $params = [$vehicleId];

    if ($excludeTripId !== null) {
        $sql .= " AND id <> ? ";
        $params[] = $excludeTripId;
    }

    $sql .= " ORDER BY time_out, id ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $trips = $stmt->fetchAll();

    foreach ($trips as $trip) {
        $otherStart = movement_trip_start_datetime($trip);
        if (!$otherStart) {
            continue;
        }

        $otherEnd = movement_trip_end_datetime($trip);

        if ($candidateEnd === null) {
            if ($otherEnd !== null && $candidateStart >= $otherStart && $candidateStart < $otherEnd) {
                return $trip;
            }
            if ($otherEnd === null && $candidateStart >= $otherStart) {
                return $trip;
            }
            continue;
        }

        if ($otherEnd === null) {
            if ($candidateEnd > $otherStart) {
                return $trip;
            }
            continue;
        }

        if (!($candidateStart >= $otherEnd || $candidateEnd <= $otherStart)) {
            return $trip;
        }
    }

    return null;
}

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Log Movement', 'movement_leg');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_REQUEST['vehicle_id']) ? (int) $_REQUEST['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$pageError = '';

$weekSeed = $_REQUEST['week_start'] ?? date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['movement_date'])) {
        $weekSeed = (string) $_POST['movement_date'];
    } elseif (!empty($_POST['week_start'])) {
        $weekSeed = (string) $_POST['week_start'];
    }
}
$weekStart = fleet_week_start_from_date($weekSeed);
$weekEnd = fleet_week_end_from_start($weekStart);

$systemUsers = [];
$systemConfirmers = [];
if ($selectedVehicle) {
    $systemUsers = fleet_fetch_confirmable_users(
        $pdo,
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : ($user['facility_id'] ? (int) $user['facility_id'] : null)
    );
    $systemConfirmers = fleet_fetch_confirmation_users(
        $pdo,
        $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : ($user['facility_id'] ? (int) $user['facility_id'] : null)
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedVehicle) {
    $movementAction = trim((string) ($_POST['movement_action'] ?? ''));
    $returnLegsPage = max(1, (int) ($_POST['legs_page'] ?? 1));

    try {
        if ($movementAction === 'start_leg') {
            $movementDate = trim((string) ($_POST['movement_date'] ?? ''));
            $timeOut = trim((string) ($_POST['time_out'] ?? ''));
            $fromLocation = trim((string) ($_POST['from_location'] ?? ''));
            $toLocation = trim((string) ($_POST['to_location'] ?? ''));
            $purposeChoice = trim((string) ($_POST['purpose_choice'] ?? ''));
            $purposeOther = trim((string) ($_POST['purpose_other'] ?? ''));
            $purpose = movement_resolve_purpose_input($purposeChoice, $purposeOther);
            $startKmRaw = trim((string) ($_POST['odometer_start_km'] ?? ''));
            $confirmedByUserId = (int) ($_POST['confirmed_by_user_id'] ?? 0);
            $passengerName = trim((string) ($_POST['passenger_name'] ?? ''));

            if (!movement_is_valid_date($movementDate)) {
                throw new RuntimeException('Movement date is required.');
            }
            if (!movement_is_valid_time($timeOut)) {
                throw new RuntimeException('Departure time is required.');
            }
            if ($fromLocation === '' || $toLocation === '' || $purpose === '') {
                throw new RuntimeException('From, to, and purpose are required to start a leg.');
            }
            if (movement_text_length($purpose) > 80) {
                throw new RuntimeException('Purpose must be 80 characters or fewer.');
            }
            if (!fleet_is_valid_km_input($startKmRaw)) {
                throw new RuntimeException('Start KM must be a whole number.');
            }

            $confirmer = $confirmedByUserId > 0 ? movement_find_confirmer($systemConfirmers, $confirmedByUserId) : null;
            if ($confirmedByUserId > 0 && !$confirmer) {
                throw new RuntimeException('Select a valid admin or facility admin in Confirmed By.');
            }
            if (!$confirmer) {
                throw new RuntimeException('Confirmed by is required before starting a leg.');
            }
            if ($passengerName === '') {
                throw new RuntimeException('Passenger or requesting officer is required before starting a leg.');
            }
            $confirmedByName = trim((string) $confirmer['name']);
            $confirmedByContact = movement_text_or_null((string) ($confirmer['email'] ?? ''));

            $movementWeekStart = fleet_week_start_from_date($movementDate);
            $existingWeekly = fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $movementWeekStart);
            if ($existingWeekly && !fleet_week_is_editable($existingWeekly)) {
                throw new RuntimeException('This week has already been submitted or approved. It must be returned before you can add more movement legs.');
            }

            $openTrip = fleet_find_in_progress_trip_leg($pdo, (int) $selectedVehicle['id']);
            if ($openTrip) {
                throw new RuntimeException('This vehicle already has an in-progress movement leg. Complete it before starting another one.');
            }

            $conflictingTrip = movement_find_conflicting_trip(
                $pdo,
                (int) $selectedVehicle['id'],
                $movementDate,
                $timeOut
            );
            if ($conflictingTrip) {
                throw new RuntimeException(
                    'This start time falls inside an existing leg for this vehicle: '
                    . movement_trip_window_label($conflictingTrip)
                    . ' ('
                    . $conflictingTrip['from_location']
                    . ' to '
                    . $conflictingTrip['to_location']
                    . ').'
                );
            }

            $startKm = fleet_km_value($startKmRaw);
            $latestKnown = fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']);
            if ($startKm < $latestKnown) {
                throw new RuntimeException('Start KM cannot be lower than the last known reading of ' . fleet_format_km($latestKnown) . '.');
            }

            $issueNotes = [];
            if ($startKm > $latestKnown) {
                $issueNotes[] = 'Start odometer is above the last known reading. Confirm that no movement leg is missing before this one.';
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
                    confirmed_by_user_id,
                    confirmed_by_name,
                    confirmed_by_title,
                    confirmed_by_contact,
                    passenger_name,
                    record_status,
                    has_issues,
                    issue_notes,
                    created_by,
                    updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, 'in_progress', ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $selectedVehicle['id'],
                $user['id'],
                $selectedVehicle['facility_id'],
                $movementDate,
                $movementWeekStart,
                $timeOut,
                $fromLocation,
                $toLocation,
                $purpose,
                $startKm,
                $confirmer ? (int) $confirmer['id'] : null,
                $confirmedByName,
                $confirmedByContact,
                $passengerName,
                $issueNotes ? 1 : 0,
                $issueNotes ? implode(' ', $issueNotes) : null,
                $user['id'],
                $user['id'],
            ]);

            $tripLegId = (int) $pdo->lastInsertId();
            $weekly = fleet_ensure_weekly_liquidation(
                $pdo,
                $user['id'],
                (int) $selectedVehicle['id'],
                $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
                $movementWeekStart
            );
            fleet_attach_item_to_weekly($pdo, (int) $weekly['id'], 'trip_leg', $tripLegId);
            fleet_recalculate_weekly($pdo, (int) $weekly['id']);

            $pdo->commit();

            fleet_set_flash('success', 'Movement started. Complete the leg when you reach the destination.');
            header('Location: log_movement_leg.php?' . http_build_query([
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => $movementWeekStart,
                'legs_page' => $returnLegsPage,
            ]) . '#complete-leg-panel');
            exit();
        }

        if ($movementAction === 'complete_leg') {
            $tripLegId = (int) ($_POST['trip_leg_id'] ?? 0);
            $arrivalDate = trim((string) ($_POST['arrival_date'] ?? ''));
            $timeIn = trim((string) ($_POST['time_in'] ?? ''));
            $endKmRaw = trim((string) ($_POST['odometer_end_km'] ?? ''));

            $tripStmt = $pdo->prepare("
                SELECT *
                FROM trip_legs
                WHERE id = ?
                  AND vehicle_id = ?
                LIMIT 1
            ");
            $tripStmt->execute([$tripLegId, $selectedVehicle['id']]);
            $tripLeg = $tripStmt->fetch();

            if (!$tripLeg || $tripLeg['record_status'] !== 'in_progress') {
                throw new RuntimeException('The in-progress movement leg could not be found.');
            }
            if ((int) $tripLeg['driver_id'] !== (int) $user['id']) {
                throw new RuntimeException('Only the driver who started this leg can complete it.');
            }
            if (!movement_is_valid_date($arrivalDate)) {
                throw new RuntimeException('Arrival date is required to complete the leg.');
            }
            if (!movement_is_valid_time($timeIn)) {
                throw new RuntimeException('Arrival time is required to complete the leg.');
            }
            if (!fleet_is_valid_km_input($endKmRaw)) {
                throw new RuntimeException('End KM must be a whole number.');
            }

            $departureAt = movement_trip_start_datetime($tripLeg);
            $arrivalAt = movement_make_datetime($arrivalDate, $timeIn);
            if (!$departureAt || !$arrivalAt) {
                throw new RuntimeException('Departure and arrival date/time could not be validated.');
            }
            if ($arrivalAt <= $departureAt) {
                throw new RuntimeException('Arrival date and time must be after the departure date and time.');
            }

            $endKm = fleet_km_value($endKmRaw);
            $startKm = fleet_km_value($tripLeg['odometer_start_km']);
            if ($endKm <= $startKm) {
                throw new RuntimeException('End KM must be greater than Start KM.');
            }

            $existingWeekly = fleet_find_weekly_liquidation($pdo, (int) $tripLeg['driver_id'], (int) $tripLeg['vehicle_id'], (string) $tripLeg['week_start_date']);
            if ($existingWeekly && !fleet_week_is_editable($existingWeekly)) {
                throw new RuntimeException('This week has already been submitted or approved. It must be returned before you can complete more movement legs.');
            }

            $conflictingTrip = movement_find_conflicting_trip(
                $pdo,
                $tripLeg['vehicle_id'],
                $tripLeg['movement_date'],
                movement_short_time((string) $tripLeg['time_out']),
                $arrivalDate,
                $timeIn,
                (int) $tripLeg['id']
            );
            if ($conflictingTrip) {
                throw new RuntimeException(
                    'This leg overlaps with an existing leg for this vehicle: '
                    . movement_trip_window_label($conflictingTrip)
                    . ' ('
                    . $conflictingTrip['from_location']
                    . ' to '
                    . $conflictingTrip['to_location']
                    . ').'
                );
            }

            $issueNotes = [];
            if (!empty($tripLeg['issue_notes'])) {
                $issueNotes[] = trim((string) $tripLeg['issue_notes']);
            }
            $issueNotes = array_values(array_unique(array_filter($issueNotes)));
            $totalKm = $endKm - $startKm;

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE trip_legs
                SET arrival_date = ?,
                    time_in = ?,
                    odometer_end_km = ?,
                    total_km = ?,
                    record_status = 'completed',
                    has_issues = ?,
                    issue_notes = ?,
                    updated_by = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $arrivalDate,
                $timeIn,
                $endKm,
                $totalKm,
                $issueNotes ? 1 : 0,
                $issueNotes ? implode(' ', $issueNotes) : null,
                $user['id'],
                $tripLeg['id'],
            ]);

            $updateVehicleStmt = $pdo->prepare("
                UPDATE vehicles
                SET current_mileage = CASE
                    WHEN current_mileage IS NULL OR current_mileage < ? THEN ?
                    ELSE current_mileage
                END
                WHERE id = ?
            ");
            $updateVehicleStmt->execute([$endKm, $endKm, $tripLeg['vehicle_id']]);

            $weekly = fleet_ensure_weekly_liquidation(
                $pdo,
                (int) $tripLeg['driver_id'],
                (int) $tripLeg['vehicle_id'],
                $tripLeg['facility_id'] ? (int) $tripLeg['facility_id'] : null,
                (string) $tripLeg['week_start_date']
            );
            fleet_attach_item_to_weekly($pdo, (int) $weekly['id'], 'trip_leg', (int) $tripLeg['id']);
            fleet_recalculate_weekly($pdo, (int) $weekly['id']);

            $pdo->commit();

            $nextParams = [
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => fleet_week_start_from_date($arrivalDate),
                'movement_date' => $arrivalDate,
                'time_out' => $timeIn,
                'from_location' => (string) $tripLeg['to_location'],
                'odometer_start_km' => fleet_km_input_value($endKm),
                'confirmed_by_user_id' => !empty($tripLeg['confirmed_by_user_id']) ? (string) $tripLeg['confirmed_by_user_id'] : null,
                'legs_page' => $returnLegsPage,
                'prepared' => '1',
            ];
            $nextParams = array_filter($nextParams, static fn($value): bool => $value !== null && $value !== '');

            fleet_set_flash('success', 'Movement completed. The next leg is ready with smart defaults.');
            header('Location: log_movement_leg.php?' . http_build_query($nextParams) . '#start-leg-panel');
            exit();
        }

        if ($movementAction === 'delete_leg') {
            $tripLegId = (int) ($_POST['trip_leg_id'] ?? 0);

            $tripStmt = $pdo->prepare("
                SELECT *
                FROM trip_legs
                WHERE id = ?
                  AND vehicle_id = ?
                LIMIT 1
            ");
            $tripStmt->execute([$tripLegId, $selectedVehicle['id']]);
            $tripLeg = $tripStmt->fetch();

            if (!$tripLeg || $tripLeg['record_status'] === 'voided') {
                throw new RuntimeException('That movement leg could not be found.');
            }
            if ((int) $tripLeg['driver_id'] !== (int) $user['id']) {
                throw new RuntimeException('You can only delete your own movement legs.');
            }

            $existingWeekly = fleet_find_weekly_liquidation($pdo, (int) $tripLeg['driver_id'], (int) $tripLeg['vehicle_id'], (string) $tripLeg['week_start_date']);
            if ($existingWeekly && !fleet_week_is_editable($existingWeekly)) {
                throw new RuntimeException('This week has already been submitted or approved. It must be returned before you can delete movement legs.');
            }

            $pdo->beginTransaction();

            $deleteStmt = $pdo->prepare("
                UPDATE trip_legs
                SET record_status = 'voided',
                    void_reason = ?,
                    updated_by = ?
                WHERE id = ?
            ");
            $deleteStmt->execute([
                'Deleted by driver from Log Movement for testing.',
                $user['id'],
                $tripLeg['id'],
            ]);

            if ($existingWeekly) {
                fleet_recalculate_weekly($pdo, (int) $existingWeekly['id']);
            }

            $pdo->commit();

            fleet_set_flash('success', 'Movement leg deleted from the current week list.');
            header('Location: log_movement_leg.php?' . http_build_query([
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => (string) $tripLeg['week_start_date'],
                'legs_page' => $returnLegsPage,
            ]) . '#week-legs-section');
            exit();
        }

        throw new RuntimeException('Invalid movement action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
    }
}

$latestKnown = $selectedVehicle ? fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']) : 0;
$weekly = $selectedVehicle ? fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart) : null;
if ($weekly) {
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
}

$inProgressLeg = $selectedVehicle ? fleet_find_in_progress_trip_leg($pdo, (int) $selectedVehicle['id']) : null;
$openLegBelongsToDriver = $inProgressLeg && (int) $inProgressLeg['driver_id'] === (int) $user['id'];

$legsPerPage = 5;
$legsPage = max(1, (int) ($_REQUEST['legs_page'] ?? 1));
$recentTrips = [];
$recentTripsTotal = 0;
$recentTripsTotalPages = 1;
$lastCompletedLeg = null;
if ($selectedVehicle) {
    $recentCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM trip_legs
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND week_start_date = ?
          AND record_status <> 'voided'
    ");
    $recentCountStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
    $recentTripsTotal = (int) ($recentCountStmt->fetchColumn() ?: 0);
    $recentTripsTotalPages = max(1, (int) ceil($recentTripsTotal / $legsPerPage));
    $legsPage = min($legsPage, $recentTripsTotalPages);
    $legsOffset = ($legsPage - 1) * $legsPerPage;

    $recentStmt = $pdo->prepare("
        SELECT *
        FROM trip_legs
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND week_start_date = ?
          AND record_status <> 'voided'
        ORDER BY
            CASE WHEN record_status = 'in_progress' THEN 0 ELSE 1 END,
            COALESCE(arrival_date, movement_date) DESC,
            COALESCE(time_in, time_out) DESC,
            id DESC
        LIMIT ? OFFSET ?
    ");
    $recentStmt->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
    $recentStmt->bindValue(2, (int) $selectedVehicle['id'], PDO::PARAM_INT);
    $recentStmt->bindValue(3, $weekStart, PDO::PARAM_STR);
    $recentStmt->bindValue(4, $legsPerPage, PDO::PARAM_INT);
    $recentStmt->bindValue(5, $legsOffset, PDO::PARAM_INT);
    $recentStmt->execute();
    $recentTrips = $recentStmt->fetchAll();

    $lastCompletedStmt = $pdo->prepare("
        SELECT *
        FROM trip_legs
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND week_start_date = ?
          AND record_status IN ('completed', 'locked')
        ORDER BY COALESCE(arrival_date, movement_date) DESC, time_in DESC, id DESC
        LIMIT 1
    ");
    $lastCompletedStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
    $lastCompletedLeg = $lastCompletedStmt->fetch() ?: null;
}

$selectedMovementDate = $_POST['movement_date'] ?? ($_GET['movement_date'] ?? ($lastCompletedLeg ? movement_trip_reference_date($lastCompletedLeg) : date('Y-m-d')));
$lastCompletedLegForDate = null;
if ($selectedVehicle && movement_is_valid_date((string) $selectedMovementDate)) {
    $lastCompletedForDateStmt = $pdo->prepare("
        SELECT *
        FROM trip_legs
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND COALESCE(arrival_date, movement_date) = ?
          AND record_status IN ('completed', 'locked')
        ORDER BY time_in DESC, id DESC
        LIMIT 1
    ");
    $lastCompletedForDateStmt->execute([$user['id'], $selectedVehicle['id'], $selectedMovementDate]);
    $lastCompletedLegForDate = $lastCompletedForDateStmt->fetch() ?: null;
}

$defaultSourceLeg = $lastCompletedLegForDate ?: $lastCompletedLeg;
$defaultSourceTimeOut = ($defaultSourceLeg && !empty($defaultSourceLeg['time_in']) && (string) $selectedMovementDate === movement_trip_reference_date($defaultSourceLeg))
    ? movement_short_time((string) $defaultSourceLeg['time_in'])
    : '';
$resolvedPostedPurpose = movement_resolve_purpose_input(
    (string) ($_POST['purpose_choice'] ?? ''),
    (string) ($_POST['purpose_other'] ?? '')
);
$seedPurpose = $resolvedPostedPurpose !== ''
    ? $resolvedPostedPurpose
    : (string) ($_GET['purpose'] ?? '');
$selectedPurposeOption = movement_match_purpose_option($seedPurpose);
$defaultTimeOut = $_POST['time_out']
    ?? ($_GET['time_out']
    ?? $defaultSourceTimeOut);

$startFormValues = [
    'movement_date' => $selectedMovementDate,
    'time_out' => $defaultTimeOut,
    'from_location' => $_POST['from_location'] ?? ($_GET['from_location'] ?? ($defaultSourceLeg['to_location'] ?? '')),
    'to_location' => $_POST['to_location'] ?? ($_GET['to_location'] ?? ''),
    'purpose' => $seedPurpose,
    'purpose_choice' => $_POST['purpose_choice'] ?? ($seedPurpose === '' ? '' : ($selectedPurposeOption ?? 'Other')),
    'purpose_other' => $_POST['purpose_other'] ?? ($selectedPurposeOption ? '' : $seedPurpose),
    'odometer_start_km' => $_POST['odometer_start_km'] ?? ($_GET['odometer_start_km'] ?? fleet_km_input_value($defaultSourceLeg['odometer_end_km'] ?? $latestKnown)),
    'confirmed_by_user_id' => $_POST['confirmed_by_user_id'] ?? ($_GET['confirmed_by_user_id'] ?? ($defaultSourceLeg['confirmed_by_user_id'] ?? '')),
    'passenger_name' => $_POST['passenger_name'] ?? ($_GET['passenger_name'] ?? ''),
];

$defaultCompleteDateTime = $inProgressLeg
    ? movement_shift_datetime((string) $inProgressLeg['movement_date'], (string) $inProgressLeg['time_out'], 5)
    : null;
$defaultCompleteArrivalDate = $_POST['arrival_date']
    ?? ($defaultCompleteDateTime['date'] ?? ($inProgressLeg['movement_date'] ?? date('Y-m-d')));
$defaultCompleteTimeIn = $_POST['time_in']
    ?? ($defaultCompleteDateTime['time'] ?? date('H:i'));
$defaultCompleteEndKm = fleet_km_input_value($latestKnown);
if ($inProgressLeg) {
    $inProgressStartKm = fleet_km_value($inProgressLeg['odometer_start_km']);
    $defaultCompleteEndKm = $latestKnown > $inProgressStartKm
        ? fleet_km_input_value($latestKnown)
        : fleet_km_input_value($inProgressStartKm + 1);
}

$completeFormValues = [
    'arrival_date' => $defaultCompleteArrivalDate,
    'time_in' => $defaultCompleteTimeIn,
    'odometer_end_km' => $_POST['odometer_end_km'] ?? $defaultCompleteEndKm,
];

fleet_render_shell_start(
    'Log Movement',
    'movement_leg',
    $user,
    'Start a leg when you leave, then complete it when you arrive. One movement leg is one point-to-point entry.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h2>No vehicle available</h2>';
    echo '<p>A movement leg can only be logged against an assigned or accessible vehicle. Once a vehicle is assigned, return here and the two-step form will be ready.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Select Vehicle</h2><p>Pick the vehicle you are logging for. Each movement is saved point to point, just like the paper logbook.</p></div>';
echo '<a class="inline-link-button" href="my_vehicle.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Back to My Vehicle</a>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i><span>' . fleet_h($vehicle['vehicle_name']) . ' &middot; ' . fleet_h($vehicle['number_plate']) . '</span></a>';
}
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="weekly-navigator">';
echo '<div class="week-badge"><i class="fas fa-calendar-week"></i><span>' . fleet_h(fleet_format_week_label($weekStart)) . '</span></div>';
echo '<div class="button-row">';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode((new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d')) . '">Previous Week</a>';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode(fleet_week_start_from_date(date('Y-m-d'))) . '">This Week</a>';
echo '<a class="button-secondary" href="log_movement_leg.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode((new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d')) . '">Next Week</a>';
echo '</div>';
echo '</div>';
echo '</section>';

if ($inProgressLeg && $openLegBelongsToDriver) {
    echo '<section id="complete-leg-panel" class="panel">';
    echo '<div class="panel-header-split">';
    echo '<div><h2>Complete In-Progress Leg</h2><p>Finish the open leg below when you reach the destination.</p></div>';
    echo '<span class="status-pill in_progress">In Progress</span>';
    echo '</div>';
    echo '<div class="detail-pairs">';
    echo '<div class="detail-pair"><span class="detail-pair-label">Departure Date</span><span class="detail-pair-value">' . fleet_h(date('d M Y', strtotime((string) $inProgressLeg['movement_date']))) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">Departure Time</span><span class="detail-pair-value">' . fleet_h(movement_display_time((string) $inProgressLeg['time_out'])) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">From</span><span class="detail-pair-value">' . fleet_h($inProgressLeg['from_location']) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">To</span><span class="detail-pair-value">' . fleet_h($inProgressLeg['to_location']) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">Purpose</span><span class="detail-pair-value">' . fleet_h($inProgressLeg['purpose']) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">Start KM</span><span class="detail-pair-value">' . movement_format_km($inProgressLeg['odometer_start_km']) . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">Passenger / Requester</span><span class="detail-pair-value">' . fleet_h(movement_trip_passenger_name($inProgressLeg) ?: 'Not captured') . '</span></div>';
    echo '<div class="detail-pair"><span class="detail-pair-label">Confirmed By</span><span class="detail-pair-value">' . fleet_h(movement_trip_confirmer_name($inProgressLeg) ?: 'Not captured') . '</span></div>';
    echo '</div>';
    echo '<form method="post" class="form-grid" novalidate style="margin-top:16px;">';
    echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
    echo '<input type="hidden" name="trip_leg_id" value="' . fleet_h($inProgressLeg['id']) . '">';
    echo '<input type="hidden" name="week_start" value="' . fleet_h($inProgressLeg['week_start_date']) . '">';
    echo '<input type="hidden" name="legs_page" value="' . fleet_h($legsPage) . '">';
    echo '<input type="hidden" name="movement_action" value="complete_leg">';
    echo '<div class="form-group span-4"><label for="arrival_date">Arrival Date</label><input id="arrival_date" type="date" name="arrival_date" value="' . fleet_h($completeFormValues['arrival_date']) . '" required></div>';
    echo '<div class="form-group span-4"><label for="time_in">Arrival Time</label><input id="time_in" type="time" name="time_in" value="' . fleet_h($completeFormValues['time_in']) . '" required></div>';
    echo '<div class="form-group span-4"><label for="odometer_end_km">End KM</label><input id="odometer_end_km" type="number" step="1" min="' . fleet_h(fleet_km_input_value(fleet_km_value($inProgressLeg['odometer_start_km']) + 1)) . '" name="odometer_end_km" placeholder="e.g. 12005" value="' . fleet_h($completeFormValues['odometer_end_km']) . '" data-start-km="' . fleet_h(fleet_km_input_value($inProgressLeg['odometer_start_km'])) . '" required></div>';
    echo '<div class="form-group span-4"><label for="total_km_preview">Total KM</label><input id="total_km_preview" type="text" value="0 km" readonly><div class="input-hint">Calculated when the leg is completed.</div></div>';
    echo '<div class="form-group span-12"><div class="button-row"><button class="button" type="submit">Complete Leg</button><a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode((string) $inProgressLeg['week_start_date']) . '">Review This Week</a></div></div>';
    echo '</form>';
    echo '</section>';
} elseif (!$inProgressLeg) {
    echo '<section id="start-leg-panel" class="panel">';
    echo '<div class="panel-header-split">';
    echo '<div><h2>Start New Leg</h2><p>Record only the details you know when leaving. Complete the leg later with arrival time and end KM.</p></div>';
    echo '<span class="status-pill draft">Ready</span>';
    echo '</div>';
    echo '<form method="post" class="form-grid" novalidate>';
    echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
    echo '<input type="hidden" name="week_start" value="' . fleet_h($weekStart) . '">';
    echo '<input type="hidden" name="legs_page" value="' . fleet_h($legsPage) . '">';
    echo '<input type="hidden" name="movement_action" value="start_leg">';
    echo '<div class="form-group span-4"><label for="movement_date">Departure Date</label><input id="movement_date" type="date" name="movement_date" value="' . fleet_h($startFormValues['movement_date']) . '" data-source-date="' . fleet_h((string) movement_trip_reference_date($defaultSourceLeg ?? [])) . '" data-source-time="' . fleet_h($defaultSourceTimeOut) . '" required></div>';
    echo '<div class="form-group span-4"><label for="time_out">Departure Time</label><input id="time_out" type="time" name="time_out" value="' . fleet_h($startFormValues['time_out']) . '" required></div>';
    echo '<div class="form-group span-4"><label for="odometer_start_km">Start KM</label><input id="odometer_start_km" type="number" step="1" min="0" name="odometer_start_km" placeholder="e.g. 12000" value="' . fleet_h($startFormValues['odometer_start_km']) . '" required><div class="input-hint">Must not be below ' . fleet_format_km($latestKnown) . '.</div></div>';
    echo '<div class="form-group span-6"><label for="from_location">From</label><input id="from_location" type="text" name="from_location" value="' . fleet_h($startFormValues['from_location']) . '" placeholder="Departure point" required></div>';
    echo '<div class="form-group span-6"><label for="to_location">To</label><input id="to_location" type="text" name="to_location" value="' . fleet_h($startFormValues['to_location']) . '" placeholder="Destination" required></div>';
    echo '<div id="purpose_choice_group" class="form-group span-4"><label for="purpose_choice">Purpose / Activity</label><select id="purpose_choice" name="purpose_choice" required>';
    echo '<option value="">Select purpose</option>';
    foreach (movement_purpose_options() as $purposeOption) {
        $selected = $startFormValues['purpose_choice'] === $purposeOption ? ' selected' : '';
        echo '<option value="' . fleet_h($purposeOption) . '"' . $selected . '>' . fleet_h($purposeOption) . '</option>';
    }
    echo '<option value="Other"' . ($startFormValues['purpose_choice'] === 'Other' ? ' selected' : '') . '>Other</option>';
    echo '</select></div>';
    echo '<div id="purpose_other_group" class="form-group span-12"' . ($startFormValues['purpose_choice'] === 'Other' ? '' : ' style="display:none;"') . '><label for="purpose_other">Other Purpose</label><input id="purpose_other" type="text" name="purpose_other" value="' . fleet_h($startFormValues['purpose_other']) . '" placeholder="Enter purpose" maxlength="80"' . ($startFormValues['purpose_choice'] === 'Other' ? ' required' : '') . '><div class="input-hint">Maximum 80 characters.</div></div>';
    echo '<div id="confirmed_by_group" class="form-group ' . ($startFormValues['purpose_choice'] === 'Other' ? 'span-6' : 'span-4') . '"><label for="confirmed_by_user_id">Confirmed By</label><select id="confirmed_by_user_id" name="confirmed_by_user_id" required>';
    echo '<option value="">Select admin</option>';
    foreach ($systemConfirmers as $confirmer) {
        $selected = (string) $startFormValues['confirmed_by_user_id'] === (string) $confirmer['id'] ? ' selected' : '';
        $title = fleet_role_label((string) ($confirmer['role'] ?? 'staff'));
        echo '<option value="' . fleet_h($confirmer['id']) . '" data-name="' . fleet_h($confirmer['name']) . '" data-title="' . fleet_h($title) . '" data-contact="' . fleet_h($confirmer['email'] ?? '') . '"' . $selected . '>' . fleet_h($confirmer['name']) . ' &middot; ' . fleet_h($title) . '</option>';
    }
    echo '</select><div class="input-hint">Choose the admin, super admin, or facility admin confirming this movement.</div></div>';
    echo '<div id="passenger_group" class="form-group ' . ($startFormValues['purpose_choice'] === 'Other' ? 'span-6' : 'span-4') . '"><label for="passenger_name">Passenger / Requesting Officer</label><input id="passenger_name" list="passenger_name_suggestions" type="text" name="passenger_name" value="' . fleet_h($startFormValues['passenger_name']) . '" placeholder="Start typing a name" required><datalist id="passenger_name_suggestions">';
    $seenPassengerSuggestions = [];
    foreach ($systemUsers as $systemUser) {
        $suggestedName = trim((string) ($systemUser['name'] ?? ''));
        if ($suggestedName === '') {
            continue;
        }
        $suggestionKey = strtolower($suggestedName);
        if (isset($seenPassengerSuggestions[$suggestionKey])) {
            continue;
        }
        $seenPassengerSuggestions[$suggestionKey] = true;
        echo '<option value="' . fleet_h($suggestedName) . '" label="' . fleet_h(fleet_role_label((string) ($systemUser['role'] ?? 'staff'))) . '"></option>';
    }
    echo '</datalist><div class="input-hint">Start typing to see matching users. You can still enter a name manually.</div></div>';
    echo '<div class="form-group span-12"><div class="button-row"><button class="button" type="submit">Start Leg</button><a class="button-secondary" href="weekly_liquidation.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '">Review This Week</a></div></div>';
    echo '</form>';
    echo '</section>';
}

echo '<section id="week-legs-section" class="panel">';
echo '<div class="panel-header"><div><h2>Current Week Movement Legs</h2><p>In-progress legs appear first so it is easy to see what still needs to be completed.</p></div></div>';
if (!$recentTrips) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-route"></i></div>';
    echo '<h3>No movement legs yet</h3>';
    echo '<p>Start the first point-to-point movement for this week. Once saved, it will appear here and flow into the weekly liquidation draft automatically.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($recentTrips as $trip) {
        $isInProgress = $trip['record_status'] === 'in_progress';
        $timeLabel = movement_trip_time_label($trip);
        $passengerLabel = movement_trip_passenger_name($trip);
        $confirmerLabel = movement_trip_confirmer_name($trip);
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($trip['from_location']) . ' to ' . fleet_h($trip['to_location']) . '</h3><p class="data-item-meta">' . fleet_h(movement_trip_date_label($trip)) . ' &middot; ' . fleet_h($timeLabel) . '</p></div>';
        echo '<span class="status-pill ' . fleet_h($trip['record_status']) . '">' . fleet_h(movement_status_label((string) $trip['record_status'])) . '</span>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Purpose</span><span class="detail-pair-value">' . fleet_h($trip['purpose']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Start KM</span><span class="detail-pair-value">' . movement_format_km($trip['odometer_start_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">End KM</span><span class="detail-pair-value">' . movement_format_km($trip['odometer_end_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Total KM</span><span class="detail-pair-value">' . movement_format_km($trip['total_km']) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Passenger / Requester</span><span class="detail-pair-value">' . fleet_h($passengerLabel ?: 'Not captured') . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Confirmed By</span><span class="detail-pair-value">' . fleet_h($confirmerLabel ?: 'Not captured') . '</span></div>';
        echo '</div>';
        if ($isInProgress && (int) $trip['driver_id'] === (int) $user['id']) {
            echo '<div class="button-row" style="margin-top:12px;"><a class="button-secondary" href="#complete-leg-panel">Complete This Leg</a></div>';
        }
        if ((int) $trip['driver_id'] === (int) $user['id']) {
            echo '<form method="post" style="margin-top:12px;" onsubmit="return confirm(\'Delete this movement leg for testing?\');">';
            echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
            echo '<input type="hidden" name="week_start" value="' . fleet_h($weekStart) . '">';
            echo '<input type="hidden" name="legs_page" value="' . fleet_h($legsPage) . '">';
            echo '<input type="hidden" name="trip_leg_id" value="' . fleet_h($trip['id']) . '">';
            echo '<input type="hidden" name="movement_action" value="delete_leg">';
            echo '<button class="button-secondary" type="submit">Delete</button>';
            echo '</form>';
        }
        if (!empty($trip['issue_notes'])) {
            echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($trip['issue_notes']) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
    if ($recentTripsTotalPages > 1) {
        echo '<nav class="pagination" aria-label="Movement leg pages">';
        echo '<div class="page-numbers">';
        if ($legsPage > 1) {
            echo '<a class="page-link" href="log_movement_leg.php?' . http_build_query([
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => $weekStart,
                'legs_page' => $legsPage - 1,
            ]) . '#week-legs-section">Previous</a>';
        }
        for ($pageNumber = 1; $pageNumber <= $recentTripsTotalPages; $pageNumber++) {
            echo '<a class="page-link' . ($pageNumber === $legsPage ? ' active' : '') . '" href="log_movement_leg.php?' . http_build_query([
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => $weekStart,
                'legs_page' => $pageNumber,
            ]) . '#week-legs-section">' . $pageNumber . '</a>';
        }
        if ($legsPage < $recentTripsTotalPages) {
            echo '<a class="page-link" href="log_movement_leg.php?' . http_build_query([
                'vehicle_id' => (string) $selectedVehicle['id'],
                'week_start' => $weekStart,
                'legs_page' => $legsPage + 1,
            ]) . '#week-legs-section">Next</a>';
        }
        echo '</div>';
        echo '<span class="helper-text">Showing ' . count($recentTrips) . ' of ' . $recentTripsTotal . ' leg(s).</span>';
        echo '</nav>';
    }
}
echo '</section>';

echo '<script>';
echo 'const movementDateInput=document.getElementById("movement_date");';
echo 'const timeOutInput=document.getElementById("time_out");';
echo 'if(movementDateInput&&timeOutInput){const sourceDate=movementDateInput.dataset.sourceDate||"";const sourceTime=movementDateInput.dataset.sourceTime||"";movementDateInput.addEventListener("change",function(){if(movementDateInput.value&&sourceDate&&movementDateInput.value===sourceDate&&sourceTime){timeOutInput.value=sourceTime;return;}timeOutInput.value="";});}';
echo 'const purposeChoiceInput=document.getElementById("purpose_choice");';
echo 'const purposeOtherGroup=document.getElementById("purpose_other_group");';
echo 'const purposeOtherInput=document.getElementById("purpose_other");';
echo 'const confirmedByGroup=document.getElementById("confirmed_by_group");';
echo 'const passengerGroup=document.getElementById("passenger_group");';
echo 'if(purposeChoiceInput&&purposeOtherGroup&&purposeOtherInput&&confirmedByGroup&&passengerGroup){const togglePurposeOther=function(){const isOther=purposeChoiceInput.value==="Other";purposeOtherGroup.style.display=isOther?"":"none";purposeOtherInput.required=isOther;confirmedByGroup.className="form-group "+(isOther?"span-6":"span-4");passengerGroup.className="form-group "+(isOther?"span-6":"span-4");};purposeChoiceInput.addEventListener("change",togglePurposeOther);togglePurposeOther();}';
echo 'const endKmInput=document.getElementById("odometer_end_km");';
echo 'const totalKmPreview=document.getElementById("total_km_preview");';
echo 'if(endKmInput&&totalKmPreview){const startKm=parseInt(endKmInput.dataset.startKm||"0",10)||0;const updateTripKmPreview=function(){const end=parseInt(endKmInput.value||"0",10)||0;const total=Math.max(0,end-startKm);totalKmPreview.value=total.toLocaleString("en-US")+" km";};endKmInput.addEventListener("input",updateTripKmPreview);updateTripKmPreview();}';
echo '</script>';

fleet_render_shell_end();
