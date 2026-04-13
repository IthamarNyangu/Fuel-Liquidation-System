# Fuel Management System Technical Documentation

Last updated: April 2, 2026

## 1. Purpose

This document describes the current technical state of the Fuel Management System (FMS) in this codebase and the next update areas planned from the direction already visible in the application.

Important note:
The earlier discussion referenced by the request is not present in this chat thread. The "planned updates" section below is therefore based on:

- the code currently in the repository
- the redesign migration notes
- the partially implemented new workflow already present in the PHP application
- visible technical gaps between the legacy and redesigned modules

## 2. Executive Summary

The system is currently in a transition phase between:

- a legacy fuel requisition and logbook workflow
- a newer weekly liquidation workflow built on a redesigned schema

The legacy workflow is still the main source for dashboards, approvals, reports, exports, and some administration features.

The redesign workflow is already implemented for core driver operations:

- vehicle-based driver landing page
- point-to-point movement leg capture
- fuel purchase recording with receipt upload
- weekly liquidation packaging
- facility review and approval/return flow

This means the system is not greenfield and not fully migrated. It is a live hybrid system with both legacy and new modules active at the same time.

## 3. Technology Stack

### Backend

- PHP, structured as server-rendered page controllers
- No framework detected
- Direct SQL access inside page files

### Database

- MySQL / MariaDB
- Two access styles are used:
  - `PDO`
  - `mysqli`

### Frontend

- Server-rendered HTML
- CSS files per module plus inline CSS in several screens
- Small amounts of vanilla JavaScript

### Composer dependencies

- `phpoffice/phpspreadsheet`
- `dompdf/dompdf`

These are used for report exports.

## 4. High-Level Architecture

The application is a monolithic PHP web app with page-level business logic. There is no separate API layer, service layer, or domain module separation. Most pages perform all of the following in one file:

- authentication/authorization checks
- request handling
- SQL queries
- validation
- HTML rendering

### Shared infrastructure files

- `index.php`: login/signup landing page
- `login.php`: login processing
- `auth_check.php`: session protection
- `facility_auth.php`: facility-based access helper functions
- `db_config.php`: shared `PDO` connection
- `db_connect.php`: shared `mysqli` connection
- `fleet_redesign.php`: helper library for the redesigned workflow

## 5. Role Model

The current application uses both old role flags and a newer normalized role model.

### Session/runtime roles currently used in PHP

- `staff`
- `facility_admin`
- `admin`
- `super_admin`
- `approver` appears in some new-flow role checks, but is not consistently represented elsewhere

### New normalized role table

The redesign introduces a `roles` table with these codes:

- `driver`
- `facility_admin`
- `admin`
- `super_admin`

### Practical access model today

- `staff`: submits requests, logs trips, uses driver workflow
- `facility_admin`: reviews weekly liquidations and can access some admin screens
- `admin`: approves requisitions, manages vehicles/users in facility scope, can also use driver workflow
- `super_admin`: full cross-facility visibility and configuration access

## 6. Current Functional Modules

### 6.1 Authentication and onboarding

Implemented:

- login with password hash verification
- signup with facility selection
- session timeout handling

Current behavior:

- session values are stored in `$_SESSION`
- the primary session role key is `user_role`
- some legacy report modules still read `$_SESSION['role']`, which is inconsistent with the login flow

### 6.2 Dashboard and operations overview

Primary file:

- `dashboard.php`

Current behavior:

- combines requisitions and logbook activity
- supports requisition approval/rejection for admin roles
- displays float balances and fuel stock from `settings`
- includes links into both legacy and redesigned flows

This is still mainly a legacy-structured dashboard, even though it now links to the newer weekly liquidation pages.

### 6.3 User management

Primary file:

- `users.php`

Implemented:

- add user
- edit user
- reset password
- delete user
- facility-scoped access for non-super-admins

Current behavior:

- still uses legacy role fields on `users`
- not yet fully aligned with the redesign `roles` table / `role_id`

### 6.4 Facility management

Primary file:

- `manage_facilities.php`

Implemented:

- add facility
- edit facility
- activate/deactivate facility
- delete facility when unreferenced

