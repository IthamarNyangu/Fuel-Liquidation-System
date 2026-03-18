# Fleet Redesign Migration Notes

This package introduces the new normalized fleet model without deleting the old operational tables.

## Files

- `20260318_001_fleet_redesign_schema.sql`
  Adds the new schema and extends a few legacy tables with forward-looking fields.
- `20260318_002_legacy_backfill.sql`
  Copies legacy operational data into the new transactional tables.

## What Changes Immediately

- Existing tables remain in place:
  - `users`
  - `facilities`
  - `vehicles`
  - `vehicle_assignments`
  - `requisitions`
  - `logbook`
  - `float_transactions`
- New tables are added for the redesigned workflow:
  - `roles`
  - `card_accounts`
  - `fuel_prices`
  - `trip_legs`
  - `fuel_purchases`
  - `weekly_liquidations`
  - `weekly_liquidation_items`
  - `edit_requests`
  - `attachments`

## Status Model

The redesign separates workflow state from validation state.

- `trip_legs.record_status`
  - `draft`
  - `recorded`
  - `locked`
  - `voided`
- `fuel_purchases.record_status`
  - `draft`
  - `recorded`
  - `locked`
  - `voided`
- `weekly_liquidations.status`
  - `draft`
  - `submitted`
  - `under_review`
  - `returned`
  - `approved`

`flagged` is not a main status in the redesign. It is represented by:

- `has_issues`
- `issue_notes`

This keeps the workflow easy to reason about:

- `locked` means the row belongs to a submitted/reviewed liquidation and should not be edited directly
- `voided` means the row was cancelled but retained for audit
- `has_issues = 1` means the row needs attention but still exists in a normal lifecycle state

## Legacy Mapping

### `logbook` -> `trip_legs`

- `log_date` -> `movement_date`
- `location_from` -> `from_location`
- `location_to` -> `to_location`
- `purpose` -> `purpose`
- `time_out` -> `time_out`
- `time_in` -> `time_in`
- `start_kms` -> `odometer_start_km`
- `end_kms` -> `odometer_end_km`
- `total_kms` -> `total_km`
- `approver_id` is not mapped to `confirmed_by_name`

Important:

- old logbook rows did not store the real-world passenger/requesting officer signature or confirmation
- imported rows are therefore marked with `has_issues = 1`

### `requisitions` -> `fuel_purchases`

Only approved requisitions are imported as historical fuel purchases.

- `staff_id` -> `driver_id`
- `vehicle_id` -> `vehicle_id`
- `facility_id` -> `facility_id`
- `request_date` -> `purchase_date`
- `requested_amount` -> `litres`
- `fuel_price_per_liter` -> `unit_price`
- calculated total -> `amount`
- `filling_station` -> `station_name`
- `receipt_number` -> `receipt_number`
- `mileage` -> `odometer_at_refill_km`
- `notes` -> `notes`

Important:

- pending or rejected requisitions stay in the old table for audit/history
- they are not automatically converted into purchases

### `float_transactions` -> `card_accounts`

The redesign uses `card_accounts` as the TOM-card/float layer.

- one card account is created per vehicle from `vehicles.float_account_name`
- current balance is seeded from `vehicles.float_balance`
- `float_transactions` remains available as legacy ledger history

If later needed, add a dedicated `card_account_transactions` table instead of reusing `float_transactions`.

## Receipt Attachments

Legacy requisition receipts currently live in `requisitions.receipt_data` as blobs.

The new `attachments` table supports two storage methods:

- `file`
- `db_blob_legacy`

The backfill script creates attachment metadata rows that point back to the legacy blob using:

- `legacy_source_table`
- `legacy_source_id`
- `legacy_source_column`

That lets the application read old receipts without forcing an immediate file export.

## Suggested Rollout Order

1. Back up the current `fuel` database.
2. Run `php database/migrate_redesign.php`.
3. Review newly added nullable columns in:
   - `users`
   - `facilities`
   - `vehicles`
   - `vehicle_assignments`
4. Fill missing master data that cannot be safely guessed:
   - `vehicles.fuel_type`
   - `vehicles.asset_type` where motorcycles exist
   - `facilities.province_name`
5. Run `php database/backfill_redesign.php`.
6. Validate a sample of:
   - imported trip legs
   - imported fuel purchases
   - legacy blob receipts in `attachments`
7. Build the new PHP screens against the new tables while keeping old screens read-only during transition.

## Notes For The Next PHP Phase

The safest implementation order in the app is:

1. `My Vehicle`
2. `Log Movement Leg`
3. `Record Fuel Purchase`
4. `Weekly Draft / Review`
5. `Submit Weekly Liquidation`
6. Facility admin review pages

This keeps drivers on the new flow first, while admin reporting can catch up after the data model is stable.


