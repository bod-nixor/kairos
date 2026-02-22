<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$lessonId = (int)($in['lesson_id'] ?? 0);
$published = !empty($in['published']) ? 1 : 0;

if ($lessonId <= 0) {
    lms_error('validation_error', 'lesson_id is required', 422);
}

$pdo = db();
$lessonStmt = $pdo->prepare('SELECT lesson_id, course_id FROM lms_lessons WHERE lesson_id = :lesson_id AND deleted_at IS NULL LIMIT 1');
$lessonStmt->execute([':lesson_id' => $lessonId]);
$lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);
if (!$lesson) {
    lms_error('not_found', 'Lesson not found', 404);
}

lms_course_access($user, (int)$lesson['course_id']);

$updateModuleStmt = $pdo->prepare('UPDATE lms_module_items SET published_flag = :published, updated_at = CURRENT_TIMESTAMP WHERE item_type = \'lesson\' AND entity_id = :lesson_id AND course_id = :course_id');
$updateModuleStmt->execute([
    ':published' => $published,
    ':lesson_id' => $lessonId,
    ':course_id' => (int)$lesson['course_id'],
]);

lms_ok(['lesson_id' => $lessonId, 'published_flag' => $published]);