Current dependency note:

- this page reads from `facility_stats`, which appears to be an expected database view/table outside the redesign migration files

### 6.5 Vehicle and assignment management

Primary files:

- `manage_vehicles.php`
- `assign_vehicle.php`
- `unassign_vehicle.php`
- `assign_users_to_facility.php`

Implemented:

- vehicle create/edit/delete
- vehicle assignment and unassignment
- facility-based scoping
- fuel type and asset type on vehicles
- float/card settings still tied to vehicles in legacy mode

Current design state:

- vehicle master data has already been extended for the redesign
- vehicle operations still reference legacy tables for usage counts and dependencies

### 6.6 Fuel pricing and float/card administration

Primary files:

- `fuelset.php`
- `fuel_topup.php`
- `settings.php`
- `update_settings.php`

Implemented:

- update global fuel price settings
- maintain available fuel stock
- adjust vehicle float balances
- log float transactions

Current design state:

- legacy pricing uses `settings.fuel_price`
- `fuelset.php` also uses `petrol_price` and `diesel_price`
- redesign introduces `fuel_prices` for structured historical pricing by facility and fuel type

This area is currently split across multiple models and needs consolidation.

### 6.7 Legacy fuel request workflow

Primary file:

- `request.php`

Implemented:

- request fuel against a vehicle
- attach receipt/document blob
- capture mileage and filling station
- assign an approver
- save request as `pending`

Data store:

- `requisitions`

Workflow:

1. Driver submits request
2. Admin approves/rejects in `dashboard.php`
3. On approval, vehicle float balance is reduced
4. Approved data later becomes reportable history

### 6.8 Legacy logbook workflow

Primary file:

- `logbook.php`

Implemented:

- record trip in one form submission
- capture route, times, purpose, odometer start/end
- assign approver

Data store:

- `logbook`

Current technical note:

- this page creates its own `PDO` connection using `root`/blank password instead of reusing shared database config, which is inconsistent and risky

### 6.9 Redesigned driver workflow

Primary helper:

- `fleet_redesign.php`

Primary pages:

- `my_vehicle.php`
- `log_movement_leg.php`
- `record_fuel_purchase.php`
- `weekly_liquidation.php`
- `facility_liquidation_review.php`
- `view_attachment.php`

This is the main new implementation already present in the codebase.

#### `my_vehicle.php`

Purpose:

- landing page for the assigned vehicle and active week
- summarizes trip count, distance, fuel purchases, card status, and weekly warnings

#### `log_movement_leg.php`

Purpose:

- two-step movement capture

Current process:

1. Driver starts a movement leg
2. System stores it as `in_progress`
3. Driver completes the leg on arrival
4. System calculates final kilometres and flags issues if needed

Important behavior already implemented:

- point-to-point capture
- odometer continuity checks
- one in-progress leg at a time per vehicle
- weekly linkage
- `voided` support for test/deletion scenarios

#### `record_fuel_purchase.php`

Purpose:

- record actual fuel refill against a card account

Implemented:

- vehicle-scoped card selection
- required station name and receipt number
- required receipt upload
- odometer validation
- fuel-type compatibility check between vehicle and card
- attachment storage on filesystem
- automatic linking into weekly liquidation

#### `weekly_liquidation.php`

Purpose:

- package weekly trip legs and fuel purchases by driver + vehicle + week

Implemented:

- auto-create weekly draft
- aggregate totals
- detect issues such as:
  - in-progress movement legs
  - missing receipts
  - flagged records
- submit weekly package
- lock included records on submission

#### `facility_liquidation_review.php`

Purpose:

- reviewer queue for submitted weekly liquidation packages

Implemented:

- facility-scoped review queue
- inspect trip legs and fuel purchases in one package
- approve weekly liquidation
- return weekly liquidation with notes
- unlock records if returned

### 6.10 Reporting and exports

Primary files:

- `reports.php`
- `weekly_report.php`
- `generate_report.php`
- `export_report_pdf.php`
- `export_report_excel.php`

Implemented:

