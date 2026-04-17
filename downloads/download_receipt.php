<?php
// download_receipt.php - Download/view receipts stored in database

// Database configuration
require_once __DIR__ . '/../db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get requisition ID from URL
$req_id = $_GET['id'] ?? 0;

if (!$req_id || !is_numeric($req_id)) {
    header('HTTP/1.0 400 Bad Request');
    die("Invalid request ID");
}

// Fetch receipt data from database
$stmt = $pdo->prepare("
    SELECT receipt_data, receipt_filename, receipt_type 
    FROM requisitions 
    WHERE id = ?
");
$stmt->execute([$req_id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if receipt exists
if (!$receipt) {
    header('HTTP/1.0 404 Not Found');
    die("Receipt not found");
}

if (!$receipt['receipt_data']) {
    header('HTTP/1.0 404 Not Found');
    die("No receipt attached to this requisition");
}

// Determine if we should display inline or force download
$disposition = 'inline'; // Default to inline (view in browser)

// For certain file types, force download
$filename = $receipt['receipt_filename'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx'])) {
    $disposition = 'attachment'; // Force download for Office documents
}

// Clear any previous output
if (ob_get_level()) {
    ob_end_clean();
}

// Set appropriate headers
header('Content-Type: ' . $receipt['receipt_type']);
header('Content-Disposition: ' . $disposition . '; filename="' . $receipt['receipt_filename'] . '"');
header('Content-Length: ' . strlen($receipt['receipt_data']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Output the file content
echo $receipt['receipt_data'];
exit;
?>
