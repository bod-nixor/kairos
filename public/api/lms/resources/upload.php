<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

$user = lms_require_roles(['student','ta','manager','admin']);
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

$accessScope = (string)($_POST['access_scope'] ?? 'course');
if (!in_array($accessScope, ['course', 'private'], true)) {
    lms_error('validation_error', 'invalid access scope', 422);
}

$file = lms_validate_uploaded_file($_FILES['file'], (int)env('LMS_RESOURCE_UPLOAD_MAX_BYTES', 25 * 1024 * 1024));
$driveMeta = lms_drive_upload_stub($file['name'], $file['tmp_name'], $file['mime_type']);

$pdo = db();
$stmt = $pdo->prepare('INSERT INTO lms_resources (course_id, title, resource_type, drive_file_id, drive_preview_url, mime_type, file_size, checksum_sha256, access_scope, metadata_json, created_by) VALUES (:course_id,:title,\'file\',:drive_file_id,:drive_preview_url,:mime_type,:file_size,:checksum,:access_scope,:metadata,:created_by)');
$stmt->execute([
    ':course_id' => $courseId,
    ':title' => $title,
    ':drive_file_id' => $driveMeta['file_id'],
    ':drive_preview_url' => $driveMeta['preview_url'],
    ':mime_type' => $driveMeta['mime_type'],
    ':file_size' => $driveMeta['size'],
    ':checksum' => $driveMeta['checksum'],
    ':access_scope' => $accessScope,
    ':metadata' => json_encode($driveMeta),
    ':created_by' => (int)$user['user_id'],
]);
lms_ok(['resource_id' => (int)$pdo->lastInsertId()]);
