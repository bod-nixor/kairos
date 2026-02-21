<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$resourceId = isset($_GET['resource_id']) ? (int)$_GET['resource_id'] : 0;
if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id is required', 422);
}
$pdo = db();
$stmt = $pdo->prepare('SELECT resource_id, course_id, title, resource_type, drive_preview_url, mime_type, file_size, access_scope, metadata_json FROM lms_resources WHERE resource_id = :resource_id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':resource_id' => $resourceId]);
$row = $stmt->fetch();
if (!$row) {
    lms_error('not_found', 'Resource not found', 404);
}
lms_course_access($user, (int)$row['course_id']);
unset($row['metadata_json']);
lms_ok($row);
