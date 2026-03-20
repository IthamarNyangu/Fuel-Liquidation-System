-- Fuel Liquidation System redesign schema
-- Additive migration against the current NGO fleet database.
-- This keeps legacy tables in place while introducing the new normalized model.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code, name, description)
VALUES
    ('driver', 'Driver', 'Records movement legs, fuel purchases, and submits weekly liquidation'),
    ('facility_admin', 'Facility Admin', 'Reviews weekly liquidations for the assigned facility'),
    ('admin', 'Admin', 'Manages fleet operations and organisation-wide oversight'),
    ('super_admin', 'Super Admin', 'Global system administration')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role_id INT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS user_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER password,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE facilities
    ADD COLUMN IF NOT EXISTS province_name VARCHAR(100) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS facility_type ENUM('facility', 'hq') NOT NULL DEFAULT 'facility' AFTER facility_code,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by;

ALTER TABLE vehicles
    ADD COLUMN IF NOT EXISTS asset_code VARCHAR(100) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS asset_type ENUM('car', 'motorcycle') NOT NULL DEFAULT 'car' AFTER number_plate,
    ADD COLUMN IF NOT EXISTS fuel_type ENUM('petrol', 'diesel') NULL AFTER asset_type,
    ADD COLUMN IF NOT EXISTS make VARCHAR(100) NULL AFTER fuel_type,
    ADD COLUMN IF NOT EXISTS model VARCHAR(100) NULL AFTER make,
    ADD COLUMN IF NOT EXISTS year_of_make SMALLINT NULL AFTER model,
    ADD COLUMN IF NOT EXISTS opening_odometer_km DECIMAL(10,2) NULL AFTER current_mileage,
    ADD COLUMN IF NOT EXISTS vehicle_status ENUM('active', 'maintenance', 'retired') NOT NULL DEFAULT 'active' AFTER current_driver_id;

ALTER TABLE vehicle_assignments
    ADD COLUMN IF NOT EXISTS assignment_type ENUM('primary', 'shared', 'temporary') NOT NULL DEFAULT 'primary' AFTER user_id,
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER assigned_date,
    ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date,
    ADD COLUMN IF NOT EXISTS assignment_status ENUM('active', 'ended') NOT NULL DEFAULT 'active' AFTER is_active;

