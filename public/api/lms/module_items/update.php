<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

/**
 * POST /api/lms/module_items/update.php
 * Update a module item's title, published_flag, required_flag.
 * RBAC: Manager/Admin only.
 */
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$moduleItemId = (int)($in['module_item_id'] ?? 0);
$courseId = (int)($in['course_id'] ?? 0);

if ($moduleItemId <= 0 || $courseId <= 0) {
    lms_error('validation_error', 'module_item_id and course_id are required.', 422);
}

lms_course_access($user, $courseId);

$pdo = db();
$stmt = $pdo->prepare('SELECT module_item_id, course_id, item_type, entity_id, title, published_flag, required_flag FROM lms_module_items WHERE module_item_id = :id AND course_id = :cid LIMIT 1');
$stmt->execute([':id' => $moduleItemId, ':cid' => $courseId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    lms_error('not_found', 'Module item not found.', 404);
}

$title = isset($in['title']) ? trim((string)$in['title']) : null;
$publishedFlag = isset($in['published']) ? ((int)$in['published'] === 1 ? 1 : 0) : null;
$requiredFlag = isset($in['required']) ? ((int)$in['required'] === 1 ? 1 : 0) : null;

$updates = [];
$params = [':id' => $moduleItemId];

if ($title !== null && $title !== '') {
    $updates[] = 'title = :title';
    $params[':title'] = $title;
}
if ($publishedFlag !== null) {
    $updates[] = 'published_flag = :pf';
    $params[':pf'] = $publishedFlag;
}
if ($requiredFlag !== null) {
    $updates[] = 'required_flag = :rf';
    $params[':rf'] = $requiredFlag;
}

if (empty($updates)) {
    lms_error('validation_error', 'No valid fields to update.', 422);
}

$sql = 'UPDATE lms_module_items SET ' . implode(', ', $updates) . ' WHERE module_item_id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

lms_ok(['module_item_id' => $moduleItemId, 'updated' => true]);
