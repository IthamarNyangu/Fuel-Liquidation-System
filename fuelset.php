<?php
require_once 'db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fuel_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fuel_normalize_type(?string $value, string $fallback = 'petrol'): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, ['petrol', 'diesel'], true) ? $value : $fallback;
}

function fuel_source_options(): array
{
    return [
        'erb_revised_pump_prices' => 'ERB Revised Pump Prices',
        'manual_correction' => 'Manual Correction',
        'internal_adjustment' => 'Internal Adjustment',
        'other' => 'Other',
    ];
}

function fuel_source_label(string $value): string
{
    $options = fuel_source_options();
    return $options[$value] ?? 'Other';
}

function fuel_is_valid_date(?string $value, string $format): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    $date = DateTime::createFromFormat($format, $value);
    return $date instanceof DateTime && $date->format($format) === $value;
}

function fuel_format_price(float $amount): string
{
    return 'K ' . number_format($amount, 2);
}

function fuel_format_price_input(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function fuel_format_price_per_liter(float $amount): string
{
    return fuel_format_price($amount) . '/L';
}

function fuel_format_date_label(?string $value, string $fallback = '--'): string
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y', $timestamp);
}

function fuel_format_datetime_label(?string $value, string $fallback = '--'): string
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y, h:i A', $timestamp);
}

function fuel_format_reference_month(?string $value, string $fallback = '--'): string
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    $month = DateTime::createFromFormat('Y-m', $value);
    if ($month instanceof DateTime && $month->format('Y-m') === $value) {
        return $month->format('M Y');
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('M Y', $timestamp);
}

function fuel_current_user_label(PDO $pdo): string
{
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['user_id']]);
            $name = $stmt->fetchColumn();
            if ($name) {
                return (string) $name;
            }
        } catch (Throwable $exception) {
        }
    }

    if (!empty($_SESSION['user_email'])) {
        return (string) $_SESSION['user_email'];
    }

    if (!empty($_SESSION['user_role'])) {
        return ucwords(str_replace('_', ' ', (string) $_SESSION['user_role']));
    }

    return 'Admin';
}

function fuel_build_reason(string $sourceType, string $effectiveDate, string $referenceMonth, string $notes): string
{
    $parts = [
        'Source Type: ' . fuel_source_label($sourceType),
        'Effective Date: ' . $effectiveDate,
    ];

    if ($referenceMonth !== '') {
        $parts[] = 'Reference Month: ' . $referenceMonth;
    }

    if ($notes !== '') {
        $parts[] = 'Notes: ' . $notes;
    }

    return implode("\n", $parts);
}

function fuel_parse_history_reason(string $reason, string $createdAt = ''): array
{
    $meta = [
        'effective_date' => $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '',
        'source' => 'Legacy entry',
        'reference_month' => '',
        'notes' => trim($reason),
    ];

    if (trim($reason) === '') {
        return $meta;
    }

    $recognized = 0;
    $lines = preg_split('/\r\n|\r|\n/', $reason) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (stripos($line, 'Source Type:') === 0) {
            $meta['source'] = trim(substr($line, strlen('Source Type:')));
            $recognized++;
            continue;
        }

        if (stripos($line, 'Effective Date:') === 0) {
            $meta['effective_date'] = trim(substr($line, strlen('Effective Date:')));
            $recognized++;
            continue;
        }

        if (stripos($line, 'Reference Month:') === 0) {
            $meta['reference_month'] = trim(substr($line, strlen('Reference Month:')));
            $recognized++;
            continue;
        }

        if (stripos($line, 'Notes:') === 0) {
            $meta['notes'] = trim(substr($line, strlen('Notes:')));
            $recognized++;
            continue;
        }
    }

    if ($recognized > 0 && $meta['notes'] === '') {
        $meta['notes'] = 'No additional notes';
    }

    return $meta;
}

$message = '';
$messageType = '';
$sourceOptions = fuel_source_options();
$historyFilter = strtolower(trim((string) ($_GET['history_filter'] ?? 'all')));
if (!in_array($historyFilter, ['all', 'petrol', 'diesel'], true)) {
    $historyFilter = 'all';
}
$historyPage = max(1, (int) ($_GET['history_page'] ?? 1));
$historyPerPage = 6;

