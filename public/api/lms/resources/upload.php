<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/drive_client.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
$title = trim((string)($_POST['title'] ?? ''));
if ($courseId <= 0 || $title === '' || empty($_FILES['file'])) {
    lms_error('validation_error', 'course_id, title, and file are required', 422);
}
lms_course_access($user, $courseId);

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
    lms_error('upload_failed', 'File upload failed', 422);
}

$mime = (string)($file['type'] ?? 'application/octet-stream');
$driveMeta = lms_drive_upload_stub((string)$file['name'], (string)$file['tmp_name'], $mime);

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
    ':access_scope' => (string)($_POST['access_scope'] ?? 'course'),
    ':metadata' => json_encode($driveMeta),
    ':created_by' => (int)$user['user_id'],
]);
lms_ok(['resource_id' => (int)$pdo->lastInsertId()]);
