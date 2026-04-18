<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/facility_auth.php';

const FLEET_REQUIRED_TABLES = [
    'roles',
    'card_accounts',
    'fuel_prices',
    'trip_legs',
    'fuel_purchases',
    'weekly_liquidations',
    'weekly_liquidation_items',
    'edit_requests',
    'attachments',
];

function fleet_pdo(): PDO
{
    global $pdo;

    return $pdo;
}

function fleet_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fleet_currency($value): string
{
    return 'K ' . number_format((float) $value, 2);
}

function fleet_km_value($value): int
{
    if ($value === null || $value === '') {
        return 0;
    }

    return (int) round((float) $value);
}

function fleet_km_input_value($value): string
{
    return (string) fleet_km_value($value);
}

function fleet_format_km($value, bool $withSuffix = true): string
{
    if ($value === null || $value === '') {
        return '--';
    }

    $formatted = number_format(fleet_km_value($value));

    return $withSuffix ? $formatted . ' km' : $formatted;
}

function fleet_is_valid_km_input($value): bool
{
    return preg_match('/^\d+$/', trim((string) $value)) === 1;
}

function fleet_card_limit_value(?array $vehicle = null, ?array $cardAccount = null): float
{
    if ($cardAccount && isset($cardAccount['card_limit']) && (float) $cardAccount['card_limit'] > 0) {
        return round((float) $cardAccount['card_limit'], 2);
    }

    if ($vehicle && isset($vehicle['float_limit'])) {
        return round((float) $vehicle['float_limit'], 2);
    }

    return 0.0;
}

function fleet_card_balance_value(?array $vehicle = null, ?array $cardAccount = null): float
{
    if ($vehicle && isset($vehicle['float_balance'])) {
        return round((float) $vehicle['float_balance'], 2);
    }

    if ($cardAccount && isset($cardAccount['current_balance'])) {
        return round((float) $cardAccount['current_balance'], 2);
    }

    return 0.0;
}

function fleet_card_remaining_percent(float $balance, float $limit): float
{
    if ($limit <= 0) {
        return 0.0;
    }

    return round(($balance / $limit) * 100, 2);
}

function fleet_card_threshold_key(float $balance, float $limit): string
{
    if ($limit <= 0) {
        return 'healthy';
    }

    $remainingPercent = fleet_card_remaining_percent($balance, $limit);

    if ($remainingPercent < 45) {
        return 'needs_top_up';
    }

    if ($remainingPercent <= 55) {
        return 'refill_soon';
    }

    return 'healthy';
}

function fleet_card_threshold_label(string $thresholdKey): string
{
    return match ($thresholdKey) {
        'needs_top_up' => 'Needs Top-Up',
        'refill_soon' => 'Refill Soon',
        default => 'Healthy',
    };
}

function fleet_card_threshold_class(string $thresholdKey): string
{
    return match ($thresholdKey) {
        'needs_top_up' => 'is-warning',
        'refill_soon' => 'is-pending',
        default => 'is-positive',
    };
}

function fleet_suggested_replenishment(float $balance, float $limit): float
{
    if ($limit <= 0) {
        return 0.0;
    }

    return round(max(0, $limit - $balance), 2);
}

function fleet_vehicle_card_context(array $vehicle, ?array $cardAccount = null): array
{
    $balance = fleet_card_balance_value($vehicle, $cardAccount);
    $limit = fleet_card_limit_value($vehicle, $cardAccount);
    $thresholdKey = fleet_card_threshold_key($balance, $limit);
    $remainingPercent = fleet_card_remaining_percent($balance, $limit);

    return [
        'balance' => $balance,
        'limit' => $limit,
        'remaining_percent' => $remainingPercent,
        'threshold_key' => $thresholdKey,
        'threshold_label' => fleet_card_threshold_label($thresholdKey),
        'threshold_class' => fleet_card_threshold_class($thresholdKey),
        'suggested_replenishment' => fleet_suggested_replenishment($balance, $limit),
    ];
}

