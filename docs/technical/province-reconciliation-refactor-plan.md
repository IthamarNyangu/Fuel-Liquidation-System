# Province and Reconciliation Refactor Plan

Last updated: April 17, 2026

## 1. Purpose

This document turns the revised NGO operating model into a practical implementation plan for the current plain PHP/MySQL codebase.

It is written for the system as it exists now:

- PHP page controllers with shared helper files
- mixed legacy and redesigned workflow
- database already extended with `trip_legs`, `fuel_purchases`, `card_accounts`, and `weekly_liquidations`

The main goal is to refactor the system from:

- `facility` scope to `province` scope
- `weekly liquidation` packaging to `vehicle/card reconciliation`
- mixed legacy role logic to three roles only:
  - `fleet_manager`
  - `provincial_admin`
  - `driver`

## 2. Target Business Model

### 2.1 Roles

Only these roles should remain in business logic and UI:

- Fleet Manager
- Provincial Admin
- Driver

### 2.2 Organisational scope

The organisation works by province, not facility.

Target provinces:

- Central
- North-Western
- Copperbelt
- Northern
- Luapula
- Muchinga

Practical rule for staged migration:

- keep legacy `facilities` and `facility_id` physically for now where needed
- treat them as province records in UI/business language
- introduce true `provinces` table only when the app is ready to stop depending on `facilities`

### 2.3 Vehicle and card model

A TOM card belongs to a vehicle.

Each vehicle should have:

- province
- asset type: `car` or `motorcycle`
- fuel type: `petrol` or `diesel`
- TOM card number
- normal card limit
- initial card balance
- current card balance
- one or more assignments
- one active driver context at a time

### 2.4 Reconciliation model

Refueling is a transaction. Reconciliation is a submission package.

Each reconciliation should package:

- one vehicle
- one driver
- one province
- one or more fuel purchases
- related movement legs
- totals for litres and spend
- card balance at submission
- suggested replenishment amount

## 3. Current Codebase Gaps

The codebase already has transitional redesign tables, but the operating model is still not aligned with the real workflow.

### 3.1 Current schema mismatch

Already present:

- `card_accounts`
- `trip_legs`
- `fuel_purchases`
- `weekly_liquidations`
- `weekly_liquidation_items`

Main mismatch:

- `card_accounts` exists, but the system still treats top-up and float behavior as older float logic in several places
- `trip_legs` and `fuel_purchases` exist, but packaging is still week-based
- `weekly_liquidations` is the wrong operational concept for real reconciliation
- `facility_id` is still the dominant scope field

### 3.2 Current page mismatch

Driver pages:

- `pages/driver/my_vehicle.php`
- `pages/driver/log_movement_leg.php`
- `pages/driver/record_fuel_purchase.php`
- `pages/driver/weekly_liquidation.php`
- `pages/driver/weekly_report.php`

Admin pages:

- `pages/admin/dashboard.php`
- `pages/admin/manage_facilities.php`
- `pages/admin/manage_vehicles.php`
- `pages/admin/users.php`
- `pages/admin/fuelset.php`
- `pages/admin/fuel_topup.php`
- `pages/admin/facility_liquidation_review.php`
- `pages/admin/reports.php`

Main mismatch:

- driver workflow has already moved toward movement/fuel capture
- review and packaging still assume a weekly period
- admin pages still blend old requisition logic with the new redesign

## 4. Recommended Data Strategy

Use staged migration. Do not force a hard rename or cutover all at once.

### 4.1 Keep and extend these existing tables

Keep:

- `users`
- `vehicles`
- `vehicle_assignments`
- `card_accounts`
- `trip_legs`
- `fuel_purchases`
- `attachments`

Extend:

- `users`
- `vehicles`
- `card_accounts`
- `trip_legs`
- `fuel_purchases`

### 4.2 Deprecate these concepts in UI first

Deprecate in UI/business language:

- `facility`
- `facility_admin`
- `weekly_liquidation`
- `weekly_liquidation_items`

Do not remove them from the database in stage 1.

### 4.3 Add these new tables

Recommended additions:

- `provinces`
- `reconciliations`
- `reconciliation_items`
- `card_topups`

Optional later:

- `card_balance_ledger`
- `vehicle_active_sessions`

## 5. Recommended Table Design Changes

### 5.1 `provinces`

If the system is ready for a clean scope table, add:

```sql
CREATE TABLE provinces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(30) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Seed:

- Central
- North-Western
- Copperbelt
- Northern
- Luapula
- Muchinga

If the app is not ready yet:

- continue using `facilities`
- treat each row as a province record
- use `province` wording everywhere in UI

### 5.2 `users`

Required target fields:

- `role`
- `province_id`
- `status`

Recommended staged rule:

- keep current role columns
- normalize runtime role values to:
  - `fleet_manager`
  - `provincial_admin`
  - `driver`

Recommended additions:

```sql
ALTER TABLE users
    ADD COLUMN province_id INT NULL,
    ADD COLUMN account_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active';
