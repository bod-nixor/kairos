<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

/**
 * POST /api/lms/resources/update.php
 * Update a resource's title, URL, published status.
 * RBAC: Manager/Admin only.
 */
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$resourceId = (int)($in['resource_id'] ?? 0);
$courseId = (int)($in['course_id'] ?? 0);

if ($resourceId <= 0 || $courseId <= 0) {
    lms_error('validation_error', 'resource_id and course_id are required.', 422);
}

lms_course_access($user, $courseId);

$pdo = db();
$stmt = $pdo->prepare('SELECT resource_id, course_id, title, resource_type, drive_preview_url, metadata_json FROM lms_resources WHERE resource_id = :id AND course_id = :cid AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $resourceId, ':cid' => $courseId]);
$resource = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    lms_error('not_found', 'Resource not found.', 404);
}

$title = isset($in['title']) ? trim((string)$in['title']) : null;
$url = isset($in['url']) ? trim((string)$in['url']) : null;
$published = isset($in['published']) ? (int)$in['published'] : null;

$updates = [];
$params = [':id' => $resourceId];

if ($title !== null && $title !== '') {
    $updates[] = 'title = :title';
    $params[':title'] = $title;
}
if ($url !== null && $url !== '') {
    if (!preg_match('/^https?:\/\//i', $url)) {
        lms_error('validation_error', 'URL must start with http:// or https://.', 422);
    }
    $updates[] = 'drive_preview_url = :url';
    $params[':url'] = $url;
    // Update metadata_json with the url
    $meta = json_decode($resource['metadata_json'] ?: '{}', true) ?: [];
    $meta['url'] = $url;
    $updates[] = 'metadata_json = :meta';
    $params[':meta'] = json_encode($meta, JSON_THROW_ON_ERROR);
}

if (empty($updates)) {
    lms_error('validation_error', 'No valid fields to update.', 422);
}

$sql = 'UPDATE lms_resources SET ' . implode(', ', $updates) . ' WHERE resource_id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Also update the module_item title if it exists
if ($title !== null && $title !== '') {
    $updateItemSql = 'UPDATE lms_module_items SET title = :title WHERE entity_id = :eid AND item_type IN (\'file\',\'video\',\'link\') AND course_id = :cid';
    $stmt = $pdo->prepare($updateItemSql);
    $stmt->execute([':title' => $title, ':eid' => $resourceId, ':cid' => $courseId]);
}

lms_ok(['resource_id' => $resourceId, 'updated' => true]);
