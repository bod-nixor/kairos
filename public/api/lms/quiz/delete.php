<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['assessment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$pdo = db();
$assessmentStmt = $pdo->prepare('SELECT assessment_id, course_id FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$assessmentStmt->execute([':id' => $id]);
$assessment = $assessmentStmt->fetch();
if (!$assessment) {
    lms_error('not_found', 'Assessment not found', 404);
}

lms_course_access($user, (int)$assessment['course_id']);

$inProgressStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:id AND status=\'in_progress\'');
$inProgressStmt->execute([':id' => $id]);
if ((int)$inProgressStmt->fetchColumn() > 0) {
    lms_error('conflict', 'Cannot archive quiz with in-progress attempts', 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assessments SET deleted_at=CURRENT_TIMESTAMP, status=\'archived\' WHERE assessment_id=:id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM lms_module_items WHERE item_type = \'quiz\' AND entity_id = :id')->execute([':id' => $id]);
    $pdo->commit();
    lms_ok(['deleted' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to archive quiz', 500);
}