```

Business rules:

- Fleet Manager: `province_id` nullable
- Provincial Admin: `province_id` required
- Driver: `province_id` required

### 5.3 `vehicles`

Required target fields:

- `province_id`
- `asset_type`
- `fuel_type`
- `current_odometer`
- `vehicle_status`

Recommended additions if not already present in usable form:

```sql
ALTER TABLE vehicles
    ADD COLUMN province_id INT NULL,
    ADD COLUMN asset_type ENUM('car', 'motorcycle') NOT NULL DEFAULT 'car',
    ADD COLUMN fuel_type ENUM('petrol', 'diesel') NOT NULL,
    ADD COLUMN current_odometer_km INT NULL,
    ADD COLUMN vehicle_status ENUM('active', 'maintenance', 'retired') NOT NULL DEFAULT 'active';
```

### 5.4 `card_accounts`

This table already exists and is the best place to keep the TOM card model.

Recommended new/renamed business fields:

- `vehicle_id`
- `card_number`
- `card_limit`
- `initial_balance`
- `current_balance`
- `threshold_status`
- `threshold_percent`
- `status`

Recommended additions:

```sql
ALTER TABLE card_accounts
    ADD COLUMN card_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN threshold_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    ADD COLUMN threshold_status ENUM('healthy', 'refill_soon', 'needs_top_up') NOT NULL DEFAULT 'healthy';
```

Recommended business mapping:

- `opening_balance` becomes business label `Initial Card Balance`
- `current_balance` remains current balance
- `account_code` or `card_number_masked` can support TOM card number

### 5.5 `trip_legs`

Keep this table and refocus it.

Recommended additions:

```sql
ALTER TABLE trip_legs
    ADD COLUMN province_id INT NULL,
    ADD COLUMN reconciliation_id INT NULL,
    ADD COLUMN reconciliation_status ENUM('pending', 'included', 'approved') NOT NULL DEFAULT 'pending';
```

Required business rules:

- one point-to-point leg per row
- no overlapping active movement across two vehicles for the same driver
- `in_progress` means not yet completed
- completed leg can later be linked into a reconciliation package

### 5.6 `fuel_purchases`

Keep this table and refocus it.

Recommended additions:

```sql
ALTER TABLE fuel_purchases
    ADD COLUMN province_id INT NULL,
    ADD COLUMN reconciliation_id INT NULL,
    ADD COLUMN reconciliation_status ENUM('pending', 'submitted', 'approved', 'returned') NOT NULL DEFAULT 'pending',
    ADD COLUMN card_balance_after_purchase DECIMAL(12,2) NULL;
```

Required behavior:

- every saved fuel purchase should become `pending`
- receipt attachment should be required for normal submission
- `fuel_type` should be resolved from the selected vehicle, not manually entered

### 5.7 `reconciliations`

Add a new table instead of trying to stretch `weekly_liquidations`.

```sql
CREATE TABLE reconciliations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(100) NOT NULL UNIQUE,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    province_id INT NULL,
    card_account_id INT NULL,
    status ENUM('draft', 'submitted', 'reviewed', 'returned', 'approved') NOT NULL DEFAULT 'draft',
    total_movement_legs INT NOT NULL DEFAULT 0,
    total_fuel_purchases INT NOT NULL DEFAULT 0,
    total_litres DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_spend DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    card_balance_at_submission DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    suggested_replenishment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    returned_reason TEXT NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 5.8 `reconciliation_items`

```sql
CREATE TABLE reconciliation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL,
    item_type ENUM('movement_leg', 'fuel_purchase') NOT NULL,
    item_id INT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reconciliation_item (reconciliation_id, item_type, item_id)
);
```

### 5.9 `card_topups`

```sql
CREATE TABLE card_topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    province_id INT NULL,
    card_account_id INT NULL,
    requested_by INT NULL,
    approved_by INT NULL,
    transaction_type ENUM('top_up', 'deduction', 'correction') NOT NULL DEFAULT 'top_up',
    amount DECIMAL(12,2) NOT NULL,
    suggested_amount DECIMAL(12,2) NULL,
    reason_type VARCHAR(100) NULL,
    notes TEXT NULL,
    status ENUM('draft', 'requested', 'approved', 'processed', 'cancelled') NOT NULL DEFAULT 'processed',
    processed_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);
```

## 6. Threshold Logic

Threshold logic should be warning-based only.

Recommended calculation:

- `remaining_percent = (current_balance / card_limit) * 100`

Recommended statuses:

- above `55%`: `healthy`
- `45%` to `55%`: `refill_soon`
- below `45%`: `needs_top_up`

Suggested replenishment:

