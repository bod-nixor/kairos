<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$id = (int)($in['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$assignmentStmt = $pdo->prepare(
    'SELECT assignment_id, course_id, title, status
     FROM lms_assignments
     WHERE assignment_id = :id AND deleted_at IS NULL
     LIMIT 1'
);
$assignmentStmt->execute([':id' => $id]);
$assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$assignment) {
    lms_error('not_found', 'Assignment not found', 404);
}
if (isset($in['course_id']) && (int)$in['course_id'] !== (int)$assignment['course_id']) {
    lms_error('not_found', 'Assignment not found in this course', 404);
}
lms_require_course_capability($user, 'manage_course', (int)$assignment['course_id']);

$pdo->beginTransaction();
try {
    $deleteStmt = $pdo->prepare(
        "UPDATE lms_assignments
         SET deleted_at = CURRENT_TIMESTAMP, status = 'archived', updated_at = CURRENT_TIMESTAMP
         WHERE assignment_id = :id AND course_id = :course_id AND deleted_at IS NULL"
    );
    $deleteStmt->execute([
        ':id' => $id,
        ':course_id' => (int)$assignment['course_id'],
    ]);
    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();
        lms_error('not_found', 'Assignment not found', 404);
    }
    $pdo->prepare(
        "DELETE FROM lms_module_items
         WHERE course_id = :course_id AND item_type = 'assignment' AND entity_id = :id"
    )->execute([
        ':course_id' => (int)$assignment['course_id'],
        ':id' => $id,
    ]);

    lms_emit_event($pdo, 'assignment.deleted', [
        'event_name' => 'assignment.deleted',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'assignment',
        'entity_id' => $id,
        'course_id' => (int)$assignment['course_id'],
        'title' => (string)$assignment['title'],
        'previous_status' => (string)$assignment['status'],
        'status' => 'archived',
    ]);

    $pdo->commit();
    lms_ok([
        'deleted' => true,
        'archived' => true,
        'assignment_id' => $id,
        'historical_records_preserved' => true,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('assignment_delete_failed assignment_id=' . $id . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    lms_error('server_error', 'Failed to delete assignment', 500);
}
