<?php
$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/auth_check.php';
require_once $appRoot . '/fleet_redesign.php';

$pdo = fleet_pdo();
$user = fleet_current_user_context();
fleet_require_driver_access($user);

if (!fleet_schema_ready($pdo)) {
    fleet_render_schema_required($user, 'Record Fuel Purchase', 'fuel_purchase');
}

$vehicles = fleet_get_accessible_vehicles($pdo, $user);
$selectedVehicleId = isset($_REQUEST['vehicle_id']) ? (int) $_REQUEST['vehicle_id'] : null;
$selectedVehicle = fleet_pick_vehicle($vehicles, $selectedVehicleId);
$pageError = '';

$cardAccounts = [];
if ($selectedVehicle) {
    $cardStmt = $pdo->prepare("
        SELECT *
        FROM card_accounts
        WHERE status = 'active'
          AND (
              vehicle_id = ?
              OR (vehicle_id IS NULL AND facility_id = ?)
          )
        ORDER BY
            CASE WHEN vehicle_id IS NULL THEN 1 ELSE 0 END,
            account_name
    ");
    $cardStmt->execute([
        $selectedVehicle['id'],
        $selectedVehicle['facility_id'],
    ]);
    $cardAccounts = $cardStmt->fetchAll();
    if (!$cardAccounts && !empty($selectedVehicle['float_account_name'])) {
        $syncedCard = fleet_ensure_vehicle_card_account($pdo, $selectedVehicle, $user['id']);
        if ($syncedCard) {
            $cardAccounts = [$syncedCard];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedVehicle) {
    $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
    $weekStart = fleet_week_start_from_date($purchaseDate);
    $cardAccountId = (int) ($_POST['card_account_id'] ?? 0);
    $stationName = trim((string) ($_POST['station_name'] ?? ''));
    $receiptNumber = trim((string) ($_POST['receipt_number'] ?? ''));
    $odometerAtRefillRaw = trim((string) ($_POST['odometer_at_refill_km'] ?? ''));
    $litres = (float) ($_POST['litres'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $movedFilePath = null;

    try {
        if ($cardAccountId <= 0) {
            throw new RuntimeException('A TOM card account is required for each fuel purchase.');
        }

        if ($stationName === '' || $receiptNumber === '') {
            throw new RuntimeException('Station name and receipt number are required.');
        }

        if (!fleet_is_valid_km_input($odometerAtRefillRaw)) {
            throw new RuntimeException('Refill odometer must be a whole number.');
        }

        if ($litres <= 0 || $amount <= 0) {
            throw new RuntimeException('Litres and amount must both be greater than zero.');
        }

        $odometerAtRefill = fleet_km_value($odometerAtRefillRaw);

        if (!isset($_FILES['receipt_attachment']) || $_FILES['receipt_attachment']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('A receipt attachment is required.');
        }

        if ($_FILES['receipt_attachment']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The receipt upload failed. Please try again.');
        }

        if ($_FILES['receipt_attachment']['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Receipt attachment must be 5 MB or smaller.');
        }

        $existingWeekly = fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart);
        if ($existingWeekly && !fleet_week_is_editable($existingWeekly)) {
            throw new RuntimeException('This week has already been submitted or approved. It must be returned before you can add more fuel purchases.');
        }

        $latestKnown = fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']);
        if ($odometerAtRefill < $latestKnown) {
            throw new RuntimeException('Refill odometer cannot be lower than the last known vehicle reading of ' . fleet_format_km($latestKnown) . '.');
        }

        $selectedCard = null;
        foreach ($cardAccounts as $cardAccount) {
            if ((int) $cardAccount['id'] === $cardAccountId) {
                $selectedCard = $cardAccount;
                break;
            }
        }

        if (!$selectedCard) {
            throw new RuntimeException('The selected card account is not valid for this vehicle or facility.');
        }

        if (!empty($selectedVehicle['fuel_type']) && !empty($selectedCard['fuel_type']) && $selectedVehicle['fuel_type'] !== $selectedCard['fuel_type']) {
            throw new RuntimeException('The selected card fuel type does not match the vehicle fuel type.');
        }

        $issueNotes = [];
        $previousCardBalance = isset($selectedCard['current_balance'])
            ? round((float) $selectedCard['current_balance'], 2)
            : round((float) ($selectedVehicle['float_balance'] ?? 0), 2);
        $newCardBalance = round($previousCardBalance - $amount, 2);
        if ($newCardBalance < 0) {
            $issueNotes[] = 'Recorded fuel amount pushes the TOM card balance below zero. Verify whether a top-up is still outstanding.';
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedType = $finfo->file($_FILES['receipt_attachment']['tmp_name']) ?: $_FILES['receipt_attachment']['type'];
        if (!isset($allowedMimeTypes[$detectedType])) {
            throw new RuntimeException('Only JPG, PNG, and PDF receipt uploads are allowed.');
        }

        $uploadDirectory = $appRoot . '/uploads/receipts/' . date('Y') . '/' . date('m');
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Could not create the receipt upload directory.');
        }

        $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($receiptNumber));
        $storedFilename = $safeBaseName . '-' . uniqid('', true) . '.' . $allowedMimeTypes[$detectedType];
        $targetPath = $uploadDirectory . '/' . $storedFilename;
        if (!move_uploaded_file($_FILES['receipt_attachment']['tmp_name'], $targetPath)) {
            throw new RuntimeException('Could not save the uploaded receipt file.');
        }
        $movedFilePath = $targetPath;

        if ($odometerAtRefill > $latestKnown) {
            $issueNotes[] = 'Refill odometer is ahead of the last known reading. Check whether a movement leg is still missing before this refuel.';
        }

        $pdo->beginTransaction();

        $insertStmt = $pdo->prepare("
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
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recorded', ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $selectedVehicle['id'],
            $user['id'],
            $selectedVehicle['facility_id'],
            $cardAccountId,
            $purchaseDate,
            $weekStart,
            $stationName,
            $receiptNumber,
            $odometerAtRefill,
            $litres,
            round($amount / $litres, 2),
            $amount,
            $notes ?: null,
            $issueNotes ? 1 : 0,
            $issueNotes ? implode(' ', $issueNotes) : null,
            $user['id'],
            $user['id'],
        ]);

        $fuelPurchaseId = (int) $pdo->lastInsertId();

        $relativeStoragePath = str_replace('\\', '/', substr($targetPath, strlen($appRoot) + 1));
        $attachmentStmt = $pdo->prepare("
            INSERT INTO attachments (
                fuel_purchase_id,
                attachment_type,
                storage_method,
                original_name,
                stored_name,
                storage_path,
                mime_type,
                file_size_bytes,
                uploaded_by
            ) VALUES (?, 'receipt', 'file', ?, ?, ?, ?, ?, ?)
        ");
        $attachmentStmt->execute([
            $fuelPurchaseId,
            $_FILES['receipt_attachment']['name'],
            $storedFilename,
            $relativeStoragePath,
            $detectedType,
            (int) $_FILES['receipt_attachment']['size'],
            $user['id'],
        ]);

        $vehicleUpdateStmt = $pdo->prepare("
            UPDATE vehicles
            SET current_mileage = CASE
                WHEN current_mileage IS NULL OR current_mileage < ? THEN ?
                ELSE current_mileage
            END,
                float_balance = ?
            WHERE id = ?
        ");
        $vehicleUpdateStmt->execute([$odometerAtRefill, $odometerAtRefill, $newCardBalance, $selectedVehicle['id']]);

        $cardBalanceStmt = $pdo->prepare("
            UPDATE card_accounts
            SET current_balance = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $cardBalanceStmt->execute([$newCardBalance, $cardAccountId]);

        $weekly = fleet_ensure_weekly_liquidation(
            $pdo,
            $user['id'],
            (int) $selectedVehicle['id'],
            $selectedVehicle['facility_id'] ? (int) $selectedVehicle['facility_id'] : null,
            $weekStart
        );
        fleet_attach_item_to_weekly($pdo, (int) $weekly['id'], 'fuel_purchase', $fuelPurchaseId);
        fleet_recalculate_weekly($pdo, (int) $weekly['id']);

        $pdo->commit();

        fleet_set_flash(
            'success',
            'Fuel purchase saved for ' . $selectedVehicle['vehicle_name'] . ' (' . $selectedVehicle['number_plate'] . '). Remaining card balance: ' . fleet_currency($newCardBalance) . '.'
        );
        header('Location: record_fuel_purchase.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($movedFilePath && is_file($movedFilePath)) {
            @unlink($movedFilePath);
        }
        $pageError = $e->getMessage();
    }
}

$weekStart = fleet_week_start_from_date($_GET['week_start'] ?? date('Y-m-d'));
$latestKnown = $selectedVehicle ? fleet_get_latest_vehicle_odometer($pdo, (int) $selectedVehicle['id']) : 0;
$weekly = $selectedVehicle ? fleet_find_weekly_liquidation($pdo, $user['id'], (int) $selectedVehicle['id'], $weekStart) : null;
if ($weekly) {
    $weekly = fleet_recalculate_weekly($pdo, (int) $weekly['id']);
}

$recentFuelPurchases = [];
if ($selectedVehicle) {
    $recentStmt = $pdo->prepare("
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
        LIMIT 6
    ");
    $recentStmt->execute([$user['id'], $selectedVehicle['id'], $weekStart]);
    $recentFuelPurchases = $recentStmt->fetchAll();
}

fleet_render_shell_start(
    'Record Fuel Purchase',
    'fuel_purchase',
    $user,
    'Fuel purchases stay separate from movement legs. Capture the actual refill, TOM card used, receipt details, receipt attachment, and odometer at refill.'
);

if ($pageError !== '') {
    echo '<div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><span>' . fleet_h($pageError) . '</span></div>';
}

if (!$selectedVehicle) {
    echo '<section class="panel empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-receipt"></i></div>';
    echo '<h2>No vehicle available</h2>';
    echo '<p>A fuel purchase can only be recorded against an assigned or accessible vehicle. Once a vehicle is assigned, return here and the form will be ready.</p>';
    echo '</section>';
    fleet_render_shell_end();
    exit();
}

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>Select Vehicle</h2><p>Pick the vehicle first, then record the refill details exactly as they appear on the receipt.</p></div>';
echo '<a class="inline-link-button" href="my_vehicle.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-car-side"></i>Back to My Vehicle</a>';
echo '</div>';
echo '<div class="vehicle-switcher">';
foreach ($vehicles as $vehicle) {
    $isActive = (int) $vehicle['id'] === (int) $selectedVehicle['id'];
    echo '<a class="vehicle-pill' . ($isActive ? ' active' : '') . '" href="record_fuel_purchase.php?vehicle_id=' . urlencode((string) $vehicle['id']) . '&week_start=' . urlencode($weekStart) . '">';
    echo '<i class="fas fa-car-side"></i><span>' . fleet_h($vehicle['vehicle_name']) . ' · ' . fleet_h($vehicle['number_plate']) . '</span></a>';
}
echo '</div>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header-split">';
echo '<div><h2>' . fleet_h($selectedVehicle['vehicle_name']) . ' &middot; ' . fleet_h($selectedVehicle['number_plate']) . '</h2><p>Latest known odometer is ' . fleet_format_km($latestKnown) . '. The refill odometer must not be below that value.</p></div>';
if ($weekly) {
    echo '<span class="status-pill ' . fleet_h($weekly['status']) . '">' . fleet_h($weekly['status']) . '</span>';
}
echo '</div>';

if (!$cardAccounts) {
    echo '<div class="alert alert-info"><i class="fas fa-circle-info"></i><span>No active TOM card account is configured for this vehicle or facility yet. Add one after running the redesign migration/backfill or set it up in master data first.</span></div>';
}

echo '<form method="post" enctype="multipart/form-data" class="form-grid" novalidate>';
echo '<input type="hidden" name="vehicle_id" value="' . fleet_h($selectedVehicle['id']) . '">';
echo '<div class="form-group span-3"><label for="purchase_date">Purchase Date</label><input id="purchase_date" type="date" name="purchase_date" value="' . fleet_h($_POST['purchase_date'] ?? date('Y-m-d')) . '" required></div>';
echo '<div class="form-group span-3"><label for="vehicle_fuel_type">Fuel Type</label><input id="vehicle_fuel_type" type="text" value="' . fleet_h($selectedVehicle['fuel_type'] ? ucfirst((string) $selectedVehicle['fuel_type']) : 'Not set') . '" readonly><div class="input-hint">Loaded from the selected vehicle record.</div></div>';
echo '<div class="form-group span-3"><label for="card_account_id">TOM Card Account</label><select id="card_account_id" name="card_account_id" required><option value="">Select card</option>';
foreach ($cardAccounts as $account) {
    $selected = ((int) ($_POST['card_account_id'] ?? 0) === (int) $account['id']) ? ' selected' : '';
    echo '<option value="' . fleet_h($account['id']) . '"' . $selected . '>' . fleet_h($account['account_name']) . ($account['fuel_type'] ? ' · ' . fleet_h($account['fuel_type']) : '') . '</option>';
}
echo '</select></div>';
echo '<div class="form-group span-3"><label for="odometer_at_refill_km">Odometer at Refill</label><input id="odometer_at_refill_km" type="number" step="1" min="0" name="odometer_at_refill_km" placeholder="e.g. 12000" value="' . fleet_h($_POST['odometer_at_refill_km'] ?? fleet_km_input_value($latestKnown)) . '" required></div>';
echo '<div class="form-group span-6"><label for="station_name">Station</label><input id="station_name" type="text" name="station_name" value="' . fleet_h($_POST['station_name'] ?? '') . '" placeholder="Fuel station name" required></div>';
echo '<div class="form-group span-6"><label for="receipt_number">Receipt Number</label><input id="receipt_number" type="text" name="receipt_number" value="' . fleet_h($_POST['receipt_number'] ?? '') . '" placeholder="Receipt or invoice number" required></div>';
echo '<div class="form-group span-4"><label for="litres">Litres</label><input id="litres" type="number" step="0.01" min="0" name="litres" value="' . fleet_h($_POST['litres'] ?? '') . '" required></div>';
echo '<div class="form-group span-4"><label for="amount">Amount (ZMW)</label><input id="amount" type="number" step="0.01" min="0" name="amount" value="' . fleet_h($_POST['amount'] ?? '') . '" required></div>';
echo '<div class="form-group span-4"><label for="unit_price_preview">Unit Price</label><input id="unit_price_preview" type="text" value="K 0.00" readonly><div class="input-hint">Calculated automatically from amount divided by litres.</div></div>';
echo '<div class="form-group span-6"><label for="receipt_attachment">Receipt Attachment</label><input id="receipt_attachment" type="file" name="receipt_attachment" accept=".jpg,.jpeg,.png,.pdf" required><div class="input-hint">Allowed: JPG, PNG, PDF. Max 5 MB.</div></div>';
echo '<div class="form-group span-6"><label for="notes">Notes</label><textarea id="notes" name="notes" placeholder="Optional notes about the refill">' . fleet_h($_POST['notes'] ?? '') . '</textarea></div>';
echo '<div class="form-group span-12"><div class="button-row"><button class="button" type="submit" ' . (!$cardAccounts ? 'disabled' : '') . '><i class="fas fa-save"></i>Save Fuel Purchase</button><a class="button-secondary" href="pending_reconciliations.php?vehicle_id=' . urlencode((string) $selectedVehicle['id']) . '&week_start=' . urlencode($weekStart) . '"><i class="fas fa-clipboard-check"></i>Pending Reconciliations</a></div></div>';
echo '</form>';
echo '</section>';

echo '<section class="panel">';
echo '<div class="panel-header"><div><h2>Current Fuel Purchases</h2><p>Every saved purchase stays pending until you include it in a reconciliation package and submit it for review.</p></div></div>';
if (!$recentFuelPurchases) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fas fa-receipt"></i></div>';
    echo '<h3>No purchases recorded yet</h3>';
    echo '<p>Once you save the first refill and attach its receipt, it will appear here and stay pending until you submit it inside a reconciliation package.</p>';
    echo '</div>';
} else {
    echo '<div class="data-list">';
    foreach ($recentFuelPurchases as $purchase) {
        echo '<article class="data-item">';
        echo '<div class="data-item-header">';
        echo '<div><h3 class="data-item-title">' . fleet_h($purchase['station_name']) . '</h3><p class="data-item-meta">' . date('D, d M Y', strtotime($purchase['purchase_date'])) . ' · Receipt ' . fleet_h($purchase['receipt_number']) . ' · ' . fleet_h($purchase['account_name'] ?: 'Card not linked') . '</p></div>';
        echo '<span class="status-pill ' . fleet_h($purchase['record_status']) . '">' . fleet_h($purchase['record_status']) . '</span>';
        echo '</div>';
        echo '<div class="data-item-grid">';
        echo '<div class="detail-pair"><span class="detail-pair-label">Litres</span><span class="detail-pair-value">' . number_format((float) $purchase['litres'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Amount</span><span class="detail-pair-value">K ' . number_format((float) $purchase['amount'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Unit Price</span><span class="detail-pair-value">K ' . number_format((float) $purchase['unit_price'], 2) . '</span></div>';
        echo '<div class="detail-pair"><span class="detail-pair-label">Refill Odometer</span><span class="detail-pair-value">' . fleet_format_km($purchase['odometer_at_refill_km']) . '</span></div>';
        echo '</div>';
        echo '<div class="button-row" style="margin-top:12px;">';
        if (!empty($purchase['receipt_attachment_id'])) {
            echo '<a class="button-ghost" href="view_attachment.php?id=' . urlencode((string) $purchase['receipt_attachment_id']) . '" target="_blank"><i class="fas fa-paperclip"></i>View Receipt</a>';
        }
        echo '</div>';
        if (!empty($purchase['issue_notes'])) {
            echo '<p class="helper-text issue-hint"><i class="fas fa-triangle-exclamation"></i> ' . fleet_h($purchase['issue_notes']) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
}
echo '</section>';

echo '<script>';
echo 'const litresInput=document.getElementById("litres");';
echo 'const amountInput=document.getElementById("amount");';
echo 'const unitPricePreview=document.getElementById("unit_price_preview");';
echo 'function updateUnitPricePreview(){const litres=parseFloat(litresInput.value)||0;const amount=parseFloat(amountInput.value)||0;const price=litres>0?amount/litres:0;unitPricePreview.value="K "+price.toFixed(2);}';
echo 'litresInput.addEventListener("input",updateUnitPricePreview);';
echo 'amountInput.addEventListener("input",updateUnitPricePreview);';
echo 'updateUnitPricePreview();';
echo '</script>';

fleet_render_shell_end();

