<?php
/**
 * POST /api/lms/module_items/delete.php
 * Remove a module item link from lms_module_items.
 * Does NOT delete the underlying entity (lesson, assignment, quiz, etc.).
 * Requires manager/admin with course management capability.
 *
 * Payload: { module_item_id: int, course_id: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$moduleItemId = (int) ($in['module_item_id'] ?? 0);
$requestedCourseId = (int) ($in['course_id'] ?? 0);

if ($moduleItemId <= 0) {
    lms_error('validation_error', 'module_item_id is required', 422);
}

$pdo = db();
$itemStmt = $pdo->prepare(
    'SELECT module_item_id, course_id, section_id, item_type, entity_id, title
     FROM lms_module_items
     WHERE module_item_id = :id
     LIMIT 1'
);
$itemStmt->execute([':id' => $moduleItemId]);
$item = $itemStmt->fetch(PDO::FETCH_ASSOC);
if (!$item || ($requestedCourseId > 0 && (int)$item['course_id'] !== $requestedCourseId)) {
    lms_error('not_found', 'Module item not found in this course', 404);
}
$courseId = (int)$item['course_id'];
lms_require_course_capability($user, 'manage_course', $courseId);

try {
    $pdo->beginTransaction();
    $delStmt = $pdo->prepare('DELETE FROM lms_module_items WHERE module_item_id = :id AND course_id = :cid');
    $delStmt->execute([':id' => $moduleItemId, ':cid' => $courseId]);

    if ($delStmt->rowCount() === 0) {
        $pdo->rollBack();
        lms_error('not_found', 'Module item not found in this course', 404);
    }

    lms_emit_event($pdo, 'module_item.removed', [
        'event_name' => 'module_item.removed',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'module_item',
        'entity_id' => $moduleItemId,
        'course_id' => $courseId,
        'section_id' => (int)$item['section_id'],
        'item_type' => (string)$item['item_type'],
        'linked_entity_id' => (int)$item['entity_id'],
    ]);

    $pdo->commit();
    lms_ok([
        'removed' => true,
        'module_item_id' => $moduleItemId,
        'underlying_content_deleted' => false,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] module_items/delete failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to remove module item', 500);
}
