<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$questionId = (int)($in['question_id'] ?? 0);
$direction = trim(strtolower((string)($in['direction'] ?? '')));

if ($questionId <= 0 || !in_array($direction, ['up', 'down'], true)) {
    lms_error('validation_error', 'question_id and direction (up/down) are required', 422);
}

$pdo = db();
$currentStmt = $pdo->prepare('SELECT q.question_id, q.assessment_id, q.position, a.course_id FROM lms_questions q JOIN lms_assessments a ON a.assessment_id = q.assessment_id WHERE q.question_id = :question_id AND q.deleted_at IS NULL LIMIT 1');
$currentStmt->execute([':question_id' => $questionId]);
$current = $currentStmt->fetch(PDO::FETCH_ASSOC);
if (!$current) {
    lms_error('not_found', 'Question not found', 404);
}

lms_course_access($user, (int)$current['course_id']);

$pdo->beginTransaction();
try {
    $currentForUpdateStmt = $pdo->prepare('SELECT question_id, assessment_id, position FROM lms_questions WHERE question_id = :question_id AND deleted_at IS NULL FOR UPDATE');
    $currentForUpdateStmt->execute([':question_id' => $questionId]);
    $currentLocked = $currentForUpdateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentLocked) {
        lms_error('not_found', 'Question not found', 404);
    }

    $comparison = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighborStmt = $pdo->prepare("SELECT question_id, position FROM lms_questions WHERE assessment_id = :assessment_id AND deleted_at IS NULL AND position $comparison :position ORDER BY position $order, question_id $order LIMIT 1 FOR UPDATE");
    $neighborStmt->execute([
        ':assessment_id' => (int)$currentLocked['assessment_id'],
        ':position' => (int)$currentLocked['position'],
    ]);
    $neighbor = $neighborStmt->fetch(PDO::FETCH_ASSOC);
    if (!$neighbor) {
        $pdo->commit();
        lms_ok(['moved' => false]);
    }

    $swapStmt = $pdo->prepare('UPDATE lms_questions SET position = :position, updated_at = CURRENT_TIMESTAMP WHERE question_id = :question_id AND deleted_at IS NULL');
    $swapStmt->execute([
        ':position' => (int)$neighbor['position'],
        ':question_id' => (int)$currentLocked['question_id'],
    ]);
    $swapStmt->execute([
        ':position' => (int)$currentLocked['position'],
        ':question_id' => (int)$neighbor['question_id'],
    ]);

    $pdo->commit();
    lms_ok(['moved' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('question_reorder_failed', 'Failed to reorder question', 500);
}
