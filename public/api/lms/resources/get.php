<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';
require_once __DIR__ . '/_access.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$resourceId = isset($_GET['resource_id']) ? (int)$_GET['resource_id'] : 0;
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id is required', 422);
}

$pdo = db();
$row = lms_resource_access_row($pdo, $resourceId);

if ($courseId > 0 && (int)$row['course_id'] !== $courseId) {
    lms_error('not_found', 'Resource not found in this course', 404);
}

lms_authorize_resource_access($pdo, $user, $row);

$meta = [];
if (!empty($row['metadata_json'])) {
    $decoded = json_decode((string)$row['metadata_json'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$isManagedFile = !empty($row['drive_file_id']);
$storedUrl = (string)($row['drive_preview_url'] ?? '');
$originalUrl = $isManagedFile ? '' : (string)($meta['url'] ?? $storedUrl);
$downloadUrl = $isManagedFile ? lms_drive_internal_url((int)$row['resource_id']) : '';
$previewUrl = $isManagedFile && lms_drive_inline_allowed((string)$row['mime_type'])
    ? lms_drive_internal_url((int)$row['resource_id'], 'inline')
    : ($isManagedFile ? '' : (string)($meta['preview_url'] ?? $storedUrl));

$payload = [
    'resource_id' => (int)$row['resource_id'],
    'course_id' => (int)$row['course_id'],
    'title' => (string)$row['title'],
    'type' => (string)$row['resource_type'],
    'resource_type' => (string)$row['resource_type'],
    'url' => $previewUrl !== '' ? $previewUrl : $downloadUrl,
    'original_url' => $originalUrl,
    'drive_preview_url' => $previewUrl,
    'stored_url' => $isManagedFile ? '' : $storedUrl,
    'download_url' => $downloadUrl,
    'preview_url' => $previewUrl,
    'storage_backend' => $isManagedFile ? 'google_drive' : 'external',
    'original_name' => $isManagedFile ? (string)($meta['original_name'] ?? $row['title']) : null,
    'share_warning' => $meta['share_warning'] ?? null,
    'mime_type' => $row['mime_type'],
    'file_size' => $row['file_size'],
    'access_scope' => $row['access_scope'],
    'published_flag' => (int)$row['published_flag'],
];

lms_ok($payload);