- price history reporting
- float adjustment history
- merged vehicle activity reports
- user/vehicle consumption reporting
- weekly approved requisition report
- PDF and Excel export

Current design state:

- reports still mainly read from legacy tables:
  - `requisitions`
  - `logbook`
  - `float_transactions`
  - `fuel_price_history`
- exports do not yet appear to be redesign-first

## 7. Database Model

The database currently contains two active model layers.

### 7.1 Legacy operational tables

The application still actively uses:

- `users`
- `facilities`
- `vehicles`
- `vehicle_assignments`
- `settings`
- `requisitions`
- `logbook`
- `float_transactions`
- `fuel_price_history`

Additional dependencies referenced in code:

- `facility_stats`
- `facility_admins`

These are expected by the application but are not defined in the visible redesign migration package.

### 7.2 Redesigned workflow tables

Introduced by migration:

- `roles`
- `card_accounts`
- `fuel_prices`
- `trip_legs`
- `fuel_purchases`
- `weekly_liquidations`
- `weekly_liquidation_items`
- `edit_requests`
- `attachments`

### 7.3 Redesign status model

Movement and fuel records now use lifecycle states.

#### `trip_legs.record_status`

- `draft`
- `recorded`
- `in_progress`
- `completed`
- `locked`
- `voided`

#### `fuel_purchases.record_status`

- `draft`
- `recorded`
- `in_progress`
- `completed`
- `locked`
- `voided`

#### `weekly_liquidations.status`

- `draft`
- `submitted`
- `under_review`
- `returned`
- `approved`

Validation issues are tracked separately using:

- `has_issues`
- `issue_notes`

This is an improvement over overloading workflow status with quality-control meaning.

## 8. Data Migration and Transition Strategy Already Present

The repository includes:

- `database/migrate_redesign.php`
- `database/backfill_redesign.php`
- SQL equivalents in `database/migrations/`

### What the migration already does

- adds redesign tables without deleting legacy tables
- extends `users`, `facilities`, `vehicles`, and `vehicle_assignments`
- seeds normalized roles
- backfills card accounts from vehicle float data
- imports old logbook entries into `trip_legs`
- imports approved requisitions into `fuel_purchases`
- registers legacy receipt blobs as attachment metadata

### Meaning of this transition design

The system was intentionally designed for phased migration, not a big-bang replacement.

Current migration philosophy:

- keep legacy history intact
- allow new screens to use new tables
- progressively shift operations and reporting

## 9. Current State Assessment

As of April 2, 2026, the system can be described as follows.

### What is already operational

- authentication and session-based access control
- facility-scoped administration
- legacy fuel request and approval flow
- legacy logbook capture
- vehicle, user, and facility management
- float adjustment history
- PDF/Excel export
- redesigned weekly liquidation driver flow
- redesigned facility review flow
- backfill/migration support for historical data

### What is partially transitioned

- pricing model
- card account model
- role normalization
- reporting model
- attachments model

### What is not yet fully completed in the redesign

- redesign-based dashboards and reports
- redesign-based edit/correction request flow
- full cutover away from `requisitions` and `logbook`
- unified card-account transaction ledger
- complete replacement of old receipt blob storage

## 10. Key Technical Debt and Risks

### 10.1 Mixed database access patterns

The codebase uses both `PDO` and `mysqli`. This increases maintenance cost and creates inconsistent query/error handling behavior.

### 10.2 Inconsistent session keys

Some files use:

- `$_SESSION['user_role']`

Other files use:

- `$_SESSION['role']`

This can cause access-control or reporting inconsistencies.

### 10.3 Hardcoded or inconsistent database connection logic

Examples visible in code:

- `logbook.php` creates its own direct `PDO` connection with different credentials
- shared configuration exists in both `db_config.php` and `db_connect.php`

### 10.4 Credentials stored in code

Database credentials are present directly in repository PHP files. This should be moved to environment-based configuration.

### 10.5 Legacy and redesign models overlap

The same business concept appears in multiple structures:

- fuel requests versus actual fuel purchases
- logbook trips versus movement legs
- vehicle float balances versus card accounts
- global price settings versus structured `fuel_prices`

This overlap is manageable during transition, but it increases reporting and reconciliation complexity.

