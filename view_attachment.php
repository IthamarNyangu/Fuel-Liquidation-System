<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/fleet_redesign.php';

$pdo = fleet_pdo();
$attachmentId = (int) ($_GET['id'] ?? 0);

if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

if (!fleet_schema_ready($pdo)) {
    http_response_code(503);
    exit('Redesign schema not available.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM attachments
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

if ($attachment['storage_method'] === 'db_blob_legacy') {
    if ($attachment['legacy_source_table'] !== 'requisitions' || $attachment['legacy_source_column'] !== 'receipt_data') {
        http_response_code(403);
        exit('Unsupported legacy attachment source.');
    }

    $legacyStmt = $pdo->prepare("
        SELECT receipt_data, receipt_type, receipt_filename
        FROM requisitions
        WHERE id = ?
        LIMIT 1
    ");
    $legacyStmt->execute([(int) $attachment['legacy_source_id']]);
    $legacy = $legacyStmt->fetch();

    if (!$legacy || $legacy['receipt_data'] === null) {
        http_response_code(404);
        exit('Legacy attachment data missing.');
    }

    $filename = $legacy['receipt_filename'] ?: $attachment['original_name'];
    header('Content-Type: ' . ($legacy['receipt_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) $filename)) . '"');
    echo $legacy['receipt_data'];
    exit();
}

$relativePath = (string) ($attachment['storage_path'] ?: '');
$fullPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relativePath);
$rootPath = realpath(__DIR__);

if (!$relativePath || !$fullPath || !$rootPath || strpos($fullPath, $rootPath) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Stored file not found.');
}

header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) ($attachment['original_name'] ?: $attachment['stored_name']))) . '"');
readfile($fullPath);
exit();