- `card_limit - current_balance`

Important:

- do not block late replenishment
- do not block late reconciliation
- use banners, chips, and dashboard counters instead

## 7. Role and Permission Matrix

| Area | Fleet Manager | Provincial Admin | Driver |
|---|---|---|---|
| View all provinces | Yes | No | No |
| Manage provinces | Yes | No | No |
| Create accounts | Yes | No | No |
| Edit own-province accounts | Yes | Yes | No |
| Manage vehicles | All provinces | Own province only | No |
| Assign drivers to vehicles | All provinces | Own province only | No |
| Manage card top-ups | Yes | Yes, own province only | No |
| Change pump prices | Yes | No | No |
| Record movement | No | No | Yes |
| Record fuel purchase | No | No | Yes |
| Submit reconciliation | No | No | Yes |
| Review reconciliations | Yes | Own province only | No |
| View reports | All provinces | Own province only | Own activity only |

## 8. File-by-File Refactor Map

This section maps the target model to the current PHP files.

### 8.1 Shared permission and role helpers

Files:

- `includes/core/facility_auth.php`
- `fleet_redesign.php`
- `actions/auth/login.php`

Change:

- make `fleet_manager`, `provincial_admin`, and `driver` the only runtime roles
- keep compatibility mapping from:
  - `super_admin` -> `fleet_manager`
  - `admin` -> `fleet_manager`
  - `facility_admin` -> `provincial_admin`
  - `staff` -> `driver`
- continue exposing `getUserProvinceId()` even if the DB still stores `facility_id`

### 8.2 Driver workspace

File:

- `pages/driver/my_vehicle.php`

Target:

- active vehicle card summary
- current balance
- threshold status
- suggested top-up amount
- pending reconciliation reminder

Behavior:

- if driver has multiple assignments, show active vehicle selector
- show only one active vehicle session for movement capture

### 8.3 Movement logging

File:

- `pages/driver/log_movement_leg.php`

Target:

- preserve the two-step start/complete leg workflow
- enforce no overlapping open leg across two vehicles for the same driver
- default the next leg from the previous completed leg of the same vehicle/day
- mark completed legs `pending` for reconciliation by default

### 8.4 Fuel purchase capture

File:

- `pages/driver/record_fuel_purchase.php`

Target:

- save purchase as `pending`
- resolve fuel type from selected vehicle
- store card balance after purchase
- show reminder banner after save

### 8.5 Reconciliation packaging

Current file:

- `pages/driver/weekly_liquidation.php`

Refactor target:

- rename business concept to `Pending Reconciliations`
- replace week-first behavior with item-first behavior
- list pending fuel purchases and related movement legs
- support:
  - manual item selection
  - `Include all pending items since last reconciliation`

Technical recommendation:

- keep the file temporarily
- change the route label and UI copy first
- later move implementation to a clearer page name such as `pending_reconciliations.php`

### 8.6 Province review

Current file:

- `pages/admin/facility_liquidation_review.php`

Refactor target:

- province-scoped reconciliation review
- Provincial Admin sees only own province
- Fleet Manager sees all provinces
- rename business concept from `Province Liquidation` to `Reconciliation Review`

Technical recommendation:

- keep the file physically for now
- change the route label and UI copy first
- later rename implementation file once direct links are stable

### 8.7 Vehicles

File:

- `pages/admin/manage_vehicles.php`

Target:

- vehicle province
- asset type
- fuel type
- vehicle card link
- current balance
- threshold status
- assigned driver

Behavior:

- Fleet Manager can manage all
- Provincial Admin can manage own province only

### 8.8 Top-ups

File:

- `pages/admin/fuel_topup.php`

Target:

- view top-up need by vehicle/card
- show:
  - current balance
  - card limit
  - threshold status
  - suggested replenishment
- allow authorized override amount

Behavior:

- Fleet Manager: all vehicles
- Provincial Admin: own province only

### 8.9 Pump prices

File:

- `pages/admin/fuelset.php`

Target:

- system-wide fuel prices only
- no province-level price update
- Fleet Manager only

### 8.10 Accounts

File:

- `pages/admin/users.php`

Target:

- Fleet Manager can create accounts
- Provincial Admin cannot create accounts
- role dropdown only:
  - Fleet Manager
  - Provincial Admin
  - Driver
- province required for Provincial Admin and Driver

### 8.11 Province management

Current file:

- `pages/admin/manage_facilities.php`

Target:

- province list
- assigned Provincial Admin
- driver count
- vehicle count
- pending reconciliation count

Behavior:

- Fleet Manager only

### 8.12 Reports

File:

- `pages/admin/reports.php`

Target:

- province filter as the main organisational grouping
- reconciliation summaries
- top-up need summaries
- movement and fuel summaries by province and vehicle

## 9. Suggested Naming Changes

Use these in UI first:

