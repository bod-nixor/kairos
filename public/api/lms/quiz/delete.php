<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['assessment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$pdo = db();
$assessmentStmt = $pdo->prepare(
    'SELECT assessment_id, course_id, title, status
     FROM lms_assessments
     WHERE assessment_id = :id AND deleted_at IS NULL
     LIMIT 1'
);
$assessmentStmt->execute([':id' => $id]);
$assessment = $assessmentStmt->fetch();
if (!$assessment) {
    lms_error('not_found', 'Assessment not found', 404);
}

if (isset($in['course_id']) && (int)$in['course_id'] !== (int)$assessment['course_id']) {
    lms_error('not_found', 'Quiz not found in this course', 404);
}
lms_require_course_capability($user, 'manage_course', (int)$assessment['course_id']);

$inProgressStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:id AND status=\'in_progress\'');
$inProgressStmt->execute([':id' => $id]);
if ((int)$inProgressStmt->fetchColumn() > 0) {
    lms_error('conflict', 'Cannot archive quiz with in-progress attempts', 409);
}

$pdo->beginTransaction();
try {
    $deleteStmt = $pdo->prepare(
        "UPDATE lms_assessments
         SET deleted_at = CURRENT_TIMESTAMP, status = 'archived', updated_at = CURRENT_TIMESTAMP
         WHERE assessment_id = :id AND course_id = :course_id AND deleted_at IS NULL"
    );
    $deleteStmt->execute([
        ':id' => $id,
        ':course_id' => (int)$assessment['course_id'],
    ]);
    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();
        lms_error('not_found', 'Quiz not found', 404);
    }
    $pdo->prepare(
        "DELETE FROM lms_module_items
         WHERE course_id = :course_id AND item_type = 'quiz' AND entity_id = :id"
    )->execute([
        ':course_id' => (int)$assessment['course_id'],
        ':id' => $id,
    ]);

    lms_emit_event($pdo, 'quiz.deleted', [
        'event_name' => 'quiz.deleted',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'quiz',
        'entity_id' => $id,
        'course_id' => (int)$assessment['course_id'],
        'title' => (string)$assessment['title'],
        'previous_status' => (string)$assessment['status'],
        'status' => 'archived',
    ]);

    $pdo->commit();
    lms_ok([
        'deleted' => true,
        'archived' => true,
        'assessment_id' => $id,
        'historical_records_preserved' => true,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('quiz_delete_failed assessment_id=' . $id . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    lms_error('server_error', 'Failed to delete quiz', 500);
}
