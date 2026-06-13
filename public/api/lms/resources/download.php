<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';
require_once __DIR__ . '/_access.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$resourceId = (int)($_GET['resource_id'] ?? 0);
if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id is required', 422);
}

$pdo = db();
$resource = lms_resource_access_row($pdo, $resourceId);
lms_authorize_resource_access($pdo, $user, $resource);

$fileId = trim((string)($resource['drive_file_id'] ?? ''));
if ($fileId === '') {
    lms_error('not_found', 'This resource does not contain a managed file', 404);
}

$meta = [];
if (!empty($resource['metadata_json'])) {
    $decoded = json_decode((string)$resource['metadata_json'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$stream = fopen('php://temp/maxmemory:2097152', 'w+b');
if (!is_resource($stream)) {
    lms_error('storage_unavailable', 'The file could not be prepared for download', 503);
}

try {
    $storage = lms_drive_storage();
    $remote = $storage->downloadToStream($fileId, $stream);
} catch (DriveStorageException $e) {
    fclose($stream);
    lms_drive_log('drive_download', 'failed', [
        'reason' => $e->reason(),
        'resource_id' => $resourceId,
        'course_id' => (int)$resource['course_id'],
        'user_id' => (int)$user['user_id'],
    ]);
    lms_error('storage_unavailable', 'The requested file is temporarily unavailable', 503);
}

$actualSize = ftell($stream);
rewind($stream);
$hash = hash_init('sha256');
hash_update_stream($hash, $stream);
$actualChecksum = hash_final($hash);
$expectedSize = (int)($resource['file_size'] ?? 0);
$expectedChecksum = strtolower((string)($resource['checksum_sha256'] ?? ''));
$expectedStorageKey = (string)($meta['storage_key'] ?? '');

$integrityOk = $actualSize !== false && lms_drive_download_integrity_ok(
    $fileId,
    $remote,
    (int)$actualSize,
    $actualChecksum,
    $expectedSize,
    $expectedChecksum,
    $expectedStorageKey
);

if (!$integrityOk) {
    fclose($stream);
    lms_drive_log('drive_download_integrity', 'failed', [
        'reason' => 'metadata_or_checksum_mismatch',
        'resource_id' => $resourceId,
        'course_id' => (int)$resource['course_id'],
        'user_id' => (int)$user['user_id'],
    ]);
    lms_error('storage_integrity_error', 'The stored file failed an integrity check', 503);
}

rewind($stream);
$mimeType = strtolower(trim((string)($resource['mime_type'] ?: 'application/octet-stream')));
$requestedDisposition = strtolower(trim((string)($_GET['disposition'] ?? 'attachment')));
$disposition = $requestedDisposition === 'inline' && lms_drive_inline_allowed($mimeType)
    ? 'inline'
    : 'attachment';
if ($disposition === 'attachment' && !lms_drive_inline_allowed($mimeType)) {
    $mimeType = 'application/octet-stream';
}

$filename = trim((string)($meta['original_name'] ?? $resource['title'] ?? 'download'));
$filename = str_replace(["\r", "\n", '"', '\\'], '_', $filename);
if ($filename === '') {
    $filename = 'download';
}
$asciiFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'download';

header_remove('Content-Type');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)$actualSize);
header("Content-Disposition: {$disposition}; filename=\"{$asciiFilename}\"; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

fpassthru($stream);
fclose($stream);
exit;
