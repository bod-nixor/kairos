<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$resourceId = isset($_GET['resource_id']) ? (int)$_GET['resource_id'] : 0;
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if ($resourceId <= 0) {
    lms_error('validation_error', 'resource_id is required', 422);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT r.resource_id, r.course_id, r.title, r.resource_type, r.drive_preview_url, r.mime_type, r.file_size, r.access_scope, r.metadata_json,
            COALESCE(mi.published_flag, 1) AS published_flag
     FROM lms_resources r
     LEFT JOIN lms_module_items mi
       ON mi.item_type IN (\'file\',\'video\',\'link\') AND mi.entity_id = r.resource_id AND mi.course_id = r.course_id
     WHERE r.resource_id = :resource_id
       AND r.deleted_at IS NULL
     ORDER BY mi.module_item_id DESC
     LIMIT 1'
);
$stmt->execute([':resource_id' => $resourceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Resource not found', 404);
}

if ($courseId > 0 && (int)$row['course_id'] !== $courseId) {
    lms_error('not_found', 'Resource not found in this course', 404);
}

lms_course_access($user, (int)$row['course_id']);
$role = lms_user_role($user);
if (!lms_is_staff_role($role) && (int)$row['published_flag'] !== 1) {
    lms_error('forbidden', 'Resource is not published', 403);
}

$meta = [];
if (!empty($row['metadata_json'])) {
    $decoded = json_decode((string)$row['metadata_json'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$storedUrl = (string)($row['drive_preview_url'] ?? '');
$originalUrl = (string)($meta['url'] ?? $storedUrl);
$previewUrl = (string)($meta['preview_url'] ?? $storedUrl);

$payload = [
    'resource_id' => (int)$row['resource_id'],
    'course_id' => (int)$row['course_id'],
    'title' => (string)$row['title'],
    'type' => (string)$row['resource_type'],
    'resource_type' => (string)$row['resource_type'],
    'url' => $previewUrl,
    'original_url' => $originalUrl,
    'drive_preview_url' => $previewUrl,
    'stored_url' => $storedUrl,
    'share_warning' => $meta['share_warning'] ?? null,
    'mime_type' => $row['mime_type'],
    'file_size' => $row['file_size'],
    'access_scope' => $row['access_scope'],
    'published_flag' => (int)$row['published_flag'],
];

lms_ok($payload);
