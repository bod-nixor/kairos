<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['lesson_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'lesson_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT lesson_id, course_id, title, summary, position, requires_previous FROM lms_lessons WHERE lesson_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    lms_error('not_found', 'Lesson not found', 404);
}

lms_course_access($user, (int)$existing['course_id']);

$title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$existing['title'];
$summary = array_key_exists('summary', $in) ? $in['summary'] : $existing['summary'];
$position = array_key_exists('position', $in) ? (int)$in['position'] : (int)$existing['position'];
$requiresPrevious = array_key_exists('requires_previous', $in)
    ? (!empty($in['requires_previous']) ? 1 : 0)
    : (int)$existing['requires_previous'];

$pdo->prepare('UPDATE lms_lessons SET title=:t, summary=:s, position=:p, requires_previous=:r, updated_at=CURRENT_TIMESTAMP WHERE lesson_id=:id')->execute([
    ':t' => $title,
    ':s' => $summary,
    ':p' => $position,
    ':r' => $requiresPrevious,
    ':id' => $id,
]);

lms_ok(['updated' => true]);
