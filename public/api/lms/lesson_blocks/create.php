<?php
/**
 * POST /api/lms/lesson_blocks/create.php
 * Create a new lesson block. Requires manager/admin with course access.
 *
 * Payload: { lesson_id: int, block_type: string, position?: int, content?: object, resource_id?: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$lessonId = (int)($in['lesson_id'] ?? 0);
$type = trim((string)($in['block_type'] ?? ''));

if ($lessonId <= 0 || $type === '') {
    lms_error('validation_error', 'lesson_id and block_type required', 422);
}

$pdo = db();

// Verify lesson exists and enforce course-scoped access
$lessonStmt = $pdo->prepare('SELECT course_id FROM lms_lessons WHERE lesson_id = :lid AND deleted_at IS NULL LIMIT 1');
$lessonStmt->execute([':lid' => $lessonId]);
$lessonRow = $lessonStmt->fetch(PDO::FETCH_ASSOC);
if (!$lessonRow) {
    lms_error('not_found', 'Lesson not found', 404);
}
lms_course_access($user, (int)$lessonRow['course_id']);

$pdo->prepare(
    'INSERT INTO lms_lesson_blocks (lesson_id, position, block_type, content_json, resource_id)
     VALUES (:l, :p, :t, :c, :r)'
)->execute([
    ':l' => $lessonId,
    ':p' => (int)($in['position'] ?? 0),
    ':t' => $type,
    ':c' => json_encode($in['content'] ?? new stdClass()),
    ':r' => isset($in['resource_id']) ? (int)$in['resource_id'] : null,
]);

lms_ok(['block_id' => (int)$pdo->lastInsertId()]);