function fleet_ensure_vehicle_card_account(PDO $pdo, array $vehicle, ?int $createdBy = null): ?array
{
    $vehicleId = (int) ($vehicle['id'] ?? 0);
    if ($vehicleId <= 0) {
        return null;
    }

    $lookupStmt = $pdo->prepare("
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
    $lookupStmt->execute([$vehicleId]);
    $existing = $lookupStmt->fetch() ?: null;

    $accountName = trim((string) ($vehicle['float_account_name'] ?? ''));
    if ($accountName === '') {
        $vehicleName = trim((string) ($vehicle['vehicle_name'] ?? 'Vehicle'));
        $plateNumber = trim((string) ($vehicle['number_plate'] ?? ''));
        $accountName = $plateNumber !== '' ? $vehicleName . ' (' . $plateNumber . ')' : $vehicleName;
    }

    $accountCode = 'vehicle-' . $vehicleId;
    $fuelType = !empty($vehicle['fuel_type']) ? strtolower((string) $vehicle['fuel_type']) : null;
    $facilityId = isset($vehicle['facility_id']) && $vehicle['facility_id'] !== '' ? (int) $vehicle['facility_id'] : null;
    $balance = round((float) ($vehicle['float_balance'] ?? 0), 2);

    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE card_accounts
            SET facility_id = ?,
                account_code = ?,
                account_name = ?,
                provider = 'TOM',
                fuel_type = ?,
                current_balance = ?,
                legacy_float_account_name = ?,
                status = CASE WHEN status = 'blocked' THEN status ELSE 'active' END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updateStmt->execute([
            $facilityId,
            $accountCode,
            $accountName,
            $fuelType,
            $balance,
            $accountName,
            (int) $existing['id'],
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO card_accounts (
                facility_id,
                vehicle_id,
                account_code,
                account_name,
                provider,
                fuel_type,
                opening_balance,
                current_balance,
                legacy_float_account_name,
                status,
                created_by
            ) VALUES (?, ?, ?, ?, 'TOM', ?, ?, ?, ?, 'active', ?)
        ");
        $insertStmt->execute([
            $facilityId,
            $vehicleId,
            $accountCode,
            $accountName,
            $fuelType,
            $balance,
            $balance,
            $accountName,
            $createdBy,
        ]);
    }

    $lookupStmt->execute([$vehicleId]);
    return $lookupStmt->fetch() ?: null;
}

function fleet_current_user_context(): array
{
    $role = normalizeRole($_SESSION['user_role'] ?? 'driver');

    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => $_SESSION['user_name'] ?? 'User',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $role,
        'facility_id' => getUserProvinceId(),
        'province_id' => getUserProvinceId(),
        'is_super_admin' => isFleetManager(),
        'is_facility_admin' => isProvincialAdmin(),
    ];
}

function fleet_role_label(string $role, bool $isFacilityAdmin = false): string
{
    $normalizedRole = normalizeRole($role);

    return match ($normalizedRole) {
        'fleet_manager' => 'Fleet Manager',
        'provincial_admin' => 'Provincial Admin',
        default => 'Driver',
    };
}

function fleet_is_driver(array $user): bool
{
    return ($user['role'] ?? '') === 'driver';
}

function fleet_is_fleet_overview_user(array $user): bool
{
    return in_array($user['role'] ?? '', ['provincial_admin', 'fleet_manager'], true);
}

function fleet_is_driver_flow_user(array $user): bool
{
    return in_array($user['role'], ['driver', 'provincial_admin', 'fleet_manager'], true);
}

function fleet_is_reviewer(array $user): bool
{
    return in_array($user['role'], ['provincial_admin', 'fleet_manager'], true);
}

function fleet_require_vehicle_hub_access(array $user): void
{
    if (fleet_is_driver_flow_user($user)) {
        return;
    }

    $_SESSION['error_message'] = 'You do not have access to the vehicle workspace.';
    header('Location: dashboard.php');
    exit();
}

function fleet_require_driver_access(array $user): void
{
    if (fleet_is_driver($user)) {
        return;
    }

    $_SESSION['error_message'] = 'This page is only available to drivers.';
    header('Location: dashboard.php');
    exit();
}

function fleet_require_reviewer_access(array $user): void
{
    if (fleet_is_reviewer($user)) {
        return;
    }

    $_SESSION['error_message'] = 'You do not have access to reconciliation review.';
    header('Location: dashboard.php');
    exit();
}

function fleet_missing_tables(PDO $pdo): array
{
    static $missing;

    if ($missing !== null) {
        return $missing;
    }

    $placeholders = implode(',', array_fill(0, count(FLEET_REQUIRED_TABLES), '?'));
    $stmt = $pdo->prepare("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ($placeholders)
    ");
    $stmt->execute(FLEET_REQUIRED_TABLES);
    $present = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_values(array_diff(FLEET_REQUIRED_TABLES, $present));

    return $missing;
}

function fleet_schema_ready(PDO $pdo): bool
{
    return fleet_missing_tables($pdo) === [];
}

function fleet_set_flash(string $type, string $message): void
{
    $_SESSION['fleet_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function fleet_consume_flash(): ?array
{
    if (!isset($_SESSION['fleet_flash'])) {
        return null;
    }

    $flash = $_SESSION['fleet_flash'];
    unset($_SESSION['fleet_flash']);

    return $flash;
}

function fleet_week_start_from_date(?string $date = null): string
{
    $base = $date ?: date('Y-m-d');
    $day = new DateTimeImmutable($base);
    $dayOfWeek = (int) $day->format('w');

    return $day->modify('-' . $dayOfWeek . ' days')->format('Y-m-d');
}

function fleet_week_end_from_start(string $weekStart): string
{
    return (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
}

function fleet_format_week_label(string $weekStart): string
{
    $start = new DateTimeImmutable($weekStart);
    $end = $start->modify('+6 days');

    return $start->format('d M Y') . ' to ' . $end->format('d M Y');
}

function fleet_get_accessible_vehicles(PDO $pdo, array $user): array
{
    $params = [':driver_user_id' => $user['id']];
    $sql = "
        SELECT DISTINCT
            v.id,
            v.vehicle_name,
            v.number_plate,
            v.facility_id,
            v.asset_type,
            v.fuel_type,
            v.make,
            v.model,
            v.float_balance,
            COALESCE(v.float_limit, 0) AS float_limit,
            v.float_account_name,
            v.current_mileage,
            v.opening_odometer_km,
            v.vehicle_status,
            f.facility_name,
            f.province_name,
            COALESCE(va.assignment_type, 'primary') AS assignment_type
        FROM vehicles v
        LEFT JOIN facilities f
            ON f.id = v.facility_id
        LEFT JOIN vehicle_assignments va
            ON va.vehicle_id = v.id
           AND va.user_id = :driver_user_id
           AND (va.is_active = 1 OR va.assignment_status = 'active')
        WHERE v.vehicle_status <> 'retired'
    ";

    if ($user['is_super_admin']) {
        $sql .= " ORDER BY v.vehicle_name, v.number_plate";
    } elseif (($user['role'] ?? '') === 'provincial_admin') {
        $sql .= " AND v.facility_id = :facility_id ORDER BY v.vehicle_name, v.number_plate";
        $params[':facility_id'] = $user['facility_id'];
    } else {
        $sql .= "
            AND (
                va.user_id IS NOT NULL
                OR v.current_driver_id = :current_driver_id
            )
            ORDER BY
                CASE COALESCE(va.assignment_type, 'primary')
                    WHEN 'primary' THEN 0
                    WHEN 'shared' THEN 1
                    ELSE 2
                END,
                v.vehicle_name,
                v.number_plate
        ";
        $params[':current_driver_id'] = $user['id'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fleet_pick_vehicle(array $vehicles, ?int $vehicleId): ?array
{
    if ($vehicleId !== null) {
        foreach ($vehicles as $vehicle) {
            if ((int) $vehicle['id'] === $vehicleId) {
                return $vehicle;
            }
        }
    }

    return $vehicles[0] ?? null;
}

function fleet_find_weekly_liquidation(PDO $pdo, int $driverId, int $vehicleId, string $weekStart): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM weekly_liquidations
        WHERE driver_id = ?
          AND vehicle_id = ?
          AND week_start_date = ?
        LIMIT 1
    ");
    $stmt->execute([$driverId, $vehicleId, $weekStart]);

    return $stmt->fetch() ?: null;
}

function fleet_sync_weekly_records(PDO $pdo, int $weeklyId, int $driverId, int $vehicleId, string $weekStart): void
{
    $tripStmt = $pdo->prepare("
        INSERT IGNORE INTO weekly_liquidation_items (
            weekly_liquidation_id,
            item_type,
            trip_leg_id,
            sort_order
        )
        SELECT ?, 'trip_leg', tl.id, tl.id
        FROM trip_legs tl
        WHERE tl.driver_id = ?
          AND tl.vehicle_id = ?
          AND tl.week_start_date = ?
          AND tl.record_status <> 'voided'
    ");
    $tripStmt->execute([$weeklyId, $driverId, $vehicleId, $weekStart]);

    $fuelStmt = $pdo->prepare("
        INSERT IGNORE INTO weekly_liquidation_items (
            weekly_liquidation_id,
            item_type,
            fuel_purchase_id,
            sort_order
        )
        SELECT ?, 'fuel_purchase', fp.id, fp.id
        FROM fuel_purchases fp
        WHERE fp.driver_id = ?
          AND fp.vehicle_id = ?
          AND fp.week_start_date = ?
          AND fp.record_status <> 'voided'
    ");
    $fuelStmt->execute([$weeklyId, $driverId, $vehicleId, $weekStart]);
}

function fleet_ensure_weekly_liquidation(PDO $pdo, int $driverId, int $vehicleId, ?int $facilityId, string $weekStart): array
{
    $existing = fleet_find_weekly_liquidation($pdo, $driverId, $vehicleId, $weekStart);
    if ($existing) {
        fleet_sync_weekly_records($pdo, (int) $existing['id'], $driverId, $vehicleId, $weekStart);
        return $existing;
    }

    $stmt = $pdo->prepare("
        INSERT INTO weekly_liquidations (
            driver_id,
            vehicle_id,
            facility_id,
            week_start_date,
            week_end_date,
            status
        ) VALUES (?, ?, ?, ?, ?, 'draft')
    ");
    $stmt->execute([
        $driverId,
        $vehicleId,
        $facilityId,
        $weekStart,
        fleet_week_end_from_start($weekStart),
    ]);

    $weekly = fleet_find_weekly_liquidation($pdo, $driverId, $vehicleId, $weekStart) ?: [];
    if ($weekly) {
        fleet_sync_weekly_records($pdo, (int) $weekly['id'], $driverId, $vehicleId, $weekStart);
    }

    return $weekly;
}

function fleet_attach_item_to_weekly(PDO $pdo, int $weeklyId, string $itemType, int $recordId): void
{
    if ($itemType === 'trip_leg') {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO weekly_liquidation_items (
                weekly_liquidation_id,
                item_type,
                trip_leg_id,
                sort_order
            ) VALUES (?, 'trip_leg', ?, ?)
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO weekly_liquidation_items (
                weekly_liquidation_id,
                item_type,
                fuel_purchase_id,
                sort_order
            ) VALUES (?, 'fuel_purchase', ?, ?)
        ");
    }

    $stmt->execute([$weeklyId, $recordId, $recordId]);
}

function fleet_fetch_pending_trip_legs(PDO $pdo, int $driverId, int $vehicleId, string $weekStart): array
{
    $stmt = $pdo->prepare("
        SELECT
            tl.*,
            u.name AS driver_name
        FROM trip_legs tl
        LEFT JOIN users u
            ON u.id = tl.driver_id
        WHERE tl.driver_id = ?
          AND tl.vehicle_id = ?
          AND tl.week_start_date = ?
          AND tl.record_status <> 'voided'
        ORDER BY
            CASE WHEN tl.record_status = 'in_progress' THEN 0 ELSE 1 END,
            tl.movement_date DESC,
            tl.time_out DESC,
            tl.id DESC
    ");
    $stmt->execute([$driverId, $vehicleId, $weekStart]);

    return $stmt->fetchAll();
}

function fleet_fetch_pending_fuel_purchases(PDO $pdo, int $driverId, int $vehicleId, string $weekStart): array
{
    $stmt = $pdo->prepare("
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
    ");
    $stmt->execute([$driverId, $vehicleId, $weekStart]);

    return $stmt->fetchAll();
}

function fleet_fetch_weekly_item_selection(PDO $pdo, int $weeklyId): array
{
    $tripStmt = $pdo->prepare("
        SELECT trip_leg_id
        FROM weekly_liquidation_items
        WHERE weekly_liquidation_id = ?
          AND item_type = 'trip_leg'
          AND trip_leg_id IS NOT NULL
    ");
    $tripStmt->execute([$weeklyId]);
    $tripIds = array_map('intval', $tripStmt->fetchAll(PDO::FETCH_COLUMN));

    $fuelStmt = $pdo->prepare("
        SELECT fuel_purchase_id
        FROM weekly_liquidation_items
        WHERE weekly_liquidation_id = ?
          AND item_type = 'fuel_purchase'
          AND fuel_purchase_id IS NOT NULL
    ");
    $fuelStmt->execute([$weeklyId]);
    $fuelIds = array_map('intval', $fuelStmt->fetchAll(PDO::FETCH_COLUMN));

    return [
        'trip_leg_ids' => $tripIds,
        'fuel_purchase_ids' => $fuelIds,
    ];
}

function fleet_replace_weekly_item_selection(PDO $pdo, int $weeklyId, array $tripLegIds, array $fuelPurchaseIds): void
{
    $tripLegIds = array_values(array_unique(array_map('intval', $tripLegIds)));
    $fuelPurchaseIds = array_values(array_unique(array_map('intval', $fuelPurchaseIds)));

    $deleteStmt = $pdo->prepare("DELETE FROM weekly_liquidation_items WHERE weekly_liquidation_id = ?");
    $deleteStmt->execute([$weeklyId]);

    foreach ($tripLegIds as $tripLegId) {
        if ($tripLegId > 0) {
            fleet_attach_item_to_weekly($pdo, $weeklyId, 'trip_leg', $tripLegId);
        }
    }

    foreach ($fuelPurchaseIds as $fuelPurchaseId) {
        if ($fuelPurchaseId > 0) {
            fleet_attach_item_to_weekly($pdo, $weeklyId, 'fuel_purchase', $fuelPurchaseId);
        }
    }
}

function fleet_get_latest_vehicle_odometer(PDO $pdo, int $vehicleId): int
{
    $stmt = $pdo->prepare("
        SELECT MAX(reading_value) AS latest_reading
        FROM (
            SELECT MAX(odometer_end_km) AS reading_value
            FROM trip_legs
            WHERE vehicle_id = ?
              AND record_status <> 'voided'

            UNION ALL

            SELECT MAX(odometer_at_refill_km) AS reading_value
            FROM fuel_purchases
            WHERE vehicle_id = ?
              AND record_status <> 'voided'

            UNION ALL

            SELECT MAX(COALESCE(current_mileage, opening_odometer_km, 0))
            FROM vehicles
            WHERE id = ?
        ) readings
    ");
    $stmt->execute([$vehicleId, $vehicleId, $vehicleId]);

    return fleet_km_value($stmt->fetchColumn() ?: 0);
}

function fleet_fetch_confirmable_users(PDO $pdo, ?int $facilityId = null): array
{
    if ($facilityId) {
        $stmt = $pdo->prepare("
            SELECT id, name, email, role
            FROM users
            WHERE facility_id = ?
            ORDER BY name
        ");
        $stmt->execute([$facilityId]);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->query("
        SELECT id, name, email, role
        FROM users
        ORDER BY name
    ");

    return $stmt->fetchAll();
}

function fleet_fetch_confirmation_users(PDO $pdo, ?int $facilityId = null): array
{
    if ($facilityId) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT id, name, email, role
            FROM users
            WHERE user_status = 'active'
              AND (
                    is_super_admin = 1
                    OR role IN ('super_admin', 'admin', 'facility_admin')
                    OR (facility_id = ? AND is_facility_admin = 1)
                  )
            ORDER BY name
        ");
        $stmt->execute([$facilityId]);

        return $stmt->fetchAll();
    }

    $stmt = $pdo->query("
        SELECT id, name, email, role
        FROM users
        WHERE user_status = 'active'
          AND (
                is_super_admin = 1
                OR is_facility_admin = 1
                OR role IN ('facility_admin', 'admin', 'super_admin')
              )
        ORDER BY name
    ");

    return $stmt->fetchAll();
}

function fleet_find_in_progress_trip_leg(PDO $pdo, int $vehicleId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            tl.*,
            u.name AS driver_name
        FROM trip_legs tl
        LEFT JOIN users u
            ON u.id = tl.driver_id
        WHERE tl.vehicle_id = ?
          AND tl.record_status = 'in_progress'
        ORDER BY tl.movement_date DESC, tl.time_out DESC, tl.id DESC
        LIMIT 1
    ");
    $stmt->execute([$vehicleId]);

    return $stmt->fetch() ?: null;
}

function fleet_recalculate_weekly(PDO $pdo, int $weeklyId): array
{
    $tripStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_trip_legs,
            COALESCE(SUM(tl.total_km), 0) AS total_km,
            COALESCE(SUM(CASE WHEN tl.has_issues = 1 THEN 1 ELSE 0 END), 0) AS trip_issue_count,
            COALESCE(SUM(CASE WHEN tl.record_status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_trip_count
        FROM weekly_liquidation_items wli
        JOIN trip_legs tl
            ON tl.id = wli.trip_leg_id
        WHERE wli.weekly_liquidation_id = ?
          AND wli.item_type = 'trip_leg'
          AND tl.record_status <> 'voided'
    ");
    $tripStmt->execute([$weeklyId]);
    $trip = $tripStmt->fetch() ?: [
        'total_trip_legs' => 0,
        'total_km' => 0,
        'trip_issue_count' => 0,
        'in_progress_trip_count' => 0,
    ];

    $fuelStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_fuel_purchases,
            COALESCE(SUM(fp.litres), 0) AS total_fuel_litres,
            COALESCE(SUM(fp.amount), 0) AS total_fuel_amount,
            COALESCE(SUM(CASE WHEN fp.has_issues = 1 THEN 1 ELSE 0 END), 0) AS fuel_issue_count
        FROM weekly_liquidation_items wli
        JOIN fuel_purchases fp
            ON fp.id = wli.fuel_purchase_id
        WHERE wli.weekly_liquidation_id = ?
          AND wli.item_type = 'fuel_purchase'
          AND fp.record_status <> 'voided'
    ");
    $fuelStmt->execute([$weeklyId]);
    $fuel = $fuelStmt->fetch() ?: [
        'total_fuel_purchases' => 0,
        'total_fuel_litres' => 0,
        'total_fuel_amount' => 0,
        'fuel_issue_count' => 0,
    ];

    $receiptStmt = $pdo->prepare("
        SELECT COUNT(*) AS missing_receipts
        FROM weekly_liquidation_items wli
        JOIN fuel_purchases fp
            ON fp.id = wli.fuel_purchase_id
        LEFT JOIN attachments a
            ON a.fuel_purchase_id = fp.id
           AND a.attachment_type = 'receipt'
        WHERE wli.weekly_liquidation_id = ?
          AND wli.item_type = 'fuel_purchase'
          AND fp.record_status <> 'voided'
          AND a.id IS NULL
    ");
    $receiptStmt->execute([$weeklyId]);
    $missingReceipts = (int) ($receiptStmt->fetchColumn() ?: 0);

    $issueNotes = [];
    $issueCount = (int) $trip['trip_issue_count'] + (int) $trip['in_progress_trip_count'] + (int) $fuel['fuel_issue_count'] + $missingReceipts;

    if ((int) $trip['in_progress_trip_count'] > 0) {
        $issueNotes[] = $trip['in_progress_trip_count'] . ' movement leg(s) still in progress';
    }
    if ((int) $trip['trip_issue_count'] > 0) {
        $issueNotes[] = $trip['trip_issue_count'] . ' movement leg(s) need attention';
    }
    if ((int) $fuel['fuel_issue_count'] > 0) {
        $issueNotes[] = $fuel['fuel_issue_count'] . ' fuel purchase(s) need attention';
    }
    if ($missingReceipts > 0) {
        $issueNotes[] = $missingReceipts . ' fuel receipt(s) missing';
    }

    $updateStmt = $pdo->prepare("
        UPDATE weekly_liquidations
        SET total_trip_legs = ?,
            total_km = ?,
            total_fuel_litres = ?,
            total_fuel_amount = ?,
            has_issues = ?,
            issue_notes = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $updateStmt->execute([
        (int) $trip['total_trip_legs'],
        (float) $trip['total_km'],
        (float) $fuel['total_fuel_litres'],
        (float) $fuel['total_fuel_amount'],
        $issueCount > 0 ? 1 : 0,
        $issueNotes ? implode('; ', $issueNotes) : null,
        $weeklyId,
    ]);

    $weeklyStmt = $pdo->prepare("SELECT * FROM weekly_liquidations WHERE id = ?");
    $weeklyStmt->execute([$weeklyId]);
    $weekly = $weeklyStmt->fetch() ?: [];

    $weekly['total_fuel_purchases'] = (int) $fuel['total_fuel_purchases'];
    $weekly['missing_receipts'] = $missingReceipts;
    $weekly['trip_issue_count'] = (int) $trip['trip_issue_count'];
    $weekly['in_progress_trip_count'] = (int) $trip['in_progress_trip_count'];
    $weekly['fuel_issue_count'] = (int) $fuel['fuel_issue_count'];

    return $weekly;
}

function fleet_fetch_weekly_trip_legs(PDO $pdo, int $weeklyId): array
{
    $stmt = $pdo->prepare("
        SELECT
            tl.*,
            v.vehicle_name,
            v.number_plate
        FROM weekly_liquidation_items wli
        JOIN trip_legs tl
            ON tl.id = wli.trip_leg_id
        JOIN vehicles v
            ON v.id = tl.vehicle_id
        WHERE wli.weekly_liquidation_id = ?
          AND wli.item_type = 'trip_leg'
        ORDER BY
            CASE WHEN tl.record_status = 'in_progress' THEN 0 ELSE 1 END,
            tl.movement_date DESC,
            tl.time_out DESC,
            tl.id DESC
    ");
    $stmt->execute([$weeklyId]);

    return $stmt->fetchAll();
}

function fleet_fetch_weekly_fuel_purchases(PDO $pdo, int $weeklyId): array
{
    $stmt = $pdo->prepare("
        SELECT
            fp.*,
            v.vehicle_name,
            v.number_plate,
            ca.account_name,
            a.id AS receipt_attachment_id
        FROM weekly_liquidation_items wli
        JOIN fuel_purchases fp
            ON fp.id = wli.fuel_purchase_id
        JOIN vehicles v
            ON v.id = fp.vehicle_id
        LEFT JOIN card_accounts ca
            ON ca.id = fp.card_account_id
        LEFT JOIN attachments a
            ON a.fuel_purchase_id = fp.id
           AND a.attachment_type = 'receipt'
        WHERE wli.weekly_liquidation_id = ?
          AND wli.item_type = 'fuel_purchase'
        ORDER BY fp.purchase_date DESC, fp.id DESC
    ");
    $stmt->execute([$weeklyId]);

    return $stmt->fetchAll();
}

function fleet_week_is_editable(?array $weekly): bool
{
    if (!$weekly) {
        return true;
    }

    return in_array($weekly['status'], ['draft', 'returned'], true);
}

function fleet_render_shell_start(string $title, string $activeNav, array $user, string $subtitle = ''): void
{
    $flash = fleet_consume_flash();
    $cssVersion = @filemtime(__DIR__ . '/assets/css/fleet_redesign.css') ?: time();
    $roleLabel = fleet_role_label($user['role'], $user['is_facility_admin']);
    $userDisplayName = $user['role'] === 'fleet_manager' ? 'Fleet Manager' : (string) $user['name'];
    $userDisplayRole = $user['role'] === 'fleet_manager' ? '' : $roleLabel;
    $brandTitle = 'Driver Hub';
    $myVehicleLabel = 'My Vehicle';
    $navItems = [];

    if (fleet_is_driver($user)) {
        $navItems = [
            ['key' => 'my_vehicle', 'href' => 'my_vehicle.php', 'icon' => 'fa-car-side', 'label' => 'My Vehicle'],
            ['key' => 'reports', 'href' => 'reports.php', 'icon' => 'fa-chart-line', 'label' => 'Reports'],
            ['key' => 'movement_leg', 'href' => 'log_movement_leg.php', 'icon' => 'fa-route', 'label' => 'Log Movement'],
            ['key' => 'fuel_purchase', 'href' => 'record_fuel_purchase.php', 'icon' => 'fa-receipt', 'label' => 'Record Fuel Purchase'],
            ['key' => 'pending_reconciliations', 'href' => 'pending_reconciliations.php', 'icon' => 'fa-clipboard-check', 'label' => 'Pending Reconciliations'],
        ];
    } else {
        $brandTitle = $user['role'] === 'fleet_manager' ? 'Fleet Hub' : 'Province Hub';
        $myVehicleLabel = 'My Fleet';
        $navItems[] = ['key' => 'my_vehicle', 'href' => 'my_vehicle.php', 'icon' => 'fa-car-side', 'label' => $myVehicleLabel];
    }

    if (fleet_is_reviewer($user)) {
        $navItems[] = [
            'key' => 'province_liquidation',
            'href' => 'province_liquidation.php',
            'icon' => 'fa-user-check',
            'label' => 'Reconciliation Review',
        ];
    }

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . fleet_h($title) . '</title>';
    require __DIR__ . '/favicon_links.php';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
    echo '<link rel="stylesheet" href="assets/css/fleet_redesign.css?v=' . urlencode((string) $cssVersion) . '">';
    echo '</head>';
    echo '<body>';
    echo '<div class="workflow-shell">';
    echo '<aside class="workflow-sidebar">';
    echo '<div class="workflow-brand">';
    echo '<button class="workflow-brand-mark workflow-sidebar-toggle" type="button" aria-label="Toggle sidebar" aria-expanded="true" data-sidebar-toggle>';
    echo '<i class="fas fa-bars"></i>';
    echo '</button>';
    echo '<div class="workflow-brand-copy">';
    echo '<div class="workflow-brand-title">' . fleet_h($brandTitle) . '</div>';
    echo '</div>';
    echo '</div>';
    echo '<div class="workflow-user">';
    echo '<div class="workflow-user-avatar"><i class="fas fa-user"></i></div>';
    echo '<div class="workflow-user-copy">';
    echo '<div class="workflow-user-name">' . fleet_h($userDisplayName) . '</div>';
    if ($userDisplayRole !== '') {
        echo '<div class="workflow-user-role">' . fleet_h($userDisplayRole) . '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '<nav class="workflow-nav">';
    foreach ($navItems as $item) {
        $classes = $item['key'] === $activeNav ? 'workflow-nav-link active' : 'workflow-nav-link';
        echo '<a class="' . $classes . '" href="' . fleet_h($item['href']) . '" title="' . fleet_h($item['label']) . '">';
        echo '<i class="fas ' . fleet_h($item['icon']) . '"></i>';
        echo '<span>' . fleet_h($item['label']) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
    echo '<div class="workflow-sidebar-footer">';
    if (!fleet_is_driver($user)) {
        echo '<a class="workflow-nav-link subtle" href="dashboard.php" title="Back to Dashboard"><i class="fas fa-arrow-left"></i><span>Back to Dashboard</span></a>';
    }
    echo '<a class="workflow-nav-link subtle" href="logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>';
    echo '</div>';
    echo '</aside>';
    echo '<main class="workflow-main">';
    echo '<header class="page-header">';
    echo '<div>';
    echo '<h1>' . fleet_h($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="page-subtitle">' . fleet_h($subtitle) . '</p>';
    }
    echo '</div>';
    echo '</header>';

    if ($flash) {
        echo '<div class="alert alert-' . fleet_h($flash['type']) . '">';
        echo '<i class="fas ' . ($flash['type'] === 'success' ? 'fa-circle-check' : ($flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info')) . '"></i>';
        echo '<span>' . fleet_h($flash['message']) . '</span>';
        echo '</div>';
    }
}

function fleet_render_shell_end(): void
{
    echo '<script>';
    echo '(function () {';
    echo 'var shell = document.querySelector(".workflow-shell");';
    echo 'var toggle = document.querySelector("[data-sidebar-toggle]");';
    echo 'if (!shell || !toggle) { return; }';
    echo 'var storageKey = "fleetSidebarCollapsed";';
    echo 'var desktopQuery = window.matchMedia("(min-width: 921px)");';
    echo 'function applyCollapsed(collapsed, persist) {';
    echo 'if (!desktopQuery.matches) { shell.classList.remove("sidebar-collapsed"); toggle.setAttribute("aria-expanded", "true"); return; }';
    echo 'shell.classList.toggle("sidebar-collapsed", collapsed);';
    echo 'toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");';
    echo 'if (persist) { try { window.localStorage.setItem(storageKey, collapsed ? "1" : "0"); } catch (error) {} }';
    echo '}';
    echo 'try { applyCollapsed(window.localStorage.getItem(storageKey) === "1", false); } catch (error) { applyCollapsed(false, false); }';
    echo 'toggle.addEventListener("click", function () {';
    echo 'if (!desktopQuery.matches) { return; }';
    echo 'applyCollapsed(!shell.classList.contains("sidebar-collapsed"), true);';
    echo '});';
    echo 'if (typeof desktopQuery.addEventListener === "function") {';
    echo 'desktopQuery.addEventListener("change", function () {';
    echo 'if (!desktopQuery.matches) { shell.classList.remove("sidebar-collapsed"); toggle.setAttribute("aria-expanded", "true"); return; }';
    echo 'try { applyCollapsed(window.localStorage.getItem(storageKey) === "1", false); } catch (error) { applyCollapsed(false, false); }';
    echo '});';
    echo '}';
    echo '}());';
    echo '</script>';
    echo '</main>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
}

function fleet_render_schema_required(array $user, string $title, string $activeNav): void
{
    $pdo = fleet_pdo();
    $missing = fleet_missing_tables($pdo);

    fleet_render_shell_start($title, $activeNav, $user, 'The redesign schema needs to be applied before these pages can be used.');
    echo '<section class="panel setup-panel">';
    echo '<h2>Database setup required</h2>';
    echo '<p>The new workflow tables are not available in the current database yet.</p>';
    echo '<div class="setup-list">';
    foreach ($missing as $tableName) {
        echo '<span class="setup-chip">' . fleet_h($tableName) . '</span>';
    }
    echo '</div>';
    echo '<p class="setup-hint">Run <code>php database/migrate_redesign.php</code> and then <code>php database/backfill_redesign.php</code>. The raw SQL files remain in <code>database/migrations</code> for reference.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

