<?php
/**
 * POST /api/lms/lesson_blocks/delete.php
 * Soft-delete a lesson block. Requires manager/admin with course access.
 *
 * Payload: { block_id: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['block_id'] ?? 0);

if ($id <= 0) {
    lms_error('validation_error', 'block_id required', 422);
}

$pdo = db();

// Verify block exists and enforce course-scoped access via parent lesson
$blockStmt = $pdo->prepare(
    'SELECT lb.block_id, l.course_id
     FROM lms_lesson_blocks lb
     JOIN lms_lessons l ON l.lesson_id = lb.lesson_id
     WHERE lb.block_id = :id AND lb.deleted_at IS NULL
     LIMIT 1'
);
$blockStmt->execute([':id' => $id]);
$block = $blockStmt->fetch(PDO::FETCH_ASSOC);

if (!$block) {
    lms_error('not_found', 'Lesson block not found', 404);
}

lms_course_access($user, (int)$block['course_id']);

$pdo->prepare('UPDATE lms_lesson_blocks SET deleted_at = CURRENT_TIMESTAMP WHERE block_id = :id')
    ->execute([':id' => $id]);

lms_ok(['deleted' => true]);
