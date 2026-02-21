<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assignmentId = (int)($in['assignment_id'] ?? 0);
$taIds = $in['ta_user_ids'] ?? [];
if ($assignmentId <= 0 || !is_array($taIds)) {
    lms_error('validation_error', 'assignment_id and ta_user_ids[] required', 422);
}

$validatedTaIds = [];
foreach ($taIds as $taId) {
    if (!is_int($taId) && !is_string($taId) && !is_float($taId)) {
        lms_error('validation_error', 'ta_user_ids must contain positive integers only', 422);
    }
    if (!is_numeric((string)$taId) || (int)$taId <= 0) {
        lms_error('validation_error', 'ta_user_ids must contain positive integers only', 422);
    }
    $validatedTaIds[] = (int)$taId;
}

$pdo = db();
$assignmentStmt = $pdo->prepare('SELECT assignment_id, course_id FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1');
$assignmentStmt->execute([':id' => $assignmentId]);
$assignment = $assignmentStmt->fetch();
if (!$assignment) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int)$assignment['course_id']);

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM lms_assignment_tas WHERE assignment_id=:a')->execute([':a' => $assignmentId]);
    $ins = $pdo->prepare('INSERT INTO lms_assignment_tas (assignment_id,ta_user_id) VALUES (:a,:u)');
    foreach ($validatedTaIds as $tid) {
        $ins->execute([':a' => $assignmentId, ':u' => $tid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('update_failed', 'Failed to update assignment TA mapping', 500);
}

lms_ok(['assignment_id' => $assignmentId, 'ta_user_ids' => $validatedTaIds]);
