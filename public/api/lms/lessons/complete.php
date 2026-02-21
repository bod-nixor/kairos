<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$user = lms_require_roles(['student','ta','manager','admin']);
$input = lms_json_input();
$lessonId = (int)($input['lesson_id'] ?? 0);
if ($lessonId <= 0) {
    lms_error('validation_error', 'lesson_id is required', 422);
}
$pdo = db();
$lessonStmt = $pdo->prepare('SELECT lesson_id, section_id, course_id FROM lms_lessons WHERE lesson_id = :lesson_id AND deleted_at IS NULL LIMIT 1');
$lessonStmt->execute([':lesson_id' => $lessonId]);
$lesson = $lessonStmt->fetch();
if (!$lesson) {
    lms_error('not_found', 'Lesson not found', 404);
}
$courseId = (int)$lesson['course_id'];
lms_course_access($user, $courseId);
$pdo->prepare('INSERT INTO lms_lesson_completions (lesson_id, course_id, user_id) VALUES (:lesson_id,:course_id,:user_id) ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP')->execute([
    ':lesson_id' => $lessonId,
    ':course_id' => $courseId,
    ':user_id' => (int)$user['user_id'],
]);
$event = [
    'event_name' => 'lesson.completed',
    'event_id' => lms_uuid_v4(),
    'occurred_at' => gmdate('c'),
    'actor_id' => (int)$user['user_id'],
    'entity_type' => 'lesson',
    'entity_id' => $lessonId,
    'course_id' => $courseId,
    'student_user_id' => (int)$user['user_id'],
];
lms_emit_event($pdo, 'lesson.completed', $event);
lms_ok(['lesson_id' => $lessonId, 'completed' => true]);
