<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['assignments', 'lms_assignments']);
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
$pdo->prepare('INSERT INTO lms_assignments (course_id, section_id, title, instructions, due_at, late_allowed, max_points, status, created_by)
    VALUES (:course_id, :section_id, :title, :instructions, :due_at, :late_allowed, :max_points, :status, :created_by)')
    ->execute([
        ':course_id' => $courseId,
        ':section_id' => isset($in['section_id']) ? (int)$in['section_id'] : null,
        ':title' => $title,
        ':instructions' => is_scalar($instructions) || $instructions === null ? $instructions : null,
        ':due_at' => $in['due_at'] ?? null,
        ':late_allowed' => !empty($in['late_allowed']) ? 1 : 0,
        ':max_points' => (float)($in['max_points'] ?? 100),
        ':status' => $in['status'] ?? 'draft',
        ':created_by' => (int)$user['user_id'],
    ]);

lms_ok(['assignment_id' => (int)$pdo->lastInsertId()]);
