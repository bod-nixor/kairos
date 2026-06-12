<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

$user = lms_require_roles(['manager','admin']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
$title = trim((string)($_POST['title'] ?? ''));
if ($courseId <= 0 || $title === '' || empty($_FILES['file'])) {
    lms_error('validation_error', 'course_id, title, and file are required', 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($title) : strlen($title)) > 180) {
    lms_error('validation_error', 'title is too long', 422);
}
lms_course_access($user, $courseId);
if (!lms_drive_writes_enabled()) {
    lms_error('storage_unavailable', 'Private file uploads are temporarily disabled', 503);
}

$accessScope = (string)($_POST['access_scope'] ?? 'course');
if (!in_array($accessScope, ['course', 'private'], true)) {
    lms_error('validation_error', 'invalid access scope', 422);
}

$file = lms_validate_uploaded_file(
    $_FILES['file'],
    lms_drive_upload_limit((int)env('LMS_RESOURCE_UPLOAD_MAX_BYTES', 25 * 1024 * 1024))
);
$storageContext = [
    'kind' => 'course_resource',
    'course_id' => $courseId,
    'uploader_user_id' => (int)$user['user_id'],
];
try {
    $storage = lms_drive_storage();
    $driveMeta = $storage->upload($file, $storageContext);
} catch (DriveStorageException $e) {
    lms_drive_fail($e, 'drive_resource_upload', [
        'course_id' => $courseId,
        'user_id' => (int)$user['user_id'],
    ]);
}

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();
    $metadata = lms_drive_resource_metadata($driveMeta, $storageContext);
    $stmt = $pdo->prepare('INSERT INTO lms_resources (course_id, title, resource_type, drive_file_id, drive_preview_url, mime_type, file_size, checksum_sha256, access_scope, metadata_json, created_by) VALUES (:course_id,:title,\'file\',:drive_file_id,NULL,:mime_type,:file_size,:checksum,:access_scope,:metadata,:created_by)');
    $stmt->execute([
        ':course_id' => $courseId,
        ':title' => $title,
        ':drive_file_id' => $driveMeta['file_id'],
        ':mime_type' => $driveMeta['mime_type'],
        ':file_size' => $driveMeta['size'],
        ':checksum' => $driveMeta['checksum_sha256'],
        ':access_scope' => $accessScope,
        ':metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ':created_by' => (int)$user['user_id'],
    ]);
    $resourceId = (int)$pdo->lastInsertId();
    $storage->updateAppProperties((string)$driveMeta['file_id'], [
        'resource_id' => $resourceId,
        'course_id' => $courseId,
    ]);
    $pdo->commit();
    lms_drive_log('drive_resource_upload', 'completed', [
        'resource_id' => $resourceId,
        'course_id' => $courseId,
        'user_id' => (int)$user['user_id'],
    ]);
    lms_ok([
        'resource_id' => $resourceId,
        'download_url' => lms_drive_internal_url($resourceId),
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_drive_try_cleanup($storage, (string)($driveMeta['file_id'] ?? ''), [
        'course_id' => $courseId,
        'user_id' => (int)$user['user_id'],
    ]);
    if ($e instanceof DriveStorageException) {
        lms_drive_fail($e, 'drive_resource_finalize', [
            'course_id' => $courseId,
            'user_id' => (int)$user['user_id'],
        ]);
    }
    lms_drive_log('drive_resource_finalize', 'failed', [
        'reason' => 'database_failure',
        'course_id' => $courseId,
        'user_id' => (int)$user['user_id'],
    ]);
    lms_error('resource_upload_failed', 'Failed to create the resource', 500);
}