### 10.6 Unfinished redesign surfaces

The schema includes `edit_requests`, but no full UI workflow is currently wired around it.

### 10.7 Non-production-safe test behavior

`user_dashboard.php` currently uses a hardcoded user id for display/testing rather than session-driven authentication.

### 10.8 Reporting still depends on legacy data model

Current reports and exports still center on legacy tables, which means redesign usage is not yet fully reflected in management reporting.

## 11. Planned Update Roadmap

The following roadmap is the most logical next phase based on the current repository state.

### Phase 1: Complete the operational cutover to the redesigned driver flow

Planned updates:

- make `my_vehicle.php` the default driver landing experience
- treat `log_movement_leg.php` and `record_fuel_purchase.php` as the official source for new operational data
- reduce new entry dependence on `request.php` and `logbook.php`
- make legacy pages read-only or historical where appropriate

Expected result:

- new day-to-day operations move fully onto `trip_legs`, `fuel_purchases`, and `weekly_liquidations`

### Phase 2: Move dashboards and reports to redesign tables

Planned updates:

- rebuild management dashboard metrics from:
  - `trip_legs`
  - `fuel_purchases`
  - `weekly_liquidations`
  - `attachments`
- update report exports to use redesign data first
- preserve legacy reporting only for historical periods not yet migrated

Expected result:

- reporting aligns with the actual future workflow instead of the old requisition model

### Phase 3: Implement the edit/correction workflow

Planned updates:

- expose `edit_requests` through UI
- allow drivers or reviewers to request corrections or voids
- keep audit trail of proposed changes and decisions

Expected result:

- corrections stop depending on direct record mutation and become auditable

### Phase 4: Normalize pricing and card-account management

Planned updates:

- replace remaining dependence on `settings.fuel_price`
- use `fuel_prices` per facility and fuel type
- introduce a dedicated card-account transaction ledger if needed
- separate vehicle master data from financial account balance management

Expected result:

- cleaner pricing history, easier auditing, and better control over TOM card operations

### Phase 5: Consolidate technical foundations

Planned updates:

- standardize on one database access layer
- standardize session keys and auth helpers
- move secrets to environment configuration
- reduce duplicated SQL logic across pages
- create shared service/helper functions for recurring operations

Expected result:

- safer production behavior and lower maintenance cost

### Phase 6: Final legacy retirement

Planned updates:

- retire or archive legacy entry screens
- retain legacy tables for audit or historical reference only
- document final source-of-truth tables for all reporting

Expected result:

- one canonical operational workflow and one canonical reporting model

## 12. Recommended Immediate Priorities

If work resumes from the current codebase state, the most valuable next steps are:

1. Finish redesign-based dashboard and reporting.
2. Remove session/config inconsistencies and hardcoded connection logic.
3. Implement UI for `edit_requests`.
4. Consolidate fuel pricing into `fuel_prices`.
5. Define a clear cutover policy for legacy `requisitions` and `logbook`.

## 13. Suggested Source-of-Truth Direction

### Target operational source of truth

- vehicle assignments: `vehicle_assignments`
- vehicle master data: `vehicles`
- card accounts: `card_accounts`
- movement activity: `trip_legs`
- actual fuel usage/purchases: `fuel_purchases`
- weekly submission package: `weekly_liquidations` and `weekly_liquidation_items`
- supporting documents: `attachments`
- review/correction audit: `edit_requests`

### Historical/legacy retention

- `requisitions`
- `logbook`
- `float_transactions`

These should remain available for audit and migration traceability, but not necessarily for ongoing primary operations once the cutover is complete.

## 14. Conclusion

The Fuel Management System is already beyond prototype stage. It contains a functioning legacy fleet fuel workflow and a substantially implemented redesigned weekly liquidation workflow. The main technical challenge is no longer "building from zero"; it is completing the migration cleanly, consolidating the data model, and making reporting/admin surfaces reflect the redesigned operational flow.

The codebase already contains the core building blocks needed for that transition. The next development focus should be on consolidation, reporting cutover, pricing/card normalization, and audit-safe correction workflows.
