-- Legacy backfill into the new fleet redesign tables.
-- Run only after 20260318_001_fleet_redesign_schema.sql.
-- This script is intentionally additive and leaves legacy tables untouched.

SET NAMES utf8mb4;

START TRANSACTION;

-- Map current enum-style user roles to the new roles table.
UPDATE users u
JOIN roles r
    ON r.code = CASE
        WHEN u.role = 'staff' THEN 'driver'
        WHEN u.role = 'facility_admin' THEN 'facility_admin'
        WHEN u.role = 'admin' THEN 'admin'
        WHEN u.role = 'super_admin' THEN 'super_admin'
        ELSE 'driver'
    END
SET u.role_id = r.id
WHERE u.role_id IS NULL;

-- Backfill vehicle assignment lifecycle fields from the legacy structure.
UPDATE vehicle_assignments
SET start_date = COALESCE(start_date, assigned_date),
    assignment_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'ended' END
WHERE start_date IS NULL
   OR assignment_status IS NULL;

-- Create card accounts from legacy float accounts attached to vehicles.
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
    COALESCE(v.float_account_name, CONCAT(v.vehicle_name, ' (', v.number_plate, ')')),
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
WHERE ca.id IS NULL;

-- Import old logbook rows as trip legs.
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
    l.driver_id,
    COALESCE(l.facility_id, v.facility_id),
    l.log_date,
    DATE_SUB(l.log_date, INTERVAL (DAYOFWEEK(l.log_date) - 1) DAY),
    l.time_out,
    l.time_in,
    l.location_from,
    l.location_to,
    l.purpose,
    l.start_kms,
    l.end_kms,
    l.total_kms,
    NULL,
    'recorded',
    1,
    'Imported from legacy logbook. Confirmed-by person was not stored in the old system.',
    'logbook',
    l.id,
    l.driver_id,
    l.approver_id,
    l.created_at,
    l.updated_at
FROM logbook l
LEFT JOIN vehicles v
    ON v.id = l.vehicle_id
LEFT JOIN trip_legs tl
    ON tl.legacy_source_table = 'logbook'
   AND tl.legacy_source_id = l.id
WHERE tl.id IS NULL;

-- Import approved requisitions as historical fuel purchases.
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
    r.staff_id,
    COALESCE(r.facility_id, v.facility_id),
    ca.id,
    r.request_date,
    DATE_SUB(r.request_date, INTERVAL (DAYOFWEEK(r.request_date) - 1) DAY),
    COALESCE(r.filling_station, 'Unknown Station'),
    r.receipt_number,
    COALESCE(r.mileage, 0.00),
    r.requested_amount,
    r.fuel_price_per_liter,
    (r.requested_amount * r.fuel_price_per_liter),
    r.notes,
    'recorded',
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
    r.staff_id,
    r.approver_id,
    r.created_at,
    r.created_at
FROM requisitions r
LEFT JOIN vehicles v
    ON v.id = r.vehicle_id
LEFT JOIN card_accounts ca
    ON ca.vehicle_id = r.vehicle_id
LEFT JOIN fuel_purchases fp
    ON fp.legacy_source_table = 'requisitions'
   AND fp.legacy_source_id = r.id
WHERE r.status = 'approved'
  AND fp.id IS NULL;

-- Register legacy blob receipts as attachment rows without moving the binary data yet.
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
    r.staff_id,
    r.created_at
FROM requisitions r
JOIN fuel_purchases fp
    ON fp.legacy_source_table = 'requisitions'
   AND fp.legacy_source_id = r.id
LEFT JOIN attachments a
    ON a.legacy_source_table = 'requisitions'
   AND a.legacy_source_id = r.id
   AND a.attachment_type = 'receipt'
WHERE r.status = 'approved'
  AND r.receipt_data IS NOT NULL
  AND a.id IS NULL;

COMMIT;
