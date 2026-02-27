<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();
$id = (int)($in['lesson_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'lesson_id required', 422);
}

$pdo = db();
$lessonStmt = $pdo->prepare('SELECT lesson_id, course_id FROM lms_lessons WHERE lesson_id=:id AND deleted_at IS NULL LIMIT 1');
$lessonStmt->execute([':id' => $id]);
$lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);
if (!$lesson) {
    lms_error('not_found', 'Lesson not found', 404);
}

lms_course_access($user, (int)$lesson['course_id']);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE lms_lessons SET deleted_at=CURRENT_TIMESTAMP WHERE lesson_id=:id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM lms_module_items WHERE item_type = \'lesson\' AND entity_id = :id')->execute([':id' => $id]);
    $pdo->commit();
    lms_ok(['deleted' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to delete lesson', 500);
}
