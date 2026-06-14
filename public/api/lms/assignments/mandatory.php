<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$required = !empty($in['required']) ? 1 : 0;

if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT assignment_id, course_id, section_id, title FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $assignmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_require_course_capability($user, 'manage_course', (int)$row['course_id']);

try {
    $pdo->beginTransaction();

    $existsStmt = $pdo->prepare("SELECT module_item_id FROM lms_module_items WHERE course_id=:course_id AND item_type='assignment' AND entity_id=:id LIMIT 1 FOR UPDATE");
    $existsStmt->execute([
        ':course_id' => (int)$row['course_id'],
        ':id' => $assignmentId,
    ]);
    $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $pdo->rollBack();
        lms_error('conflict', 'Add this assignment to a module before changing its mandatory state.', 409);
    }

    $pdo->prepare("UPDATE lms_module_items SET required_flag=:required, updated_at=CURRENT_TIMESTAMP WHERE module_item_id=:module_item_id")
        ->execute([
            ':required' => $required,
            ':module_item_id' => (int)$existing['module_item_id'],
        ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'lms/assignments/mandatory.php failed assignment_id=' . $assignmentId .
        ' course_id=' . (int)$row['course_id'] .
        ' user_id=' . (int)$user['user_id'] .
        ' required_flag=' . $required .
        ' exception=' . get_class($e)
    );
    lms_error('mandatory_failed', 'Failed to update mandatory state', 500);
}

lms_ok(['assignment_id' => $assignmentId, 'required_flag' => $required]);
