<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assessmentId = (int)($in['assessment_id'] ?? 0);
$required = !empty($in['required']) ? 1 : 0;

if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT assessment_id, course_id, section_id, title FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $assessmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Quiz not found', 404);
}

lms_require_course_capability($user, 'manage_course', (int)$row['course_id']);

try {
    $pdo->beginTransaction();

    $existsStmt = $pdo->prepare("SELECT module_item_id FROM lms_module_items WHERE course_id=:course_id AND item_type='quiz' AND entity_id=:id LIMIT 1 FOR UPDATE");
    $existsStmt->execute([
        ':course_id' => (int)$row['course_id'],
        ':id' => $assessmentId,
    ]);
    $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $pdo->rollBack();
        lms_error('conflict', 'Add this quiz to a module before changing its mandatory state.', 409);
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
    $courseIdForLog = (isset($row) && is_array($row) && isset($row['course_id']))
        ? (int)$row['course_id']
        : 'unknown';
    error_log(
        'lms/quiz/mandatory.php failed assessment_id=' . $assessmentId .
        ' course_id=' . $courseIdForLog .
        ' user_id=' . (int)$user['user_id'] .
        ' required_flag=' . $required .
        ' exception=' . get_class($e)
    );
    lms_error('mandatory_failed', 'Failed to update mandatory state', 500);
}

lms_ok(['assessment_id' => $assessmentId, 'required_flag' => $required]);
