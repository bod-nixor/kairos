<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$resourceId = (int)($in['resource_id'] ?? 0);
if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id required', 422);
}

$pdo = db();
$resourceStmt = $pdo->prepare('SELECT resource_id, course_id, drive_file_id, access_scope, metadata_json, deleted_at FROM lms_resources WHERE resource_id = :id LIMIT 1');
$resourceStmt->execute([':id' => $resourceId]);
$resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
if (!$resource) {
    lms_error('not_found', 'Resource not found', 404);
}

lms_course_access($user, (int)$resource['course_id']);
if ((string)$resource['access_scope'] === 'assignment_submission') {
    lms_error('conflict', 'Submission files cannot be deleted through the course resource endpoint', 409);
}

$metadata = [];
if (!empty($resource['metadata_json'])) {
    $decoded = json_decode((string)$resource['metadata_json'], true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}
$fileId = trim((string)($resource['drive_file_id'] ?? ''));
if ($fileId !== '' && !lms_drive_writes_enabled()) {
    lms_error('storage_unavailable', 'Private storage changes are temporarily disabled', 503);
}

if ($resource['deleted_at'] === null) {
    $pdo->beginTransaction();
    try {
        if ($fileId !== '') {
            $metadata['storage_cleanup_pending'] = true;
            $metadata['storage_cleanup_requested_at'] = gmdate('c');
        }
        $pdo->prepare('UPDATE lms_resources SET deleted_at = CURRENT_TIMESTAMP, metadata_json = :metadata WHERE resource_id = :id')->execute([
            ':metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ':id' => $resourceId,
        ]);
        $pdo->prepare('DELETE FROM lms_module_items WHERE item_type IN (\'file\',\'video\',\'link\',\'resource\') AND entity_id = :id')->execute([':id' => $resourceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        lms_error('server_error', 'Failed to delete resource', 500);
    }
}

if ($fileId === '') {
    lms_ok(['deleted' => true, 'storage_cleanup' => 'not_required']);
}

try {
    $storage = lms_drive_storage();
} catch (DriveStorageException $e) {
    lms_drive_log('drive_resource_delete', 'pending', [
        'reason' => $e->reason(),
        'resource_id' => $resourceId,
        'course_id' => (int)$resource['course_id'],
        'user_id' => (int)$user['user_id'],
    ]);
    lms_error('storage_cleanup_pending', 'Resource is hidden, but private storage cleanup must be retried', 503);
}

$deleted = lms_drive_try_cleanup($storage, $fileId, [
    'resource_id' => $resourceId,
    'course_id' => (int)$resource['course_id'],
    'user_id' => (int)$user['user_id'],
]);
if (!$deleted) {
    lms_error('storage_cleanup_pending', 'Resource is hidden, but private storage cleanup must be retried', 503);
}

$metadata['storage_cleanup_pending'] = false;
$metadata['storage_deleted_at'] = gmdate('c');
try {
    $pdo->prepare('UPDATE lms_resources SET metadata_json = :metadata WHERE resource_id = :id')->execute([
        ':metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ':id' => $resourceId,
    ]);
} catch (Throwable) {
    lms_drive_log('drive_resource_delete_metadata', 'failed', [
        'reason' => 'database_failure',
        'resource_id' => $resourceId,
        'course_id' => (int)$resource['course_id'],
        'user_id' => (int)$user['user_id'],
    ]);
}

lms_ok(['deleted' => true, 'storage_cleanup' => 'completed']);
