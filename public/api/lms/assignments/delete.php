<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager','admin']);
$id = (int)(lms_json_input()['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$assignmentStmt = $pdo->prepare('SELECT assignment_id, course_id, deleted_at FROM lms_assignments WHERE assignment_id=:id LIMIT 1');
$assignmentStmt->execute([':id' => $id]);
$assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$assignment || $assignment['deleted_at'] !== null) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$assignment['course_id']);

$activeStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_submissions WHERE assignment_id=:id AND status IN (\'submitted\',\'under_review\',\'in_review\')');
$activeStmt->execute([':id' => $id]);
if ((int)$activeStmt->fetchColumn() > 0) {
    lms_error('conflict', 'Cannot archive assignment with active submissions', 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assignments SET deleted_at=CURRENT_TIMESTAMP, status=\'archived\' WHERE assignment_id=:id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM lms_module_items WHERE item_type = \'assignment\' AND entity_id = :id')->execute([':id' => $id]);
    $pdo->commit();
    lms_ok(['deleted' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to delete assignment', 500);
}
