<?php
require_once 'db_config.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_price'])) {

    $fuel_type = $_POST['fuel_type']; // petrol or diesel
    $new_price = floatval($_POST['fuel_price']);
    $reason = trim($_POST['reason']);
    $changed_by = 'Admin';

    if (!in_array($fuel_type, ['petrol', 'diesel'])) {
        $message = 'Invalid fuel type selected!';
        $messageType = 'error';
    } elseif (empty($reason)) {
        $message = 'Reason for price change is mandatory!';
        $messageType = 'error';
    } elseif ($new_price <= 0) {
        $message = 'Fuel price must be greater than zero!';
        $messageType = 'error';
    } else {

        // Determine column
        $column = $fuel_type === 'petrol' ? 'petrol_price' : 'diesel_price';

        // Get current price
        $stmt = $pdo->query("SELECT $column FROM settings WHERE id = 1");
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $old_price = floatval($current[$column]);

        if (round($old_price, 2) == round($new_price, 2)) {
            $message = 'New price is the same as current price!';
            $messageType = 'error';
        } else {

            // Update selected fuel price
            $stmt = $pdo->prepare("UPDATE settings SET $column = ?, updated_at = NOW() WHERE id = 1");
            $stmt->execute([$new_price]);

            // Log history
            $stmt = $pdo->prepare("
                INSERT INTO fuel_price_history 
                (fuel_type, old_price, new_price, reason, changed_by) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$fuel_type, $old_price, $new_price, $reason, $changed_by]);

            $message = ucfirst($fuel_type) . " price updated successfully!";
            $messageType = 'success';
        }
    }
}

// Fetch settings
$stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch history
$stmt = $pdo->query("SELECT * FROM fuel_price_history ORDER BY created_at DESC LIMIT 20");
$priceHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Price Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <link rel="stylesheet" type="text/css" href="fuelset.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/fuelset.css')); ?>">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-cog"></i> Fuel Price Settings</h1>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Info Cards price card updated -->
        <div class="info-grid">
            <div class="info-card">
                <h3>Petrol Price</h3>
                <div class="info-value">K <?php echo number_format($settings['petrol_price'], 2); ?></div>
                </div>

                <div class="info-card">
                 <h3>Diesel Price</h3>
                <div class="info-value">K <?php echo number_format($settings['diesel_price'], 2); ?></div>
            </div>

            <div class="info-card">
                <div class="info-icon">
                    <i class="fas fa-gas-pump"></i>
                </div>
                <h3>Available Fuel</h3>
                <div class="info-value"><?php echo number_format($settings['available_fuel'], 2); ?> L</div>
                <div class="info-subtitle">Total Stock</div>
            </div>

            <div class="info-card">
                <div class="info-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Last Updated</h3>
                <div class="info-value" style="font-size: 18px; font-weight: 700; color: var(--gray-700);">
                    <?php echo date('d M Y', strtotime($settings['updated_at'])); ?>
                </div>
                <div class="info-subtitle"><?php echo date('h:i A', strtotime($settings['updated_at'])); ?></div>
            </div>
        </div>

        <!-- Update Form -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-edit"></i> Update Fuel Price
            </div>

            <form method="POST" action="" id="priceForm">
                <div class="form-group">
                    <label>
                        <i class="fas fa-money-bill-wave"></i> New Fuel Price (ZMW per Liter) <span class="required">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="fuel_price" 
                        id="fuel_price" 
                        step="0.01" 
                        min="0.01" 
                        value="<?php echo $settings['fuel_price']; ?>"
                        placeholder="e.g., 28.50"
                        required
                        oninput="updatePreview()"
                    >
                    <div class="helper-text">
                        <i class="fas fa-info-circle"></i>
                        Enter the new fuel price per liter in Zambian Kwacha
                    </div>
                    <div class="form-group">
                       <label><i class="fas fa-money-bill-wave"></i>Select Fuel Type *<span class="required">*</span></label>
                       <select name="fuel_type" required>
                       <option value="petrol">Petrol</option>
                       <option value="diesel">Diesel</option>
                       </select>
                    </div>
                </div>
                

                <!-- Live Price Preview -->
                <div class="price-preview" id="pricePreview">
                    <div class="preview-title">
                        <i class="fas fa-chart-line"></i> Price Change Preview
                    </div>
                    <div class="price-comparison">
                        <span class="old-price" id="previewOld">K 0.00</span>
                        <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                        <span class="new-price" id="previewNew">K 0.00</span>
                    </div>
                    <div style="text-align: center; margin-top: 10px;">
                        <span class="price-change-badge" id="changeBadge">
                            <i class="fas fa-arrow-up"></i>
                            <span id="changeText">0.00%</span>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-comment-alt"></i> Reason for Price Change <span class="required">*</span>
                    </label>
                    <textarea 
                        name="reason" 
                        placeholder="Please provide a detailed reason for changing the fuel price. For example: 'Increase in crude oil prices', 'Exchange rate fluctuation', 'Supplier price adjustment', etc."
                        required
                    ></textarea>
                    <div class="helper-text">
                        <i class="fas fa-info-circle"></i>
                        This reason will be logged and visible in the price change history
                    </div>
                </div>

                <button type="submit" name="update_price" class="submit-btn" onclick="return confirmPriceChange()">
                    <i class="fas fa-save"></i>
                    Update Fuel Price
                </button>
            </form>
        </div>

        <!-- Price History -->
        <div class="history-card">
            <div class="history-title">
                <i class="fas fa-history"></i> Price Change History
            </div>
            
            <?php if (count($priceHistory) > 0): ?>
                <div class="table-container">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar"></i> Date & Time</th>
                                <th><i class="fas fa-exchange-alt"></i> Price Change</th>
                                <th><i class="fas fa-percentage"></i> % Change</th>
                                <th><i class="fas fa-user"></i> Changed By</th>
                                <th><i class="fas fa-comment"></i> Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceHistory as $history): ?>
