<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$courseId = (int)($in['course_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$instructions = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? null;

if ($courseId <= 0 || $title === '') {
    lms_error('validation_error', 'course_id and title required', 422);
}

lms_require_course_capability($user, 'manage_course', $courseId);

if ($instructions !== null && !is_scalar($instructions)) {
    lms_error('validation_error', 'instructions must be a string', 422);
}
$maxAttempts = (int)($in['max_attempts'] ?? 1);
if ($maxAttempts < 1 || $maxAttempts > 100) {
    lms_error('validation_error', 'max_attempts must be between 1 and 100', 422);
}
$timeLimit = $in['time_limit_minutes'] ?? null;
if ($timeLimit === '') {
    $timeLimit = null;
}
if ($timeLimit !== null && (!is_numeric($timeLimit) || (int)$timeLimit < 1 || (int)$timeLimit > 1440)) {
    lms_error('validation_error', 'time_limit_minutes must be between 1 and 1440', 422);
}
foreach (['available_from', 'due_at'] as $dateKey) {
    if (($in[$dateKey] ?? null) !== null && ($in[$dateKey] ?? '') !== '' && strtotime((string)$in[$dateKey]) === false) {
        lms_error('validation_error', $dateKey . ' must be a valid datetime', 422);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO lms_assessments (course_id, section_id, title, instructions, assessment_type, status, max_attempts, time_limit_minutes, available_from, due_at, created_by)
        VALUES (:course_id, :section_id, :title, :instructions, :assessment_type, :status, :max_attempts, :time_limit_minutes, :available_from, :due_at, :created_by)')
        ->execute([
        ':course_id' => $courseId,
        ':section_id' => isset($in['section_id']) ? (int)$in['section_id'] : null,
        ':title' => $title,
        ':instructions' => $instructions === null ? null : (string)$instructions,
        ':assessment_type' => 'quiz',
        ':status' => 'draft',
        ':max_attempts' => $maxAttempts,
        ':time_limit_minutes' => $timeLimit === null ? null : (int)$timeLimit,
        ':available_from' => ($in['available_from'] ?? '') === '' ? null : $in['available_from'],
        ':due_at' => ($in['due_at'] ?? '') === '' ? null : $in['due_at'],
        ':created_by' => (int)$user['user_id'],
    ]);
    $assessmentId = (int)$pdo->lastInsertId();
    $pdo->commit();
    lms_emit_event($pdo, 'quiz.created', [
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'quiz',
        'entity_id' => $assessmentId,
        'course_id' => $courseId,
        'title' => $title,
        'status' => 'draft',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('quiz_create_failed course_id=' . $courseId . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    lms_error('quiz_create_failed', 'Failed to create quiz', 500);
}

lms_ok(['quiz_id' => $assessmentId, 'assessment_id' => $assessmentId]);
