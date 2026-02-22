<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_sanitize.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$courseId = (int)($in['course_id'] ?? 0);
$lessonId = (int)($in['lesson_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$htmlContent = (string)($in['html_content'] ?? '');
$summary = array_key_exists('summary', $in) ? ($in['summary'] ?? null) : null;

if ($courseId <= 0 || $lessonId <= 0 || $title === '') {
    lms_error('validation_error', 'course_id, lesson_id, and title are required', 422);
}

lms_course_access($user, $courseId);
$pdo = db();
$existingStmt = $pdo->prepare('SELECT lesson_id, course_id FROM lms_lessons WHERE lesson_id = :id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $lessonId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing || (int)$existing['course_id'] !== $courseId) {
    lms_error('not_found', 'Lesson not found', 404);
}

$updateStmt = $pdo->prepare('UPDATE lms_lessons SET title = :title, summary = :summary, html_content = :html_content, updated_at = CURRENT_TIMESTAMP WHERE lesson_id = :id AND deleted_at IS NULL');
$updateStmt->execute([
    ':title' => $title,
    ':summary' => $summary,
    ':html_content' => lms_sanitize_lesson_html($htmlContent),
    ':id' => $lessonId,
]);

lms_ok(['lesson_id' => $lessonId, 'saved' => true]);