- `Facility` -> `Province`
- `Facilities` -> `Provinces`
- `Facility Admin` -> `Provincial Admin`
- `Weekly Liquidation` -> `Pending Reconciliations`
- `Vehicle Liquidation` -> `Fuel Reconciliation`
- `Facility Liquidation Review` -> `Province Reconciliation Review`
- `Opening Balance` -> `Initial Card Balance`
- `Card Limit` -> `Normal Card Allocation`

Keep old database names internally during transition where necessary.

## 10. Recommended Implementation Order

### Stage 1: permission and naming cleanup

Scope:

- roles
- province wording
- menu labels
- page access rules

Tasks:

1. Make role normalization strict in `facility_auth.php`
2. Remove account creation access from Provincial Admin
3. Rename facility wording to province wording in UI
4. Rename weekly liquidation wording to reconciliation wording in UI

### Stage 2: card model cleanup

Scope:

- `card_accounts`
- `vehicles`
- top-up logic

Tasks:

1. Add `card_limit`
2. Add threshold calculation helpers
3. Show threshold status in:
   - driver hub
   - vehicle management
   - top-up page
4. Use `suggested replenishment = card_limit - current_balance`

### Stage 3: fuel purchase workflow

Scope:

- `fuel_purchases`
- `record_fuel_purchase.php`

Tasks:

1. Add reconciliation status fields
2. Save every purchase as `pending`
3. Save card balance after purchase
4. Add pending reconciliation reminder in driver hub

### Stage 4: movement-reconciliation linkage

Scope:

- `trip_legs`
- `weekly_liquidation.php`
- `facility_liquidation_review.php`

Tasks:

1. Add reconciliation linkage fields to `trip_legs`
2. Build draft reconciliation package from pending items
3. Add manual item selection
4. Add one-click include-all action
5. Submit into new `reconciliations` tables

### Stage 5: province review and reporting

Scope:

- Provincial Admin review
- Fleet Manager oversight
- reporting

Tasks:

1. Restrict review queries to province
2. Build reconciliation queue view
3. Add dashboard counters:
   - pending reconciliations
   - low card balances
   - vehicles needing top-up
4. Update reports around province and reconciliation

### Stage 6: legacy retirement

Only after the new reconciliation flow is stable:

1. freeze old `weekly_liquidations` screens
2. make old `request.php` and `logbook.php` read-only or retire them
3. stop depending on legacy requisition approval for driver operations

## 11. Minimal Controller Guidance

### 11.1 Role gate

```php
function requireFleetManager(array $user): void
{
    if (($user['role'] ?? '') !== 'fleet_manager') {
        $_SESSION['error_message'] = 'You do not have access to this page.';
        header('Location: dashboard.php');
        exit();
    }
}
```

### 11.2 Threshold calculation

```php
function cardThresholdStatus(float $currentBalance, float $cardLimit): string
{
    if ($cardLimit <= 0) {
        return 'healthy';
    }

    $remainingPercent = ($currentBalance / $cardLimit) * 100;

    if ($remainingPercent < 45) {
        return 'needs_top_up';
    }

    if ($remainingPercent <= 55) {
        return 'refill_soon';
    }

    return 'healthy';
}
```

### 11.3 Suggested replenishment

```php
function suggestedReplenishment(float $cardLimit, float $currentBalance): float
{
    return max(0, $cardLimit - $currentBalance);
}
```

### 11.4 No overlapping active movement

```php
function driverHasOpenLeg(PDO $pdo, int $driverId, ?int $excludeVehicleId = null): bool
{
    $sql = "
        SELECT COUNT(*)
        FROM trip_legs
        WHERE driver_id = ?
          AND record_status = 'in_progress'
    ";

    $params = [$driverId];

    if ($excludeVehicleId !== null) {
        $sql .= " AND vehicle_id <> ?";
        $params[] = $excludeVehicleId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}
```

## 12. Minimal Assumptions To Confirm

Confirm only these before deeper implementation:

1. Can Provincial Admin approve reconciliations fully, or only review and return?
2. Is one active TOM card per vehicle always true?
3. Can Provincial Admin process top-ups, or do they only review the need?
4. Should reconciliation still be allowed if a purchase has a receipt issue but the physical paper exists?

Recommended default assumption if business users are not immediately available:

- one active TOM card per vehicle
- Provincial Admin can review province reconciliations
- Fleet Manager retains full oversight
- threshold warnings do not block submission

## 13. Next Code Changes Recommended

The next practical coding pass should be:

1. tighten role gates in shared auth helpers
2. rename weekly liquidation UI to reconciliation wording
3. add threshold helpers for `card_accounts`
4. add `pending` reconciliation status to `fuel_purchases`
5. convert driver packaging page from weekly-first to pending-item-first

That order gives visible operational improvement without forcing a risky full migration in one step.
