<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student','ta','manager','admin']);
$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
if ($lessonId <= 0) {
    lms_error('validation_error', 'lesson_id is required', 422);
}
$pdo = db();
$lessonStmt = $pdo->prepare('SELECT lesson_id, section_id, course_id, title, summary, position, requires_previous FROM lms_lessons WHERE lesson_id = :lesson_id AND deleted_at IS NULL LIMIT 1');
$lessonStmt->execute([':lesson_id' => $lessonId]);
$lesson = $lessonStmt->fetch();
if (!$lesson) {
    lms_error('not_found', 'Lesson not found', 404);
}
lms_course_access($user, (int)$lesson['course_id']);
$blocksStmt = $pdo->prepare('SELECT block_id, position, block_type, content_json, resource_id FROM lms_lesson_blocks WHERE lesson_id = :lesson_id AND deleted_at IS NULL ORDER BY position');
$blocksStmt->execute([':lesson_id' => $lessonId]);
$lesson['blocks'] = $blocksStmt->fetchAll();
lms_ok($lesson);
