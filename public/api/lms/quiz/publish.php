<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$assessmentId = (int)($in['assessment_id'] ?? 0);
$published = !empty($in['published']) ? 1 : 0;
$newStatus = $published ? 'published' : 'draft';

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

    $pdo->prepare('UPDATE lms_assessments SET status=:status, updated_at=CURRENT_TIMESTAMP WHERE assessment_id=:id')
        ->execute([':status' => $newStatus, ':id' => $assessmentId]);

    $moduleItemStmt = $pdo->prepare("SELECT module_item_id FROM lms_module_items WHERE course_id=:course_id AND item_type='quiz' AND entity_id=:id LIMIT 1 FOR UPDATE");
    $moduleItemStmt->execute([
        ':course_id' => (int)$row['course_id'],
        ':id' => $assessmentId,
    ]);
    $moduleItem = $moduleItemStmt->fetch(PDO::FETCH_ASSOC);

    if ($moduleItem) {
        $pdo->prepare("UPDATE lms_module_items SET published_flag=:published, updated_at=CURRENT_TIMESTAMP WHERE module_item_id=:module_item_id")
            ->execute([
                ':published' => $published,
                ':module_item_id' => (int)$moduleItem['module_item_id'],
            ]);
    }

    lms_emit_event($pdo, 'quiz.updated', [
        'event_name' => 'quiz.updated',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'quiz',
        'entity_id' => $assessmentId,
        'course_id' => (int)$row['course_id'],
        'title' => (string)$row['title'],
        'status' => $newStatus,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(
        'lms/quiz/publish.php failed assessment_id=' . $assessmentId .
        ' course_id=' . (int)$row['course_id'] .
        ' user_id=' . (int)$user['user_id'] .
        ' target_status=' . $newStatus .
        ' exception=' . get_class($e)
    );
    lms_error('publish_failed', 'Failed to update publish state', 500);
}

lms_ok(['assessment_id' => $assessmentId, 'published_flag' => $published]);
