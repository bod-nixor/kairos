<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['block_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'block_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT block_id, position, block_type, content_json, resource_id FROM lms_lesson_blocks WHERE block_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    lms_error('not_found', 'Lesson block not found', 404);
}

$position = array_key_exists('position', $in) ? (int)$in['position'] : (int)$existing['position'];
$blockType = array_key_exists('block_type', $in) ? (string)$in['block_type'] : (string)$existing['block_type'];
$contentJson = array_key_exists('content', $in)
    ? json_encode($in['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : (string)$existing['content_json'];
$resourceId = array_key_exists('resource_id', $in) ? ($in['resource_id'] === null ? null : (int)$in['resource_id']) : ($existing['resource_id'] !== null ? (int)$existing['resource_id'] : null);

$pdo->prepare('UPDATE lms_lesson_blocks SET position=:p, block_type=:t, content_json=:c, resource_id=:r, updated_at=CURRENT_TIMESTAMP WHERE block_id=:id')->execute([
    ':p' => $position,
    ':t' => $blockType,
    ':c' => $contentJson,
    ':r' => $resourceId,
    ':id' => $id,
]);

lms_ok(['updated' => true]);
