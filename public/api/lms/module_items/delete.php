<?php
/**
 * POST /api/lms/module_items/delete.php
 * Hard-delete a module item from lms_module_items.
 * Does NOT delete the underlying entity (lesson, assignment, quiz, etc.).
 * Requires manager/admin with course access.
 *
 * Payload: { module_item_id: int, course_id: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$moduleItemId = (int) ($in['module_item_id'] ?? 0);
$courseId = (int) ($in['course_id'] ?? 0);

if ($moduleItemId <= 0 || $courseId <= 0) {
    lms_error('validation_error', 'module_item_id and course_id are required', 422);
}

lms_course_access($user, $courseId);

$pdo = db();

// Atomic delete within transaction
try {
    $pdo->beginTransaction();
    $delStmt = $pdo->prepare('DELETE FROM lms_module_items WHERE module_item_id = :id AND course_id = :cid');
    $delStmt->execute([':id' => $moduleItemId, ':cid' => $courseId]);

    if ($delStmt->rowCount() === 0) {
        $pdo->rollBack();
        lms_error('not_found', 'Module item not found in this course', 404);
    }

    $pdo->commit();
    lms_ok(['deleted' => true, 'module_item_id' => $moduleItemId]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] module_items/delete failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to delete module item', 500);
}
