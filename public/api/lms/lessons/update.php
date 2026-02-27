<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_sanitize.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$lessonId = (int)($in['lesson_id'] ?? 0);
if ($lessonId <= 0) {
    lms_error('validation_error', 'lesson_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT lesson_id, course_id, title, summary, html_content, position, requires_previous FROM lms_lessons WHERE lesson_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $lessonId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    lms_error('not_found', 'Lesson not found', 404);
}

lms_course_access($user, (int)$existing['course_id']);

$hasTitle = array_key_exists('title', $in);
$title = $hasTitle ? trim((string)$in['title']) : (string)$existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be empty', 422);
}

$summary = array_key_exists('summary', $in) ? ($in['summary'] ?? null) : $existing['summary'];
$htmlContentRaw = array_key_exists('html_content', $in) ? (string)$in['html_content'] : (string)($existing['html_content'] ?? '');
$position = array_key_exists('position', $in) ? (int)$in['position'] : (int)$existing['position'];
$requiresPrevious = array_key_exists('requires_previous', $in) ? (!empty($in['requires_previous']) ? 1 : 0) : (int)$existing['requires_previous'];

$updateStmt = $pdo->prepare('UPDATE lms_lessons SET title=:t, summary=:s, html_content=:h, position=:p, requires_previous=:r, updated_at=CURRENT_TIMESTAMP WHERE lesson_id=:id AND deleted_at IS NULL');
$updateStmt->execute([
    ':t' => $title,
    ':s' => $summary,
    ':h' => lms_sanitize_lesson_html($htmlContentRaw),
    ':p' => $position,
    ':r' => $requiresPrevious,
    ':id' => $lessonId,
]);

lms_ok(['updated' => true, 'lesson_id' => $lessonId]);
