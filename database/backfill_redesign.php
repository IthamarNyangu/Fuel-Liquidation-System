<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_config.php';

function line(string $message): void
{
    echo $message, PHP_EOL;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->beginTransaction();

    $roleMapSql = "
        UPDATE users u
        JOIN roles r
            ON r.code = CASE
                WHEN u.role = 'staff' THEN 'driver'
                WHEN u.role = 'facility_admin' THEN 'facility_admin'
                WHEN u.role = 'admin' THEN 'admin'
                WHEN u.role = 'super_admin' THEN 'super_admin'
                WHEN u.role = 'finance' THEN 'finance'
                ELSE 'driver'
            END
        SET u.role_id = r.id
        WHERE u.role_id IS NULL
    ";
    $updatedRoles = $pdo->exec($roleMapSql);
    line('Mapped role_id for ' . $updatedRoles . ' user(s)');

    $assignmentSql = "
        UPDATE vehicle_assignments
        SET start_date = COALESCE(start_date, assigned_date),
            assignment_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'ended' END
        WHERE start_date IS NULL
           OR assignment_status IS NULL
    ";
    $updatedAssignments = $pdo->exec($assignmentSql);
    line('Normalized ' . $updatedAssignments . ' vehicle assignment row(s)');

    $cardSql = "
        INSERT INTO card_accounts (
            facility_id,
            vehicle_id,
            account_code,
            account_name,
            provider,
            opening_balance,
            current_balance,
            legacy_float_account_name,
            status,
            created_at,
            updated_at
        )
        SELECT
            v.facility_id,
            v.id,
            CONCAT('LEGACY-CARD-', v.id),
            COALESCE(NULLIF(v.float_account_name, ''), CONCAT(v.vehicle_name, ' (', v.number_plate, ')')),
            'TOM',
            COALESCE(v.float_balance, 0.00),
            COALESCE(v.float_balance, 0.00),
            v.float_account_name,
            CASE WHEN v.float_account_name IS NULL OR v.float_account_name = '' THEN 'inactive' ELSE 'active' END,
            v.created_at,
            CURRENT_TIMESTAMP
        FROM vehicles v
        LEFT JOIN card_accounts ca
            ON ca.vehicle_id = v.id
        WHERE ca.id IS NULL
    ";
    $createdCards = $pdo->exec($cardSql);
    line('Created ' . $createdCards . ' card account row(s)');

    $tripSql = "
        INSERT INTO trip_legs (
            vehicle_id,
            driver_id,
            facility_id,
            movement_date,
            week_start_date,
            time_out,
            time_in,
            arrival_date,
            from_location,
            to_location,
            purpose,
            odometer_start_km,
            odometer_end_km,
            total_km,
            confirmed_by_name,
            passenger_name,
            record_status,
            has_issues,
            issue_notes,
            legacy_source_table,
            legacy_source_id,
            created_by,
            updated_by,
            created_at,
            updated_at
        )
        SELECT
            l.vehicle_id,
            driver.id,
            COALESCE(l.facility_id, v.facility_id),
            l.log_date,
            DATE_SUB(l.log_date, INTERVAL (DAYOFWEEK(l.log_date) - 1) DAY),
            l.time_out,
            l.time_in,
            l.log_date,
            l.location_from,
            l.location_to,
            l.purpose,
            l.start_kms,
            l.end_kms,
            l.total_kms,
            NULL,
            NULL,
            'completed',
            1,
            'Imported from legacy logbook. Confirmed-by person was not stored in the old system.',
            'logbook',
            l.id,
            driver.id,
            approver.id,
            l.created_at,
            l.updated_at
        FROM logbook l
        JOIN vehicles v
            ON v.id = l.vehicle_id
        JOIN users driver
            ON driver.id = l.driver_id
        LEFT JOIN users approver
            ON approver.id = l.approver_id
        LEFT JOIN trip_legs tl
            ON tl.legacy_source_table = 'logbook'
           AND tl.legacy_source_id = l.id
        WHERE tl.id IS NULL
    ";
    $createdTripLegs = $pdo->exec($tripSql);
    line('Imported ' . $createdTripLegs . ' trip leg row(s)');

    $fuelSql = "
        INSERT INTO fuel_purchases (
            vehicle_id,
            driver_id,
            facility_id,
            card_account_id,
            purchase_date,
            week_start_date,
            station_name,
            receipt_number,
            odometer_at_refill_km,
            litres,
            unit_price,
            amount,
            notes,
            record_status,
            has_issues,
            issue_notes,
            legacy_source_table,
            legacy_source_id,
            created_by,
            updated_by,
            created_at,
            updated_at
        )
        SELECT
            r.vehicle_id,
            staff.id,
            COALESCE(r.facility_id, v.facility_id),
            ca.id,
            r.request_date,
            DATE_SUB(r.request_date, INTERVAL (DAYOFWEEK(r.request_date) - 1) DAY),
            COALESCE(NULLIF(r.filling_station, ''), 'Unknown Station'),
            r.receipt_number,
            COALESCE(r.mileage, 0.00),
            r.requested_amount,
            r.fuel_price_per_liter,
            (r.requested_amount * r.fuel_price_per_liter),
            r.notes,
            'completed',
            CASE
                WHEN r.receipt_data IS NULL AND (r.receipt_filename IS NULL OR r.receipt_filename = '') THEN 1
                ELSE 0
            END,
            CASE
                WHEN r.receipt_data IS NULL AND (r.receipt_filename IS NULL OR r.receipt_filename = '') THEN 'Imported from legacy requisition without receipt attachment.'
                ELSE NULL
            END,
            'requisitions',
            r.id,
            staff.id,
            approver.id,
            r.created_at,
            r.created_at
        FROM requisitions r
        JOIN vehicles v
            ON v.id = r.vehicle_id
        JOIN users staff
            ON staff.id = r.staff_id
        LEFT JOIN users approver
            ON approver.id = r.approver_id
        LEFT JOIN card_accounts ca
            ON ca.vehicle_id = r.vehicle_id
        LEFT JOIN fuel_purchases fp
            ON fp.legacy_source_table = 'requisitions'
           AND fp.legacy_source_id = r.id
        WHERE r.status = 'approved'
          AND fp.id IS NULL
    ";
    $createdFuel = $pdo->exec($fuelSql);
    line('Imported ' . $createdFuel . ' fuel purchase row(s)');

    $attachmentSql = "
        INSERT INTO attachments (
            fuel_purchase_id,
            attachment_type,
            storage_method,
            original_name,
            mime_type,
            legacy_source_table,
            legacy_source_id,
            legacy_source_column,
            uploaded_by,
            uploaded_at
        )
        SELECT
            fp.id,
            'receipt',
            'db_blob_legacy',
            COALESCE(r.receipt_filename, CONCAT('legacy-receipt-', r.id)),
            r.receipt_type,
            'requisitions',
            r.id,
            'receipt_data',
            staff.id,
            r.created_at
        FROM requisitions r
        JOIN users staff
            ON staff.id = r.staff_id
        JOIN fuel_purchases fp
            ON fp.legacy_source_table = 'requisitions'
           AND fp.legacy_source_id = r.id
        LEFT JOIN attachments a
            ON a.legacy_source_table = 'requisitions'
           AND a.legacy_source_id = r.id
           AND a.attachment_type = 'receipt'
        WHERE r.status = 'approved'
          AND r.receipt_data IS NOT NULL
          AND a.id IS NULL
    ";
    $createdAttachments = $pdo->exec($attachmentSql);
    line('Registered ' . $createdAttachments . ' legacy receipt attachment row(s)');

    $pdo->commit();
    line('Fleet redesign backfill completed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    line('Backfill failed: ' . $e->getMessage());
    exit(1);
}



