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

// Verify the item exists and belongs to the course (prevents IDOR)
$stmt = $pdo->prepare('SELECT module_item_id, section_id FROM lms_module_items WHERE module_item_id = :id AND course_id = :cid LIMIT 1');
$stmt->execute([':id' => $moduleItemId, ':cid' => $courseId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    lms_error('not_found', 'Module item not found in this course', 404);
}

// Hard delete the module_items row only
$delStmt = $pdo->prepare('DELETE FROM lms_module_items WHERE module_item_id = :id AND course_id = :cid');
$delStmt->execute([':id' => $moduleItemId, ':cid' => $courseId]);

lms_ok(['deleted' => true, 'module_item_id' => $moduleItemId]);