CREATE TABLE IF NOT EXISTS card_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_id INT NULL,
    vehicle_id INT NULL,
    account_code VARCHAR(100) NOT NULL UNIQUE,
    account_name VARCHAR(255) NOT NULL,
    provider ENUM('TOM', 'OTHER') NOT NULL DEFAULT 'TOM',
    card_number_masked VARCHAR(50) NULL,
    fuel_type ENUM('petrol', 'diesel') NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    legacy_float_account_name VARCHAR(255) NULL,
    status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_card_accounts_facility (facility_id),
    KEY idx_card_accounts_vehicle (vehicle_id),
    KEY idx_card_accounts_status (status),
    CONSTRAINT fk_card_accounts_facility
        FOREIGN KEY (facility_id) REFERENCES facilities(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_card_accounts_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_card_accounts_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_id INT NULL,
    fuel_type ENUM('petrol', 'diesel') NOT NULL,
    price_per_litre DECIMAL(10,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fuel_prices_facility_type_from (facility_id, fuel_type, effective_from),
    KEY idx_fuel_prices_status (status),
    CONSTRAINT fk_fuel_prices_facility
        FOREIGN KEY (facility_id) REFERENCES facilities(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fuel_prices_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_legs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    facility_id INT NULL,
    movement_date DATE NOT NULL,
    week_start_date DATE NOT NULL,
    time_out TIME NOT NULL,
    time_in TIME NULL,
    arrival_date DATE NULL,
    from_location VARCHAR(255) NOT NULL,
    to_location VARCHAR(255) NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    odometer_start_km DECIMAL(10,2) NOT NULL,
    odometer_end_km DECIMAL(10,2) NULL,
    total_km DECIMAL(10,2) NULL,
    confirmed_by_user_id INT NULL,
    confirmed_by_name VARCHAR(150) NULL,
    confirmed_by_title VARCHAR(150) NULL,
    confirmed_by_contact VARCHAR(100) NULL,
    passenger_name VARCHAR(150) NULL,
    record_status ENUM('draft', 'recorded', 'in_progress', 'completed', 'locked', 'voided') NOT NULL DEFAULT 'draft',
    has_issues TINYINT(1) NOT NULL DEFAULT 0,
    issue_notes TEXT NULL,
    void_reason TEXT NULL,
    legacy_source_table VARCHAR(50) NULL,
    legacy_source_id INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_trip_legs_vehicle_date (vehicle_id, movement_date, time_out),
    KEY idx_trip_legs_driver_week (driver_id, week_start_date),
    KEY idx_trip_legs_facility_week (facility_id, week_start_date),
    KEY idx_trip_legs_status (record_status),
    KEY idx_trip_legs_legacy (legacy_source_table, legacy_source_id),
    CONSTRAINT fk_trip_legs_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trip_legs_driver
        FOREIGN KEY (driver_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_trip_legs_facility
        FOREIGN KEY (facility_id) REFERENCES facilities(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trip_legs_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_trip_legs_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    facility_id INT NULL,
    card_account_id INT NULL,
    purchase_date DATE NOT NULL,
    week_start_date DATE NOT NULL,
    station_name VARCHAR(200) NOT NULL,
    receipt_number VARCHAR(100) NULL,
    odometer_at_refill_km DECIMAL(10,2) NOT NULL,
    litres DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NULL,
    amount DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    record_status ENUM('draft', 'recorded', 'in_progress', 'completed', 'locked', 'voided') NOT NULL DEFAULT 'draft',
    has_issues TINYINT(1) NOT NULL DEFAULT 0,
    issue_notes TEXT NULL,
    void_reason TEXT NULL,
    legacy_source_table VARCHAR(50) NULL,
    legacy_source_id INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fuel_purchases_vehicle_date (vehicle_id, purchase_date),
    KEY idx_fuel_purchases_driver_week (driver_id, week_start_date),
    KEY idx_fuel_purchases_card_date (card_account_id, purchase_date),
    KEY idx_fuel_purchases_status (record_status),
    KEY idx_fuel_purchases_legacy (legacy_source_table, legacy_source_id),
    CONSTRAINT fk_fuel_purchases_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_fuel_purchases_driver
        FOREIGN KEY (driver_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_fuel_purchases_facility
        FOREIGN KEY (facility_id) REFERENCES facilities(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fuel_purchases_card_account
        FOREIGN KEY (card_account_id) REFERENCES card_accounts(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fuel_purchases_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fuel_purchases_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_liquidations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    facility_id INT NULL,
    week_start_date DATE NOT NULL,
    week_end_date DATE NOT NULL,
    status ENUM('draft', 'submitted', 'under_review', 'returned', 'approved') NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    submitted_by INT NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    review_notes TEXT NULL,
    return_reason TEXT NULL,
    total_trip_legs INT NOT NULL DEFAULT 0,
    total_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_fuel_litres DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_fuel_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    has_issues TINYINT(1) NOT NULL DEFAULT 0,
    issue_notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_weekly_liquidations_driver_vehicle_week (driver_id, vehicle_id, week_start_date),
    KEY idx_weekly_liquidations_facility_status (facility_id, status, week_start_date),
    CONSTRAINT fk_weekly_liquidations_driver
        FOREIGN KEY (driver_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_weekly_liquidations_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_weekly_liquidations_facility
        FOREIGN KEY (facility_id) REFERENCES facilities(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_weekly_liquidations_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_weekly_liquidations_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_liquidation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    weekly_liquidation_id INT NOT NULL,
    item_type ENUM('trip_leg', 'fuel_purchase') NOT NULL,
    trip_leg_id INT NULL,
    fuel_purchase_id INT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_weekly_liquidation_trip_leg (weekly_liquidation_id, trip_leg_id),
    UNIQUE KEY uq_weekly_liquidation_fuel_purchase (weekly_liquidation_id, fuel_purchase_id),
    KEY idx_weekly_liquidation_items_type (item_type),
    CONSTRAINT fk_weekly_items_weekly_liquidation
        FOREIGN KEY (weekly_liquidation_id) REFERENCES weekly_liquidations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_weekly_items_trip_leg
        FOREIGN KEY (trip_leg_id) REFERENCES trip_legs(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_weekly_items_fuel_purchase
        FOREIGN KEY (fuel_purchase_id) REFERENCES fuel_purchases(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS edit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_leg_id INT NULL,
    fuel_purchase_id INT NULL,
    weekly_liquidation_id INT NULL,
    requested_by INT NOT NULL,
    request_type ENUM('correction', 'void') NOT NULL,
    reason TEXT NOT NULL,
    proposed_changes_json LONGTEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'applied', 'cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    decision_notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_edit_requests_status (status),
    KEY idx_edit_requests_requested_by (requested_by),
    CONSTRAINT fk_edit_requests_trip_leg
        FOREIGN KEY (trip_leg_id) REFERENCES trip_legs(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_edit_requests_fuel_purchase
        FOREIGN KEY (fuel_purchase_id) REFERENCES fuel_purchases(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_edit_requests_weekly_liquidation
        FOREIGN KEY (weekly_liquidation_id) REFERENCES weekly_liquidations(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_edit_requests_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_edit_requests_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_leg_id INT NULL,
    fuel_purchase_id INT NULL,
    weekly_liquidation_id INT NULL,
    edit_request_id INT NULL,
    attachment_type ENUM('receipt', 'supporting_doc', 'signature', 'other') NOT NULL,
    storage_method ENUM('file', 'db_blob_legacy') NOT NULL DEFAULT 'file',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NULL,
    storage_path VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    file_size_bytes BIGINT NULL,
    legacy_source_table VARCHAR(50) NULL,
    legacy_source_id INT NULL,
    legacy_source_column VARCHAR(100) NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attachments_trip_leg (trip_leg_id),
    KEY idx_attachments_fuel_purchase (fuel_purchase_id),
    KEY idx_attachments_weekly_liquidation (weekly_liquidation_id),
    KEY idx_attachments_edit_request (edit_request_id),
    KEY idx_attachments_legacy (legacy_source_table, legacy_source_id),
    CONSTRAINT fk_attachments_trip_leg
        FOREIGN KEY (trip_leg_id) REFERENCES trip_legs(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_attachments_fuel_purchase
        FOREIGN KEY (fuel_purchase_id) REFERENCES fuel_purchases(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_attachments_weekly_liquidation
        FOREIGN KEY (weekly_liquidation_id) REFERENCES weekly_liquidations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_attachments_edit_request
        FOREIGN KEY (edit_request_id) REFERENCES edit_requests(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_attachments_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



