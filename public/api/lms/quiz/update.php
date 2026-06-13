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
$existingStmt = $pdo->prepare('SELECT assessment_id, course_id, title, instructions, status, max_attempts, time_limit_minutes, available_from, due_at FROM lms_assessments WHERE assessment_id = :id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    lms_error('not_found', 'Quiz not found', 404);
}

lms_require_course_capability($user, 'manage_course', (int)$existing['course_id']);

$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be blank', 422);
}

$instructionsRaw = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? $existing['instructions'];
$instructions = is_scalar($instructionsRaw) || $instructionsRaw === null ? $instructionsRaw : null;
if ($instructionsRaw !== null && !is_scalar($instructionsRaw)) {
    lms_error('validation_error', 'instructions must be a string', 422);
}
$maxAttempts = array_key_exists('max_attempts', $in) ? (int)$in['max_attempts'] : (int)$existing['max_attempts'];
if ($maxAttempts < 1 || $maxAttempts > 100) {
    lms_error('validation_error', 'max_attempts must be between 1 and 100', 422);
}
$timeLimit = array_key_exists('time_limit_minutes', $in) ? $in['time_limit_minutes'] : $existing['time_limit_minutes'];
if ($timeLimit === '') {
    $timeLimit = null;
}
if ($timeLimit !== null && (!is_numeric($timeLimit) || (int)$timeLimit < 1 || (int)$timeLimit > 1440)) {
    lms_error('validation_error', 'time_limit_minutes must be between 1 and 1440', 422);
}
$availableFrom = array_key_exists('available_from', $in) ? $in['available_from'] : $existing['available_from'];
$dueAt = array_key_exists('due_at', $in) ? $in['due_at'] : $existing['due_at'];
if ($availableFrom === '') {
    $availableFrom = null;
}
if ($availableFrom !== null && strtotime((string)$availableFrom) === false) {
    lms_error('validation_error', 'available_from must be a valid datetime', 422);
}
if ($dueAt === '') {
    $dueAt = null;
}
if ($dueAt !== null && strtotime((string)$dueAt) === false) {
    lms_error('validation_error', 'due_at must be a valid datetime', 422);
}
$status = array_key_exists('status', $in) ? trim((string)$in['status']) : (string)$existing['status'];
if (!in_array($status, ['draft', 'published', 'archived'], true)) {
    lms_error('validation_error', 'status is invalid', 422);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_assessments SET title=:title, instructions=:instructions, status=:status, max_attempts=:max_attempts, time_limit_minutes=:time_limit_minutes, available_from=:available_from, due_at=:due_at, updated_at=CURRENT_TIMESTAMP WHERE assessment_id=:id')
        ->execute([
        ':title' => $title,
        ':instructions' => $instructions,
        ':status' => $status,
        ':max_attempts' => $maxAttempts,
        ':time_limit_minutes' => $timeLimit === null ? null : (int)$timeLimit,
        ':available_from' => $availableFrom,
        ':due_at' => $dueAt,
        ':id' => $id,
    ]);
    lms_emit_event($pdo, 'quiz.updated', [
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'quiz',
        'entity_id' => $id,
        'course_id' => (int)$existing['course_id'],
        'title' => $title,
        'status' => $status,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('quiz_update_failed assessment_id=' . $id . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    lms_error('quiz_update_failed', 'Failed to update quiz', 500);
}

lms_ok(['updated' => true]);
