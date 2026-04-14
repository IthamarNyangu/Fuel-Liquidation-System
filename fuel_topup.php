<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'auth_check.php';
require_once 'facility_auth.php';
require_once 'db_config.php';

function topup_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function topup_currency(float $amount): string
{
    return 'K ' . number_format($amount, 2);
}

function topup_transaction_label(string $type): string
{
    $labels = [
        'addition' => 'Add Balance',
        'deduction' => 'Deduct Balance',
        'adjustment' => 'Adjustment',
    ];

    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function topup_transaction_class(string $type): string
{
    if ($type === 'addition') {
        return 'is-addition';
    }

    if ($type === 'deduction') {
        return 'is-deduction';
    }

    return 'is-neutral';
}

function topup_reason_options(): array
{
    return [
        'monthly_allocation' => 'Monthly allocation',
        'emergency_top_up' => 'Emergency top-up',
        'correction' => 'Correction',
        'reversal' => 'Reversal',
        'approved_deduction' => 'Approved deduction',
        'other' => 'Other',
    ];
}

function topup_fuel_type_label(?string $value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === 'petrol' || $value === 'diesel') {
        return ucfirst($value);
    }

    return 'Not set';
}

function topup_vehicle_display(array $vehicle): string
{
    $vehicleName = trim((string) ($vehicle['vehicle_name'] ?? ''));
    $plateNumber = trim((string) ($vehicle['number_plate'] ?? ''));

    if ($vehicleName !== '' && $plateNumber !== '') {
        return $vehicleName . ' · ' . $plateNumber;
    }

    if ($vehicleName !== '') {
        return $vehicleName;
    }

    if ($plateNumber !== '') {
        return $plateNumber;
    }

    return 'Unknown vehicle';
}

function topup_is_valid_date(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date instanceof DateTime && $date->format('Y-m-d') === $value;
}