$settingsStmt = $pdo->query('SELECT * FROM settings WHERE id = 1');
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'petrol_price' => 0,
    'diesel_price' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
];

$defaultFuelType = $historyFilter !== 'all' ? $historyFilter : 'petrol';
$formData = [
    'fuel_type' => fuel_normalize_type($_POST['fuel_type'] ?? $defaultFuelType, $defaultFuelType),
    'fuel_price' => trim((string) ($_POST['fuel_price'] ?? '')),
    'effective_date' => trim((string) ($_POST['effective_date'] ?? date('Y-m-d'))),
    'source_type' => trim((string) ($_POST['source_type'] ?? 'erb_revised_pump_prices')),
    'reference_month' => trim((string) ($_POST['reference_month'] ?? date('Y-m'))),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];

if (!array_key_exists($formData['source_type'], $sourceOptions)) {
    $formData['source_type'] = 'erb_revised_pump_prices';
}

if ($formData['fuel_price'] === '') {
    $currentSelectedPrice = $formData['fuel_type'] === 'diesel'
        ? (float) ($settings['diesel_price'] ?? 0)
        : (float) ($settings['petrol_price'] ?? 0);
    $formData['fuel_price'] = fuel_format_price_input($currentSelectedPrice);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_price'])) {
    $fuelType = $formData['fuel_type'];
    $priceInput = $formData['fuel_price'];
    $effectiveDate = $formData['effective_date'];
    $sourceType = $formData['source_type'];
    $referenceMonth = $formData['source_type'] === 'erb_revised_pump_prices' ? $formData['reference_month'] : '';
    $notes = preg_replace('/\s+/', ' ', $formData['notes'] ?? '');
    $formData['notes'] = trim((string) $notes);
    $changedBy = fuel_current_user_label($pdo);

    if (!in_array($fuelType, ['petrol', 'diesel'], true)) {
        $message = 'Select Petrol or Diesel before saving the price update.';
        $messageType = 'error';
    } elseif ($priceInput === '' || !is_numeric($priceInput)) {
        $message = 'Enter the new price in ZMW per litre.';
        $messageType = 'error';
    } elseif (!fuel_is_valid_date($effectiveDate, 'Y-m-d')) {
        $message = 'Choose a valid effective date.';
        $messageType = 'error';
    } elseif (!array_key_exists($sourceType, $sourceOptions)) {
        $message = 'Choose a valid source type.';
        $messageType = 'error';
    } elseif ($sourceType === 'erb_revised_pump_prices' && !fuel_is_valid_date($referenceMonth, 'Y-m')) {
        $message = 'Reference month is required for ERB pump price updates.';
        $messageType = 'error';
    } else {
        $newPrice = round((float) $priceInput, 2);
        $column = $fuelType === 'petrol' ? 'petrol_price' : 'diesel_price';
        $oldPrice = round((float) ($settings[$column] ?? 0), 2);

        if ($newPrice <= 0) {
            $message = 'Fuel price must be greater than zero.';
            $messageType = 'error';
        } elseif ($newPrice === $oldPrice) {
            $message = 'The new price matches the current price. Enter a different value to save a change.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $updateStmt = $pdo->prepare("UPDATE settings SET $column = ?, updated_at = NOW() WHERE id = 1");
                $updateStmt->execute([$newPrice]);

                $historyReason = fuel_build_reason($sourceType, $effectiveDate, $referenceMonth, $formData['notes']);
                $historyStmt = $pdo->prepare('
                    INSERT INTO fuel_price_history (fuel_type, old_price, new_price, reason, changed_by)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $historyStmt->execute([$fuelType, $oldPrice, $newPrice, $historyReason, $changedBy]);

                $pdo->commit();

                $message = ucfirst($fuelType) . ' price updated successfully.';
                $messageType = 'success';

                $settingsStmt = $pdo->query('SELECT * FROM settings WHERE id = 1');
                $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: $settings;

                $updatedSelectedPrice = $fuelType === 'diesel'
                    ? (float) ($settings['diesel_price'] ?? 0)
                    : (float) ($settings['petrol_price'] ?? 0);
                $formData['fuel_price'] = fuel_format_price_input($updatedSelectedPrice);
                $formData['notes'] = '';
                $formData['reference_month'] = date('Y-m');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = 'The fuel price could not be updated right now. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

$historyParams = [];
$historyWhereSql = '';
if ($historyFilter !== 'all') {
    $historyWhereSql = ' WHERE fuel_type = ?';
    $historyParams[] = $historyFilter;
}
$historyCountStmt = $pdo->prepare('SELECT COUNT(*) FROM fuel_price_history' . $historyWhereSql);
$historyCountStmt->execute($historyParams);
$historyTotalItems = (int) $historyCountStmt->fetchColumn();
$historyTotalPages = max(1, (int) ceil($historyTotalItems / $historyPerPage));
$historyPage = min($historyPage, $historyTotalPages);
$historyOffset = ($historyPage - 1) * $historyPerPage;

$historySql = 'SELECT * FROM fuel_price_history' . $historyWhereSql . ' ORDER BY created_at DESC LIMIT ' . (int) $historyPerPage . ' OFFSET ' . (int) $historyOffset;
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($historyParams);
$priceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedCurrentPrice = (float) ($formData['fuel_type'] === 'diesel' ? ($settings['diesel_price'] ?? 0) : ($settings['petrol_price'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Price Settings</title>
    <link rel="stylesheet" type="text/css" href="fuelset.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/fuelset.css')); ?>">
</head>
<body>
    <div class="fps-page">
        <header class="fps-header">
            <a href="dashboard.php" class="fps-back-link">&larr; Back to Dashboard</a>
            <div class="fps-header-copy">
                <h1>Fuel Price Settings</h1>
                <p>Manage monthly pump prices for Petrol and Diesel.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fps-alert fps-alert-<?php echo fuel_h($messageType); ?>" role="status">
                <?php echo fuel_h($message); ?>
            </div>
        <?php endif; ?>

        <section class="fps-summary-grid" aria-label="Current fuel prices">
            <article class="fps-summary-card">
                <span class="fps-summary-label">Petrol Current Price</span>
                <strong class="fps-summary-value"><?php echo fuel_h(fuel_format_price_per_liter((float) ($settings['petrol_price'] ?? 0))); ?></strong>
                <span class="fps-summary-meta">Current active pump price</span>
            </article>
            <article class="fps-summary-card">
                <span class="fps-summary-label">Diesel Current Price</span>
                <strong class="fps-summary-value"><?php echo fuel_h(fuel_format_price_per_liter((float) ($settings['diesel_price'] ?? 0))); ?></strong>
                <span class="fps-summary-meta">Current active pump price</span>
            </article>
            <article class="fps-summary-card">
                <span class="fps-summary-label">Last Updated</span>
                <strong class="fps-summary-value fps-summary-value-small"><?php echo fuel_h(fuel_format_datetime_label($settings['updated_at'] ?? null)); ?></strong>
                <span class="fps-summary-meta">Settings record last change</span>
            </article>
        </section>

        <section class="fps-panel">
            <div class="fps-panel-header">
                <div>
                    <h2>Update Pump Price</h2>
                    <p>Choose the fuel type first, then record the new price, effective date, and update source.</p>
                </div>
            </div>

            <form method="POST" action="" id="fuelPriceForm" novalidate>
                <div class="fps-fieldset">
                    <span class="fps-label">Fuel Type</span>
                    <div class="fps-segmented" role="radiogroup" aria-label="Fuel Type">
                        <?php foreach (['petrol' => 'Petrol', 'diesel' => 'Diesel'] as $fuelKey => $fuelLabel): ?>
                            <label class="fps-segment">
                                <input type="radio" name="fuel_type" value="<?php echo fuel_h($fuelKey); ?>" <?php echo $formData['fuel_type'] === $fuelKey ? 'checked' : ''; ?>>
                                <span><?php echo fuel_h($fuelLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fps-current-context">
                    <div>
                        <span class="fps-context-label">Updating</span>
                        <strong class="fps-context-value" id="selectedFuelLabel"><?php echo fuel_h(ucfirst($formData['fuel_type'])); ?></strong>
                    </div>
                    <div>
                        <span class="fps-context-label">Current Price</span>
                        <strong class="fps-context-value" id="currentPriceValue"><?php echo fuel_h(fuel_format_price_per_liter($selectedCurrentPrice)); ?></strong>
                    </div>
                </div>

                <div class="fps-form-grid">
                    <div class="fps-field">
                        <label class="fps-label" for="fuel_price">New Price (ZMW/L)</label>
                        <div class="fps-input-wrap">
                            <span class="fps-prefix">K</span>
                            <input
                                class="fps-input fps-input-with-prefix"
                                type="number"
                                name="fuel_price"
                                id="fuel_price"
                                step="0.01"
                                min="0.01"
                                placeholder="e.g. 29.40"
                                value="<?php echo fuel_h($formData['fuel_price']); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="fps-field">
                        <label class="fps-label" for="effective_date">Effective Date</label>
                        <input
                            class="fps-input"
                            type="date"
                            name="effective_date"
                            id="effective_date"
                            value="<?php echo fuel_h($formData['effective_date']); ?>"
                            required
                        >
                    </div>

                    <div class="fps-field">
                        <label class="fps-label" for="source_type">Source Type</label>
                        <select class="fps-input" name="source_type" id="source_type" required>
                            <?php foreach ($sourceOptions as $sourceKey => $sourceLabel): ?>
                                <option value="<?php echo fuel_h($sourceKey); ?>" <?php echo $formData['source_type'] === $sourceKey ? 'selected' : ''; ?>>
                                    <?php echo fuel_h($sourceLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php $showReferenceMonth = $formData['source_type'] === 'erb_revised_pump_prices'; ?>
                    <div class="fps-field" id="referenceMonthField" <?php echo $showReferenceMonth ? '' : 'hidden'; ?>>
                        <label class="fps-label" for="reference_month">Reference Month</label>
                        <input
                            class="fps-input"
                            type="month"
                            name="reference_month"
                            id="reference_month"
                            value="<?php echo fuel_h($formData['reference_month']); ?>"
                            <?php echo $showReferenceMonth ? '' : 'disabled'; ?>
                        >
                    </div>

                    <div class="fps-field fps-field-full">
                        <label class="fps-label" for="notes">Additional Notes / Reason</label>
                        <textarea
                            class="fps-input fps-textarea"
                            name="notes"
                            id="notes"
                            placeholder="Optional notes about the ERB notice, correction, or internal reason."
                        ><?php echo fuel_h($formData['notes']); ?></textarea>
                    </div>
                </div>

                <div class="fps-preview" id="pricePreview" hidden>
                    <div class="fps-preview-header">
                        <span class="fps-label">Change Preview</span>
                        <span class="fps-badge fps-badge-neutral" id="changeBadge">0.00%</span>
                    </div>
                    <div class="fps-preview-values">
                        <span class="fps-preview-old" id="previewOld">K 0.00/L</span>
                        <span class="fps-preview-arrow">to</span>
                        <span class="fps-preview-new" id="previewNew">K 0.00/L</span>
                    </div>
                </div>

                <div class="fps-form-actions">
                    <button type="submit" name="update_price" class="fps-primary-button">Save Price Update</button>
                </div>
            </form>
        </section>

        <section class="fps-panel">
            <div class="fps-panel-header fps-panel-header-split">
                <div>
                    <h2>Price History</h2>
                    <p>Review recent price revisions and keep Petrol and Diesel changes easy to trace.</p>
                </div>
                <div class="fps-filter-chips" aria-label="History filters">
                    <?php foreach (['all' => 'All', 'petrol' => 'Petrol', 'diesel' => 'Diesel'] as $filterKey => $filterLabel): ?>
                        <a
                            href="fuelset.php?history_filter=<?php echo urlencode($filterKey); ?>"
                            class="fps-filter-chip <?php echo $historyFilter === $filterKey ? 'is-active' : ''; ?>"
                        >
                            <?php echo fuel_h($filterLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($priceHistory)): ?>
                <div class="fps-table-wrap">
                    <table class="fps-history-table">
                        <thead>
                            <tr>
                                <th>Effective Date</th>
                                <th>Fuel Type</th>
                                <th>Previous Price</th>
                                <th>New Price</th>
                                <th>% Change</th>
                                <th>Updated By</th>
                                <th>Source</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceHistory as $history): ?>
                                <?php
                                $oldPrice = (float) ($history['old_price'] ?? 0);
                                $newPrice = (float) ($history['new_price'] ?? 0);
                                $delta = $newPrice - $oldPrice;
                                $changePercent = $oldPrice > 0 ? ($delta / $oldPrice) * 100 : ($newPrice > 0 ? 100 : 0);
                                $changeClass = 'fps-badge-neutral';
                                if ($delta > 0) {
                                    $changeClass = 'fps-badge-warning';
                                } elseif ($delta < 0) {
                                    $changeClass = 'fps-badge-positive';
                                }
                                $meta = fuel_parse_history_reason((string) ($history['reason'] ?? ''), (string) ($history['created_at'] ?? ''));
                                $fuelType = fuel_normalize_type($history['fuel_type'] ?? 'petrol');
                                ?>
                                <tr>
                                    <td><?php echo fuel_h(fuel_format_date_label($meta['effective_date'])); ?></td>
                                    <td>
                                        <span class="fps-type-chip fps-type-<?php echo fuel_h($fuelType); ?>">
                                            <?php echo fuel_h(ucfirst($fuelType)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo fuel_h(fuel_format_price_per_liter($oldPrice)); ?></td>
                                    <td><?php echo fuel_h(fuel_format_price_per_liter($newPrice)); ?></td>
                                    <td>
                                        <span class="fps-badge <?php echo fuel_h($changeClass); ?>">
                                            <?php echo fuel_h(number_format(abs($changePercent), 2)); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo fuel_h($history['changed_by'] ?? 'System'); ?></td>
                                    <td>
                                        <div class="fps-source-copy"><?php echo fuel_h($meta['source']); ?></div>
                                        <?php if ($meta['reference_month'] !== ''): ?>
                                            <div class="fps-table-meta">Ref month: <?php echo fuel_h(fuel_format_reference_month($meta['reference_month'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fps-notes-copy"><?php echo fuel_h($meta['notes']); ?></div>
                                        <div class="fps-table-meta">Logged <?php echo fuel_h(fuel_format_datetime_label($history['created_at'] ?? null)); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($historyTotalPages > 1): ?>
                    <div class="fps-pagination" aria-label="Price history pages">
                        <?php if ($historyPage > 1): ?>
                            <a class="fps-page-link" href="fuelset.php?<?php echo http_build_query(['history_filter' => $historyFilter, 'history_page' => $historyPage - 1]); ?>">Previous</a>
                        <?php endif; ?>

                        <?php for ($page = 1; $page <= $historyTotalPages; $page++): ?>
                            <a
                                class="fps-page-link <?php echo $page === $historyPage ? 'is-active' : ''; ?>"
                                href="fuelset.php?<?php echo http_build_query(['history_filter' => $historyFilter, 'history_page' => $page]); ?>"
                            >
                                <?php echo fuel_h($page); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($historyPage < $historyTotalPages): ?>
                            <a class="fps-page-link" href="fuelset.php?<?php echo http_build_query(['history_filter' => $historyFilter, 'history_page' => $historyPage + 1]); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="fps-empty-state">
                    <h3>No price changes found</h3>
                    <p>Once a Petrol or Diesel price is updated, the revision history will appear here.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        const fuelPrices = {
            petrol: <?php echo json_encode((float) ($settings['petrol_price'] ?? 0)); ?>,
            diesel: <?php echo json_encode((float) ($settings['diesel_price'] ?? 0)); ?>
        };

        const fuelLabels = {
            petrol: 'Petrol',
            diesel: 'Diesel'
        };

        function getSelectedFuelType() {
            const selected = document.querySelector('input[name="fuel_type"]:checked');
            return selected ? selected.value : 'petrol';
        }

        function formatPrice(amount) {
            return 'K ' + Number(amount).toFixed(2) + '/L';
        }

        function formatPriceInputValue(amount) {
            return Number(amount).toFixed(2);
        }

        function syncPriceInputToCurrent(force = false) {
            const fuelType = getSelectedFuelType();
            const currentPrice = fuelPrices[fuelType] || 0;
            const priceInput = document.getElementById('fuel_price');

            if (force || priceInput.value.trim() === '' || Number(priceInput.value) <= 0) {
                priceInput.value = formatPriceInputValue(currentPrice);
            }
        }

        function toggleReferenceMonthField() {
            const sourceType = document.getElementById('source_type').value;
            const field = document.getElementById('referenceMonthField');
            const input = document.getElementById('reference_month');
            const shouldShow = sourceType === 'erb_revised_pump_prices';

            field.hidden = !shouldShow;
            input.disabled = !shouldShow;
        }

        function updatePricePreview() {
            const fuelType = getSelectedFuelType();
            const currentPrice = fuelPrices[fuelType] || 0;
            const newPriceInput = document.getElementById('fuel_price');
            const newPrice = parseFloat(newPriceInput.value || '0');
            const preview = document.getElementById('pricePreview');
            const badge = document.getElementById('changeBadge');

            document.getElementById('selectedFuelLabel').textContent = fuelLabels[fuelType];
            document.getElementById('currentPriceValue').textContent = formatPrice(currentPrice);
            document.getElementById('previewOld').textContent = formatPrice(currentPrice);

            if (!newPrice || newPrice <= 0 || newPrice === currentPrice) {
                preview.hidden = true;
                document.getElementById('previewNew').textContent = formatPrice(currentPrice);
                badge.textContent = '0.00%';
                badge.className = 'fps-badge fps-badge-neutral';
                return;
            }

            const changePercent = currentPrice > 0
                ? ((newPrice - currentPrice) / currentPrice) * 100
                : 100;
            badge.textContent = Math.abs(changePercent).toFixed(2) + '%';
            badge.className = 'fps-badge ' + (changePercent >= 0 ? 'fps-badge-warning' : 'fps-badge-positive');
            document.getElementById('previewNew').textContent = formatPrice(newPrice);
            preview.hidden = false;
        }

        function confirmPriceChange() {
            const fuelType = getSelectedFuelType();
            const sourceSelect = document.getElementById('source_type');
            const currentPrice = fuelPrices[fuelType] || 0;
            const priceValue = document.getElementById('fuel_price').value;
            const newPrice = parseFloat(priceValue || '0');
            const effectiveDate = document.getElementById('effective_date').value;
            const referenceMonthInput = document.getElementById('reference_month');

            if (!newPrice || newPrice <= 0) {
                alert('Enter the new price before saving.');
                return false;
            }

            if (newPrice === currentPrice) {
                alert('The new price matches the current price. Enter a different value.');
                return false;
            }

            if (!effectiveDate) {
                alert('Choose the effective date for this price update.');
                return false;
            }

            if (sourceSelect.value === 'erb_revised_pump_prices' && !referenceMonthInput.value) {
                alert('Reference month is required for ERB pump price updates.');
                return false;
            }

            return window.confirm(
                'Save ' + fuelLabels[fuelType] + ' price update?\n\n' +
                'Current Price: ' + formatPrice(currentPrice) + '\n' +
                'New Price: ' + formatPrice(newPrice) + '\n' +
                'Effective Date: ' + effectiveDate
            );
        }

        document.querySelectorAll('input[name="fuel_type"]').forEach((input) => {
            input.addEventListener('change', function () {
                syncPriceInputToCurrent(true);
                updatePricePreview();
            });
        });

        document.getElementById('fuel_price').addEventListener('input', updatePricePreview);
        document.getElementById('source_type').addEventListener('change', toggleReferenceMonthField);
        document.getElementById('fuelPriceForm').addEventListener('submit', function (event) {
            if (!confirmPriceChange()) {
                event.preventDefault();
            }
        });

        syncPriceInputToCurrent();
        toggleReferenceMonthField();
        updatePricePreview();

        <?php if ($message !== ''): ?>
        window.setTimeout(function () {
            const alert = document.querySelector('.fps-alert');
            if (alert) {
                alert.remove();
            }
        }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
