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

lms_course_access($user, $courseId);

$pdo = db();
$pdo->prepare('INSERT INTO lms_assessments (course_id, section_id, title, instructions, assessment_type, status, max_attempts, time_limit_minutes, available_from, due_at, created_by)
    VALUES (:course_id, :section_id, :title, :instructions, :assessment_type, :status, :max_attempts, :time_limit_minutes, :available_from, :due_at, :created_by)')
    ->execute([
        ':course_id' => $courseId,
        ':section_id' => isset($in['section_id']) ? (int)$in['section_id'] : null,
        ':title' => $title,
        ':instructions' => is_scalar($instructions) || $instructions === null ? $instructions : null,
        ':assessment_type' => $in['assessment_type'] ?? 'quiz',
        ':status' => $in['status'] ?? 'draft',
        ':max_attempts' => (int)($in['max_attempts'] ?? 1),
        ':time_limit_minutes' => isset($in['time_limit_minutes']) ? (int)$in['time_limit_minutes'] : null,
        ':available_from' => $in['available_from'] ?? null,
        ':due_at' => $in['due_at'] ?? null,
        ':created_by' => (int)$user['user_id'],
    ]);

lms_ok(['quiz_id' => (int)$pdo->lastInsertId()]);