function topup_fetch_vehicles(PDO $pdo, bool $isSuperAdmin, ?int $facilityId): array
{
    $baseSql = "
        SELECT
            v.id,
            v.vehicle_name,
            v.number_plate,
            COALESCE(v.float_balance, 0) AS float_balance,
            COALESCE(NULLIF(v.float_account_name, ''), 'Not assigned') AS float_account_name,
            COALESCE(v.fuel_type, '') AS fuel_type,
            COALESCE(f.facility_name, 'Unassigned Facility') AS facility_name
        FROM vehicles v
        LEFT JOIN facilities f ON v.facility_id = f.id
    ";

    if ($isSuperAdmin) {
        $stmt = $pdo->query($baseSql . ' ORDER BY v.vehicle_name, v.number_plate');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare($baseSql . ' WHERE v.facility_id = ? ORDER BY v.vehicle_name, v.number_plate');
    $stmt->execute([$facilityId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function topup_build_vehicle_lookup(array $vehicles): array
{
    $lookup = [];
    foreach ($vehicles as $vehicle) {
        $lookup[(string) $vehicle['id']] = $vehicle;
    }

    return $lookup;
}

function topup_fetch_facility_display(PDO $pdo, bool $isSuperAdmin, ?int $facilityId): string
{
    if ($isSuperAdmin) {
        return 'All Facilities';
    }

    $stmt = $pdo->prepare('SELECT facility_name FROM facilities WHERE id = ? LIMIT 1');
    $stmt->execute([$facilityId]);
    $facilityName = $stmt->fetchColumn();

    return $facilityName ? (string) $facilityName : 'Your Facility';
}

function topup_history_page_url(int $page, string $vehicleFilter, string $transactionFilter, string $dateFrom, string $dateTo): string
{
    $query = ['history_page' => $page];

    if ($vehicleFilter !== '') {
        $query['vehicle_filter'] = $vehicleFilter;
    }

    if ($transactionFilter !== '' && $transactionFilter !== 'all') {
        $query['transaction_filter'] = $transactionFilter;
    }

    if ($dateFrom !== '') {
        $query['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $query['date_to'] = $dateTo;
    }

    return 'fuel_topup.php?' . http_build_query($query);
}

$is_super_admin = isSuperAdmin();
$user_facility_id = getUserFacilityId();
$user_role = $_SESSION['user_role'] ?? 'staff';
$user_name = $_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? 'User');

if (!$is_super_admin && !$user_facility_id) {
    die('Error: Your account is not assigned to a facility. Please contact your administrator.');
}

if (!in_array($user_role, ['super_admin', 'admin', 'facility_admin'], true)) {
    die("Access denied: You don't have permission to access this page.");
}

$message = '';
$messageType = '';
$reasonOptions = topup_reason_options();

$vehicles = topup_fetch_vehicles($pdo, $is_super_admin, $user_facility_id ? (int) $user_facility_id : null);
$vehicleLookup = topup_build_vehicle_lookup($vehicles);
$total_balance = array_sum(array_map(static function (array $vehicle): float {
    return (float) ($vehicle['float_balance'] ?? 0);
}, $vehicles));
$facility_display = topup_fetch_facility_display($pdo, $is_super_admin, $user_facility_id ? (int) $user_facility_id : null);

$postedAdjustmentType = (string) ($_POST['adjustment_type'] ?? 'addition');
$postedReasonType = (string) ($_POST['reason_type'] ?? 'monthly_allocation');

$formData = [
    'vehicle_id' => trim((string) ($_POST['vehicle_id'] ?? '')),
    'adjustment_type' => in_array($postedAdjustmentType, ['addition', 'deduction'], true)
        ? $postedAdjustmentType
        : 'addition',
    'amount' => trim((string) ($_POST['amount'] ?? '')),
    'reason_type' => array_key_exists($postedReasonType, $reasonOptions)
        ? $postedReasonType
        : 'monthly_allocation',
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_fuel'])) {
    $vehicle_id = $formData['vehicle_id'];
    $adjustment_type = $formData['adjustment_type'];
    $amount = abs((float) $formData['amount']);
    $reason_type = $formData['reason_type'];
    $notes = trim((string) preg_replace('/\s+/', ' ', $formData['notes']));
    $formData['notes'] = $notes;

    $photo_data = null;
    $photo_filename = null;
    $photo_type = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $photo_data = file_get_contents($_FILES['attachment']['tmp_name']);
        $photo_filename = $_FILES['attachment']['name'];
        $photo_type = $_FILES['attachment']['type'];
    }

    if ($vehicle_id === '' || !isset($vehicleLookup[$vehicle_id])) {
        $message = 'Select a valid vehicle before saving the transaction.';
        $messageType = 'error';
    } elseif (!array_key_exists($reason_type, $reasonOptions)) {
        $message = 'Select a valid reason type.';
        $messageType = 'error';
    } elseif ($reason_type === 'other' && $notes === '') {
        $message = 'Add notes when the reason type is Other.';
        $messageType = 'error';
    } elseif ($amount <= 0) {
        $message = 'Amount must be greater than zero.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT vehicle_name, float_balance, COALESCE(NULLIF(float_account_name, ''), 'Not assigned') AS float_account_name
                FROM vehicles
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([(int) $vehicle_id]);
            $vehicleData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicleData) {
                $message = 'Vehicle not found.';
                $messageType = 'error';
            } else {
                $previousBalance = round((float) ($vehicleData['float_balance'] ?? 0), 2);
                $newBalance = $adjustment_type === 'addition'
                    ? round($previousBalance + $amount, 2)
                    : round($previousBalance - $amount, 2);

                if ($adjustment_type === 'deduction' && $newBalance < 0) {
                    $message = 'Cannot deduct more than the current balance. Current: ' . topup_currency($previousBalance) . ', Attempted: ' . topup_currency($amount);
                    $messageType = 'error';
                } else {
                    $reason = $reasonOptions[$reason_type];
                    if ($notes !== '') {
                        $reason .= ' | ' . $notes;
                    }

                    $pdo->beginTransaction();

                    $updateStmt = $pdo->prepare('UPDATE vehicles SET float_balance = ? WHERE id = ?');
                    $updateStmt->execute([$newBalance, (int) $vehicle_id]);

                    $insertStmt = $pdo->prepare("
                        INSERT INTO float_transactions
                        (float_account, transaction_type, amount, previous_balance, new_balance, reason, created_by, photo_data, photo_filename, photo_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([
                        $vehicleData['float_account_name'],
                        $adjustment_type,
                        $amount,
                        $previousBalance,
                        $newBalance,
                        $reason,
                        $user_name,
                        $photo_data,
                        $photo_filename,
                        $photo_type,
                    ]);

                    $pdo->commit();

                    $actionText = $adjustment_type === 'addition' ? 'added to' : 'deducted from';
                    $message = topup_currency($amount) . ' ' . $actionText . ' ' . $vehicleData['vehicle_name'] . '. New balance: ' . topup_currency($newBalance);
                    $messageType = 'success';

                    $vehicles = topup_fetch_vehicles($pdo, $is_super_admin, $user_facility_id ? (int) $user_facility_id : null);
                    $vehicleLookup = topup_build_vehicle_lookup($vehicles);
                    $total_balance = array_sum(array_map(static function (array $vehicle): float {
                        return (float) ($vehicle['float_balance'] ?? 0);
                    }, $vehicles));

                    $formData['amount'] = '';
                    $formData['reason_type'] = 'monthly_allocation';
                    $formData['notes'] = '';
                }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = 'The transaction could not be saved right now. Please try again.';
            $messageType = 'error';
        }
    }
}

$historyVehicleFilter = trim((string) ($_GET['vehicle_filter'] ?? ''));
if ($historyVehicleFilter !== '' && !isset($vehicleLookup[$historyVehicleFilter])) {
    $historyVehicleFilter = '';
}

$historyTransactionFilter = trim((string) ($_GET['transaction_filter'] ?? 'all'));
if (!in_array($historyTransactionFilter, ['all', 'addition', 'deduction', 'adjustment'], true)) {
    $historyTransactionFilter = 'all';
}

$historyDateFrom = trim((string) ($_GET['date_from'] ?? ''));
if ($historyDateFrom !== '' && !topup_is_valid_date($historyDateFrom)) {
    $historyDateFrom = '';
}

$historyDateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($historyDateTo !== '' && !topup_is_valid_date($historyDateTo)) {
    $historyDateTo = '';
}

$historyPageSize = 6;
$historyPage = max(1, (int) ($_GET['history_page'] ?? 1));

$historyBaseSql = "
    FROM float_transactions ft
    LEFT JOIN vehicles v ON ft.float_account = v.float_account_name
    LEFT JOIN facilities f ON v.facility_id = f.id
    WHERE ft.transaction_type IN ('addition', 'deduction', 'adjustment')
";
$historyParams = [];

if (!$is_super_admin) {
    $historyBaseSql .= ' AND v.facility_id = ?';
    $historyParams[] = (int) $user_facility_id;
}

if ($historyVehicleFilter !== '') {
    $historyBaseSql .= ' AND v.id = ?';
    $historyParams[] = (int) $historyVehicleFilter;
}

if ($historyTransactionFilter !== 'all') {
    $historyBaseSql .= ' AND ft.transaction_type = ?';
    $historyParams[] = $historyTransactionFilter;
}

if ($historyDateFrom !== '') {
    $historyBaseSql .= ' AND DATE(ft.created_at) >= ?';
    $historyParams[] = $historyDateFrom;
}

if ($historyDateTo !== '') {
    $historyBaseSql .= ' AND DATE(ft.created_at) <= ?';
    $historyParams[] = $historyDateTo;
}

$historyCountStmt = $pdo->prepare('SELECT COUNT(*) ' . $historyBaseSql);
$historyCountStmt->execute($historyParams);
$historyTotalItems = (int) $historyCountStmt->fetchColumn();
$historyTotalPages = max(1, (int) ceil($historyTotalItems / $historyPageSize));
$historyPage = min($historyPage, $historyTotalPages);
$historyOffset = ($historyPage - 1) * $historyPageSize;

$historySql = "
    SELECT
        ft.*,
        v.id AS vehicle_id,
        v.vehicle_name,
        v.number_plate,
        COALESCE(f.facility_name, 'Unassigned Facility') AS facility_name
" . $historyBaseSql . ' ORDER BY ft.created_at DESC LIMIT ' . (int) $historyPageSize . ' OFFSET ' . (int) $historyOffset;
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($historyParams);
$adjustmentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedVehicle = $formData['vehicle_id'] !== '' && isset($vehicleLookup[$formData['vehicle_id']])
    ? $vehicleLookup[$formData['vehicle_id']]
    : null;
$historyFromItem = $historyTotalItems > 0 ? ($historyOffset + 1) : 0;
$historyToItem = $historyTotalItems > 0 ? min($historyOffset + count($adjustmentHistory), $historyTotalItems) : 0;
$historyPageStart = max(1, $historyPage - 2);
$historyPageEnd = min($historyTotalPages, $historyPage + 2);

if (($historyPageEnd - $historyPageStart) < 4) {
    $historyPageStart = max(1, $historyPageEnd - 4);
    $historyPageEnd = min($historyTotalPages, $historyPageStart + 4);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Fuel Top-Up</title>
    <link rel="stylesheet" type="text/css" href="fuel_topup.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/fuel_topup.css')); ?>">
</head>
<body>
    <div class="vft-page">
        <header class="vft-header">
            <a href="dashboard.php" class="vft-back-link">Back to Dashboard</a>
            <div class="vft-header-copy">
                <h1>Vehicle Fuel Top-Up</h1>
                <p>Manage TOM card balance adjustments for vehicles.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="vft-alert vft-alert-<?php echo topup_h($messageType); ?>" role="status">
                <?php echo topup_h($message); ?>
            </div>
        <?php endif; ?>

        <section class="vft-summary-grid" aria-label="Balance summary">
            <article class="vft-summary-card">
                <span class="vft-summary-label">Vehicles in Scope</span>
                <strong class="vft-summary-value"><?php echo topup_h((string) count($vehicles)); ?></strong>
                <span class="vft-summary-meta">Available for balance adjustment</span>
            </article>
            <article class="vft-summary-card">
                <span class="vft-summary-label">Total Float Balance</span>
                <strong class="vft-summary-value vft-summary-value-balance"><?php echo topup_h(topup_currency((float) $total_balance)); ?></strong>
                <span class="vft-summary-meta">Combined balance for vehicles in scope</span>
            </article>
        </section>

        <section class="vft-panel">
            <div class="vft-panel-heading">
                <h2>Balance Adjustment</h2>
                <p>Choose the vehicle, decide whether you are adding or deducting balance, then record the reason and supporting document.</p>
            </div>
            <?php if (!empty($vehicles)): ?>
                <form method="POST" action="" enctype="multipart/form-data" id="topupForm">
                    <section class="vft-section">
                        <div class="vft-section-heading">
                            <h3>Transaction Details</h3>
                            <p>Capture the vehicle, transaction type, and amount first.</p>
                        </div>

                        <div class="vft-field-grid vft-field-grid-3">
                            <div class="vft-field">
                                <label class="vft-label" for="vehicle_select">Vehicle</label>
                                <select class="vft-input" name="vehicle_id" id="vehicle_select" required>
                                    <option value="">Select vehicle</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <?php $vehicleId = (string) $vehicle['id']; ?>
                                        <option
                                            value="<?php echo topup_h($vehicleId); ?>"
                                            data-vehicle="<?php echo topup_h(topup_vehicle_display($vehicle)); ?>"
                                            data-facility="<?php echo topup_h($vehicle['facility_name']); ?>"
                                            data-fuel-type="<?php echo topup_h(topup_fuel_type_label($vehicle['fuel_type'] ?? '')); ?>"
                                            data-balance="<?php echo topup_h(number_format((float) $vehicle['float_balance'], 2, '.', '')); ?>"
                                            data-account="<?php echo topup_h($vehicle['float_account_name']); ?>"
                                            <?php echo $formData['vehicle_id'] === $vehicleId ? 'selected' : ''; ?>
                                        >
                                            <?php echo topup_h(topup_vehicle_display($vehicle)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="vft-field">
                                <span class="vft-label">Transaction Type</span>
                                <div class="vft-segmented" role="radiogroup" aria-label="Transaction Type">
                                    <label class="vft-segment is-addition">
                                        <input type="radio" name="adjustment_type" value="addition" <?php echo $formData['adjustment_type'] === 'addition' ? 'checked' : ''; ?>>
                                        <span>Add Balance</span>
                                    </label>
                                    <label class="vft-segment is-deduction">
                                        <input type="radio" name="adjustment_type" value="deduction" <?php echo $formData['adjustment_type'] === 'deduction' ? 'checked' : ''; ?>>
                                        <span>Deduct Balance</span>
                                    </label>
                                </div>
                            </div>

                            <div class="vft-field">
                                <label class="vft-label" for="amount">Amount (ZMW)</label>
                                <div class="vft-input-wrap">
                                    <span class="vft-prefix">K</span>
                                    <input
                                        class="vft-input vft-input-with-prefix"
                                        type="number"
                                        name="amount"
                                        id="amount"
                                        step="0.01"
                                        min="0.01"
                                        placeholder="e.g. 500.00"
                                        value="<?php echo topup_h($formData['amount']); ?>"
                                        required
                                    >
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="vft-section">
                        <div class="vft-section-heading">
                            <h3>Context and Explanation</h3>
                            <p>Review the selected vehicle context and record why the balance is changing.</p>
                        </div>

                        <div class="vft-context-placeholder" id="vehicleContextEmpty" <?php echo $selectedVehicle ? 'hidden' : ''; ?>>
                            Select a vehicle to load the facility, fuel type, current balance, and card account.
                        </div>

                        <div class="vft-context-grid" id="vehicleContext" <?php echo $selectedVehicle ? '' : 'hidden'; ?>>
                            <article class="vft-context-item">
                                <span class="vft-context-label">Vehicle</span>
                                <strong id="contextVehicle"><?php echo $selectedVehicle ? topup_h(topup_vehicle_display($selectedVehicle)) : '--'; ?></strong>
                            </article>
                            <article class="vft-context-item">
                                <span class="vft-context-label">Facility</span>
                                <strong id="contextFacility"><?php echo $selectedVehicle ? topup_h($selectedVehicle['facility_name']) : '--'; ?></strong>
                            </article>
                            <article class="vft-context-item">
                                <span class="vft-context-label">Fuel Type</span>
                                <strong id="contextFuelType"><?php echo $selectedVehicle ? topup_h(topup_fuel_type_label($selectedVehicle['fuel_type'] ?? '')) : '--'; ?></strong>
                            </article>
                            <article class="vft-context-item">
                                <span class="vft-context-label">Current Balance</span>
                                <strong id="contextBalance"><?php echo $selectedVehicle ? topup_h(topup_currency((float) $selectedVehicle['float_balance'])) : '--'; ?></strong>
                            </article>
                            <article class="vft-context-item">
                                <span class="vft-context-label">Card Account</span>
                                <strong id="contextAccount"><?php echo $selectedVehicle ? topup_h($selectedVehicle['float_account_name']) : '--'; ?></strong>
                            </article>
                        </div>

                        <div class="vft-balance-preview" id="balancePreview" hidden>
                            <span class="vft-balance-label">Balance Preview</span>
                            <div class="vft-balance-equation">
                                <span id="previewCurrent">K 0.00</span>
                                <span class="vft-balance-operator" id="previewOperator">+</span>
                                <span id="previewAmount">K 0.00</span>
                                <span class="vft-balance-equals">=</span>
                                <span class="vft-balance-result" id="previewResult">K 0.00</span>
                            </div>
                            <p class="vft-balance-note" id="balanceNote">Projected balance after this transaction.</p>
                        </div>

                        <div class="vft-field-grid vft-field-grid-2">
                            <div class="vft-field">
                                <label class="vft-label" for="reason_type">Reason Type</label>
                                <select class="vft-input" name="reason_type" id="reason_type" required>
                                    <?php foreach ($reasonOptions as $reasonKey => $reasonLabel): ?>
                                        <option value="<?php echo topup_h($reasonKey); ?>" <?php echo $formData['reason_type'] === $reasonKey ? 'selected' : ''; ?>>
                                            <?php echo topup_h($reasonLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="vft-field vft-field-full">
                                <label class="vft-label" for="notes">Notes</label>
                                <textarea
                                    class="vft-input vft-textarea"
                                    name="notes"
                                    id="notes"
                                    placeholder="Add approval references, month, or any explanation that supports this adjustment."
                                ><?php echo topup_h($formData['notes']); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="vft-section">
                        <div class="vft-section-heading">
                            <h3>Attachment</h3>
                            <p>Upload an approval, receipt, or supporting document if one is available.</p>
                        </div>

                        <div class="vft-upload-shell" id="uploadShell">
                            <label for="attachment" class="vft-upload-trigger">Choose File</label>
                            <div class="vft-upload-copy">
                                <strong id="fileName">No file selected</strong>
                                <span>PDF, JPG, PNG up to 5MB</span>
                            </div>
                        </div>
                        <input
                            class="vft-file-input"
                            type="file"
                            id="attachment"
                            name="attachment"
                            accept="image/*,.pdf"
                        >
                    </section>

                    <section class="vft-section vft-section-actions">
                        <button type="submit" name="adjust_fuel" class="vft-primary-button" id="submitButton">
                            <?php echo $formData['adjustment_type'] === 'deduction' ? 'Save Deduction' : 'Save Addition'; ?>
                        </button>
                    </section>
                </form>
            <?php else: ?>
                <div class="vft-empty-state">
                    <h3>No vehicles available</h3>
                    <p>There are no vehicles in <?php echo topup_h(strtolower($facility_display)); ?> yet, so no balance transaction can be recorded here.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="vft-panel">
            <div class="vft-panel-heading vft-panel-heading-split">
                <div>
                    <h2>Transaction History</h2>
                    <p>Review recent balance movements with compact audit details and optional filters.</p>
                </div>

                <form method="GET" class="vft-history-toolbar">
                    <select class="vft-input" name="vehicle_filter">
                        <option value="">All vehicles</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <?php $vehicleId = (string) $vehicle['id']; ?>
                            <option value="<?php echo topup_h($vehicleId); ?>" <?php echo $historyVehicleFilter === $vehicleId ? 'selected' : ''; ?>>
                                <?php echo topup_h(topup_vehicle_display($vehicle)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="vft-input" name="transaction_filter">
                        <option value="all" <?php echo $historyTransactionFilter === 'all' ? 'selected' : ''; ?>>All transactions</option>
                        <option value="addition" <?php echo $historyTransactionFilter === 'addition' ? 'selected' : ''; ?>>Add Balance</option>
                        <option value="deduction" <?php echo $historyTransactionFilter === 'deduction' ? 'selected' : ''; ?>>Deduct Balance</option>
                        <option value="adjustment" <?php echo $historyTransactionFilter === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                    </select>

                    <input class="vft-input" type="date" name="date_from" value="<?php echo topup_h($historyDateFrom); ?>">
                    <input class="vft-input" type="date" name="date_to" value="<?php echo topup_h($historyDateTo); ?>">

                    <button type="submit" class="vft-secondary-button">Apply</button>
                    <a href="fuel_topup.php" class="vft-clear-link">Clear</a>
                </form>
            </div>

            <?php if (!empty($adjustmentHistory)): ?>
                <div class="vft-table-wrap">
                    <table class="vft-history-table">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Vehicle</th>
                                <th>Transaction</th>
                                <th class="is-currency">Amount (K)</th>
                                <th class="is-currency">New Balance (K)</th>
                                <th>Reason</th>
                                <th>Recorded By</th>
                                <th>Attachment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustmentHistory as $history): ?>
                                <?php
                                $vehicleLabel = trim((string) ($history['vehicle_name'] ?? '')) !== ''
                                    ? topup_vehicle_display($history)
                                    : trim((string) ($history['float_account'] ?? 'Unknown vehicle'));
                                $transactionType = (string) ($history['transaction_type'] ?? 'adjustment');
                                ?>
                                <tr>
                                    <td><?php echo topup_h(date('d M Y, H:i', strtotime((string) $history['created_at']))); ?></td>
                                    <td>
                                        <div class="vft-table-primary"><?php echo topup_h($vehicleLabel); ?></div>
                                        <?php if (!empty($history['facility_name'])): ?>
                                            <div class="vft-table-meta"><?php echo topup_h($history['facility_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="vft-badge <?php echo topup_h(topup_transaction_class($transactionType)); ?>">
                                            <?php echo topup_h(topup_transaction_label($transactionType)); ?>
                                        </span>
                                    </td>
                                    <td class="is-currency"><?php echo topup_h(topup_currency((float) $history['amount'])); ?></td>
                                    <td class="is-currency is-strong"><?php echo topup_h(topup_currency((float) $history['new_balance'])); ?></td>
                                    <td>
                                        <div class="vft-reason-copy"><?php echo topup_h((string) $history['reason']); ?></div>
                                    </td>
                                    <td><?php echo topup_h((string) $history['created_by']); ?></td>
                                    <td>
                                        <?php if (!empty($history['photo_data'])): ?>
                                            <a href="view_adjustment_photo.php?id=<?php echo topup_h((string) $history['id']); ?>" target="_blank" class="vft-attachment-link">View</a>
                                        <?php else: ?>
                                            <span class="vft-table-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($historyTotalItems > 0): ?>
                    <div class="vft-pagination">
                        <div class="vft-pagination-summary">
                            Showing <?php echo topup_h((string) $historyFromItem); ?> to <?php echo topup_h((string) $historyToItem); ?> of <?php echo topup_h((string) $historyTotalItems); ?> transactions
                        </div>
                        <?php if ($historyTotalPages > 1): ?>
                            <nav class="vft-pagination-links" aria-label="History pagination">
                                <?php if ($historyPage > 1): ?>
                                    <a class="vft-page-link" href="<?php echo topup_h(topup_history_page_url($historyPage - 1, $historyVehicleFilter, $historyTransactionFilter, $historyDateFrom, $historyDateTo)); ?>">Previous</a>
                                <?php endif; ?>

                                <?php for ($page = $historyPageStart; $page <= $historyPageEnd; $page++): ?>
                                    <a
                                        class="vft-page-link <?php echo $page === $historyPage ? 'is-active' : ''; ?>"
                                        href="<?php echo topup_h(topup_history_page_url($page, $historyVehicleFilter, $historyTransactionFilter, $historyDateFrom, $historyDateTo)); ?>"
                                    >
                                        <?php echo topup_h((string) $page); ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($historyPage < $historyTotalPages): ?>
                                    <a class="vft-page-link" href="<?php echo topup_h(topup_history_page_url($historyPage + 1, $historyVehicleFilter, $historyTransactionFilter, $historyDateFrom, $historyDateTo)); ?>">Next</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="vft-empty-state">
                    <h3>No transactions found</h3>
                    <p>Balance adjustments will appear here once you start managing TOM card balances.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script>
        function getSelectedVehicleOption() {
            const select = document.getElementById('vehicle_select');
            if (!select || !select.value) {
                return null;
            }

            return select.options[select.selectedIndex];
        }

        function getSelectedTransactionType() {
            const selected = document.querySelector('input[name="adjustment_type"]:checked');
            return selected ? selected.value : 'addition';
        }

        function formatCurrency(amount) {
            return 'K ' + Number(amount).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function updateVehicleContext() {
            const option = getSelectedVehicleOption();
            const emptyState = document.getElementById('vehicleContextEmpty');
            const context = document.getElementById('vehicleContext');

            if (!option) {
                emptyState.hidden = false;
                context.hidden = true;
                return;
            }

            emptyState.hidden = true;
            context.hidden = false;

            document.getElementById('contextVehicle').textContent = option.dataset.vehicle || '--';
            document.getElementById('contextFacility').textContent = option.dataset.facility || '--';
            document.getElementById('contextFuelType').textContent = option.dataset.fuelType || '--';
            document.getElementById('contextBalance').textContent = formatCurrency(option.dataset.balance || 0);
            document.getElementById('contextAccount').textContent = option.dataset.account || '--';
        }

        function updateSubmitButton() {
            const button = document.getElementById('submitButton');
            if (!button) {
                return;
            }

            const type = getSelectedTransactionType();
            button.textContent = type === 'deduction' ? 'Save Deduction' : 'Save Addition';
        }

        function updateBalancePreview() {
            const option = getSelectedVehicleOption();
            const amountInput = document.getElementById('amount');
            const preview = document.getElementById('balancePreview');
            const transactionType = getSelectedTransactionType();
            const amount = parseFloat(amountInput.value || '0');

            if (!preview) {
                return;
            }

            if (!option || !amount || amount <= 0) {
                preview.hidden = true;
                return;
            }

            const currentBalance = parseFloat(option.dataset.balance || '0');
            const projectedBalance = transactionType === 'addition'
                ? currentBalance + amount
                : currentBalance - amount;

            document.getElementById('previewCurrent').textContent = formatCurrency(currentBalance);
            document.getElementById('previewAmount').textContent = formatCurrency(amount);
            document.getElementById('previewOperator').textContent = transactionType === 'addition' ? '+' : '-';
            document.getElementById('previewResult').textContent = formatCurrency(projectedBalance);

            preview.classList.toggle('is-addition', transactionType === 'addition');
            preview.classList.toggle('is-deduction', transactionType === 'deduction');

            if (projectedBalance < 0) {
                document.getElementById('balanceNote').textContent = 'This deduction would result in a negative balance and will not save.';
            } else {
                document.getElementById('balanceNote').textContent = 'Projected balance after this transaction.';
            }

            preview.hidden = false;
        }

        function handleFileSelect(input) {
            const shell = document.getElementById('uploadShell');
            const fileName = document.getElementById('fileName');

            if (!shell || !fileName) {
                return;
            }

            if (!input.files || !input.files[0]) {
                shell.classList.remove('has-file');
                fileName.textContent = 'No file selected';
                return;
            }

            const file = input.files[0];
            const sizeInMb = file.size / 1024 / 1024;

            if (sizeInMb > 5) {
                alert('File size must be less than 5MB.');
                input.value = '';
                shell.classList.remove('has-file');
                fileName.textContent = 'No file selected';
                return;
            }

            shell.classList.add('has-file');
            fileName.textContent = file.name;
        }

        const vehicleSelect = document.getElementById('vehicle_select');
        const amountInput = document.getElementById('amount');
        const attachmentInput = document.getElementById('attachment');

        if (vehicleSelect) {
            vehicleSelect.addEventListener('change', function () {
                updateVehicleContext();
                updateBalancePreview();
            });
        }

        document.querySelectorAll('input[name="adjustment_type"]').forEach((input) => {
            input.addEventListener('change', function () {
                updateSubmitButton();
                updateBalancePreview();
            });
        });

        if (amountInput) {
            amountInput.addEventListener('input', updateBalancePreview);
        }

        if (attachmentInput) {
            attachmentInput.addEventListener('change', function () {
                handleFileSelect(this);
            });
        }

        updateVehicleContext();
        updateSubmitButton();
        updateBalancePreview();

        <?php if ($message !== ''): ?>
        window.setTimeout(function () {
            const alert = document.querySelector('.vft-alert');
            if (alert) {
                alert.remove();
            }
        }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
