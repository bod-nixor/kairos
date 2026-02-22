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
$existingStmt = $pdo->prepare('SELECT assessment_id, course_id, title, instructions FROM lms_assessments WHERE assessment_id = :id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    lms_error('not_found', 'Quiz not found', 404);
}

lms_course_access($user, (int)$existing['course_id']);

$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be blank', 422);
}

$instructionsRaw = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? $existing['instructions'];
$instructions = is_scalar($instructionsRaw) || $instructionsRaw === null ? $instructionsRaw : $existing['instructions'];

$pdo->prepare('UPDATE lms_assessments SET title=:title, instructions=:instructions, status=:status, max_attempts=:max_attempts, time_limit_minutes=:time_limit_minutes, available_from=:available_from, due_at=:due_at, updated_at=CURRENT_TIMESTAMP WHERE assessment_id=:id')
    ->execute([
        ':title' => $title,
        ':instructions' => $instructions,
        ':status' => $in['status'] ?? 'draft',
        ':max_attempts' => (int)($in['max_attempts'] ?? 1),
        ':time_limit_minutes' => isset($in['time_limit_minutes']) ? (int)$in['time_limit_minutes'] : null,
        ':available_from' => $in['available_from'] ?? null,
        ':due_at' => $in['due_at'] ?? null,
        ':id' => $id,
    ]);

lms_ok(['updated' => true]);
