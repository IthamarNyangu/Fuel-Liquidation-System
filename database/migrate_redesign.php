<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_config.php';

function line(string $message): void
{
    echo $message, PHP_EOL;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    $stmt->execute([$tableName, $columnName]);

    return (int) $stmt->fetchColumn() > 0;
}

function addColumnIfMissing(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    if (columnExists($pdo, $tableName, $columnName)) {
        line("Skip column {$tableName}.{$columnName}");
        return;
    }

    $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$definition}");
    line("Added column {$tableName}.{$columnName}");
}

function ensureUsersRoleEnumIncludesFinance(PDO $pdo): void
{
    if (!columnExists($pdo, 'users', 'role')) {
        return;
    }

    $columnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $columnStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $columnType = strtolower((string) ($column['Type'] ?? ''));

    if (str_contains($columnType, "'finance'")) {
        line('Ready users.role enum includes finance');
        return;
    }

    $pdo->exec("
        ALTER TABLE users
        MODIFY COLUMN role ENUM('super_admin', 'facility_admin', 'admin', 'approver', 'staff', 'finance')
        NULL DEFAULT 'staff'
    ");
    line('Updated users.role enum to include finance');
}

function createTable(PDO $pdo, string $tableName, string $sql): void
{
    $pdo->exec($sql);
    line(tableExists($pdo, $tableName) ? "Ready table {$tableName}" : "Failed table {$tableName}");
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


try {
    createTable($pdo, 'roles', "
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $roleStmt = $pdo->prepare("
        INSERT INTO roles (code, name, description)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description)
    ");
    foreach ([
        ['driver', 'Driver', 'Records movement legs, fuel purchases, and submits weekly liquidation'],
        ['finance', 'Finance', 'Gives the final finance approval after provincial and fleet checks'],
        ['facility_admin', 'Facility Admin', 'Reviews weekly liquidations for the assigned facility'],
        ['admin', 'Admin', 'Manages fleet operations and organisation-wide oversight'],
        ['super_admin', 'Super Admin', 'Global system administration'],
    ] as $role) {
        $roleStmt->execute($role);
    }
    line('Seeded roles');

    ensureUsersRoleEnumIncludesFinance($pdo);

    addColumnIfMissing($pdo, 'users', 'role_id', "INT NULL AFTER role");
    addColumnIfMissing($pdo, 'users', 'phone', "VARCHAR(50) NULL AFTER email");
    addColumnIfMissing($pdo, 'users', 'user_status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER password");
    addColumnIfMissing($pdo, 'users', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

    $fixedBlankFinanceRoles = $pdo->exec("
        UPDATE users
        SET role = 'finance',
            is_super_admin = 0,
            is_facility_admin = 0
        WHERE role = ''
    ");
    line('Repaired ' . $fixedBlankFinanceRoles . ' blank finance role row(s)');

    addColumnIfMissing($pdo, 'facilities', 'province_name', "VARCHAR(100) NULL AFTER location");
    addColumnIfMissing($pdo, 'facilities', 'facility_type', "ENUM('facility', 'hq') NOT NULL DEFAULT 'facility' AFTER facility_code");
    addColumnIfMissing($pdo, 'facilities', 'updated_by', "INT NULL AFTER created_by");

    addColumnIfMissing($pdo, 'vehicles', 'asset_code', "VARCHAR(100) NULL AFTER id");
    addColumnIfMissing($pdo, 'vehicles', 'asset_type', "ENUM('car', 'motorcycle') NOT NULL DEFAULT 'car' AFTER number_plate");
    addColumnIfMissing($pdo, 'vehicles', 'fuel_type', "ENUM('petrol', 'diesel') NULL AFTER asset_type");
    addColumnIfMissing($pdo, 'vehicles', 'make', "VARCHAR(100) NULL AFTER fuel_type");
    addColumnIfMissing($pdo, 'vehicles', 'model', "VARCHAR(100) NULL AFTER make");
    addColumnIfMissing($pdo, 'vehicles', 'year_of_make', "SMALLINT NULL AFTER model");
    addColumnIfMissing($pdo, 'vehicles', 'opening_odometer_km', "DECIMAL(10,2) NULL AFTER current_mileage");
    addColumnIfMissing($pdo, 'vehicles', 'vehicle_status', "ENUM('active', 'maintenance', 'retired') NOT NULL DEFAULT 'active' AFTER current_driver_id");

    addColumnIfMissing($pdo, 'vehicle_assignments', 'assignment_type', "ENUM('primary', 'shared', 'temporary') NOT NULL DEFAULT 'primary' AFTER user_id");
    addColumnIfMissing($pdo, 'vehicle_assignments', 'start_date', "DATE NULL AFTER assigned_date");
    addColumnIfMissing($pdo, 'vehicle_assignments', 'end_date', "DATE NULL AFTER start_date");
    addColumnIfMissing($pdo, 'vehicle_assignments', 'assignment_status', "ENUM('active', 'ended') NOT NULL DEFAULT 'active' AFTER is_active");

    createTable($pdo, 'card_accounts', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'fuel_prices', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'trip_legs', "
        CREATE TABLE IF NOT EXISTS trip_legs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            facility_id INT NULL,
            movement_date DATE NOT NULL,
            week_start_date DATE NOT NULL,
            time_out TIME NOT NULL,
            time_in TIME NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    addColumnIfMissing($pdo, 'trip_legs', 'confirmed_by_user_id', "INT NULL AFTER total_km");
    addColumnIfMissing($pdo, 'trip_legs', 'arrival_date', "DATE NULL AFTER time_in");
    addColumnIfMissing($pdo, 'trip_legs', 'passenger_name', "VARCHAR(150) NULL AFTER confirmed_by_contact");
    $pdo->exec("
        ALTER TABLE trip_legs
        MODIFY time_in TIME NULL,
        MODIFY odometer_end_km DECIMAL(10,2) NULL,
        MODIFY total_km DECIMAL(10,2) NULL,
        MODIFY record_status ENUM('draft', 'recorded', 'in_progress', 'completed', 'locked', 'voided') NOT NULL DEFAULT 'draft'
    ");
    line('Adjusted trip_legs columns for two-step completion');

    $pdo->exec("
        UPDATE trip_legs
        SET record_status = 'completed'
        WHERE record_status IN ('draft', 'recorded')
    ");
    line('Normalized legacy trip leg statuses');

    $copiedArrivalDates = $pdo->exec("
        UPDATE trip_legs
        SET arrival_date = movement_date
        WHERE arrival_date IS NULL
          AND time_in IS NOT NULL
    ");
    line('Backfilled arrival_date for ' . $copiedArrivalDates . ' trip leg row(s)');

    $copiedPassengerNames = $pdo->exec("
        UPDATE trip_legs
        SET passenger_name = confirmed_by_name
        WHERE passenger_name IS NULL
          AND confirmed_by_name IS NOT NULL
          AND confirmed_by_name <> ''
    ");
    line('Backfilled passenger_name for ' . $copiedPassengerNames . ' trip leg row(s)');
    createTable($pdo, 'fuel_purchases', "
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
            record_status ENUM('draft', 'recorded', 'locked', 'voided') NOT NULL DEFAULT 'draft',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'weekly_liquidations', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    addColumnIfMissing($pdo, 'weekly_liquidations', 'submission_round', "INT NOT NULL DEFAULT 0 AFTER status");

    createTable($pdo, 'weekly_liquidation_items', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'reconciliation_reviews', "
        CREATE TABLE IF NOT EXISTS reconciliation_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            weekly_liquidation_id INT NOT NULL,
            submission_round INT NOT NULL DEFAULT 1,
            review_role VARCHAR(50) NOT NULL,
            review_action ENUM('checked', 'returned', 'approved') NOT NULL,
            reviewed_by INT NULL,
            reviewer_name VARCHAR(255) NULL,
            reviewer_email VARCHAR(255) NULL,
            review_notes TEXT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reconciliation_reviews_package_round (weekly_liquidation_id, submission_round),
            KEY idx_reconciliation_reviews_role_action (review_role, review_action),
            KEY idx_reconciliation_reviews_reviewer (reviewed_by),
            CONSTRAINT fk_reconciliation_reviews_weekly_liquidation
                FOREIGN KEY (weekly_liquidation_id) REFERENCES weekly_liquidations(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_reconciliation_reviews_reviewed_by
                FOREIGN KEY (reviewed_by) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'edit_requests', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    createTable($pdo, 'attachments', "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    line('Fleet redesign schema migration completed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    line('Migration failed: ' . $e->getMessage());
    exit(1);
}









