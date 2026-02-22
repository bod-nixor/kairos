<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_sanitize.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$lessonId = (int)($in['lesson_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
if ($lessonId <= 0 || $title === '') {
    lms_error('validation_error', 'lesson_id and title required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT lesson_id, course_id FROM lms_lessons WHERE lesson_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $lessonId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    lms_error('not_found', 'Lesson not found', 404);
}

lms_course_access($user, (int)$existing['course_id']);
$updateStmt = $pdo->prepare('UPDATE lms_lessons SET title=:t, summary=:s, html_content=:h, position=:p, requires_previous=:r, updated_at=CURRENT_TIMESTAMP WHERE lesson_id=:id AND deleted_at IS NULL');
$updateStmt->execute([
    ':t' => $title,
    ':s' => $in['summary'] ?? null,
    ':h' => lms_sanitize_lesson_html((string)($in['html_content'] ?? '')),
    ':p' => (int)($in['position'] ?? 0),
    ':r' => !empty($in['requires_previous']) ? 1 : 0,
    ':id' => $lessonId,
]);

lms_ok(['updated' => true, 'lesson_id' => $lessonId]);
