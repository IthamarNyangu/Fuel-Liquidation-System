<?php
// download_receipt.php
require_once __DIR__ . '/../db_config.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT receipt_data, receipt_type, receipt_filename FROM requisitions WHERE id = ?");
$stmt->execute([$id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if ($receipt && $receipt['receipt_data']) {
    header('Content-Type: ' . $receipt['receipt_type']);
    header('Content-Disposition: inline; filename="' . $receipt['receipt_filename'] . '"');
    echo $receipt['receipt_data'];
} else {
    header("HTTP/1.0 404 Not Found");
    echo "Receipt not found";
}
?>