<?php 
    $change = $history['new_price'] - $history['old_price'];
    if ($history['old_price'] != 0) {
        $changePercent = (($change / $history['old_price']) * 100);
    } else {
        $changePercent = 0;
    }
    $isIncrease = $change > 0;
?>
<tr>
    <td><?php echo date('d M Y, h:i A', strtotime($history['created_at'])); ?></td>
    <td>
        <div class="price-change-cell">
            <span class="price-old">K <?php echo number_format($history['old_price'], 2); ?></span>
            <span class="price-arrow"><i class="fas fa-arrow-right"></i></span>
            <span class="price-new">K <?php echo number_format($history['new_price'], 2); ?></span>
        </div>
    </td>
    <td>
        <span class="change-badge <?php echo $isIncrease ? 'up' : 'down'; ?>">
            <i class="fas fa-arrow-<?php echo $isIncrease ? 'up' : 'down'; ?>"></i>
            <?php echo number_format(abs($changePercent), 2); ?>%
        </span>
    </td>
    <td><?php echo htmlspecialchars($history['changed_by']); ?></td>
    <td><?php echo htmlspecialchars($history['reason']); ?></td>
</tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <p style="font-weight: 600; color: var(--gray-700); margin-top: 10px;">No price change history yet</p>
                    <p style="font-size: 14px; margin-top: 5px;">Price changes will appear here once you update the fuel price</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const currentPrice = <?php echo $settings['fuel_price']; ?>;

        function updatePreview() {
            const newPriceInput = document.getElementById('fuel_price');
            const newPrice = parseFloat(newPriceInput.value) || 0;
            const preview = document.getElementById('pricePreview');
            
            if (newPrice > 0 && newPrice != currentPrice) {
                const change = newPrice - currentPrice;
                const changePercent = ((change / currentPrice) * 100);
                const isIncrease = change > 0;
                
                document.getElementById('previewOld').textContent = 'K ' + currentPrice.toFixed(2);
                document.getElementById('previewNew').textContent = 'K ' + newPrice.toFixed(2);
                document.getElementById('changeText').textContent = Math.abs(changePercent).toFixed(2) + '%';
                
                const badge = document.getElementById('changeBadge');
                badge.className = 'price-change-badge ' + (isIncrease ? 'increase' : 'decrease');
                badge.querySelector('i').className = 'fas fa-arrow-' + (isIncrease ? 'up' : 'down');
                
                preview.classList.add('show');
            } else {
                preview.classList.remove('show');
            }
        }

        function confirmPriceChange() {
            const newPrice = parseFloat(document.getElementById('fuel_price').value);
            const reason = document.querySelector('textarea[name="reason"]').value.trim();
            
            if (!reason) {
                alert('Please provide a reason for the price change.');
                return false;
            }
            
            if (newPrice === currentPrice) {
                alert('The new price is the same as the current price.');
                return false;
            }

            const change = newPrice - currentPrice;
            const changePercent = ((change / currentPrice) * 100);
            const changeText = change > 0 ? 'increase' : 'decrease';
            
            return confirm(
                `Are you sure you want to update the fuel price?\n\n` +
                `Current Price: K ${currentPrice.toFixed(2)}\n` +
                `New Price: K ${newPrice.toFixed(2)}\n` +
                `Change: ${changeText} of ${Math.abs(changePercent).toFixed(2)}%`
            );
        }

        // Auto-hide alert after 5 seconds
        <?php if ($message): ?>
            setTimeout(() => {
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.style.animation = 'slideDown 0.3s reverse';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
