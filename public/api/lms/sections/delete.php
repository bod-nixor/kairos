<?php
/**
 * POST /api/lms/sections/delete.php
 * Soft-delete a course section (module). Requires manager/admin with course access.
 *
 * Payload: { section_id: int }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['section_id'] ?? 0);

if ($id <= 0) {
    lms_error('validation_error', 'section_id required', 422);
}

$pdo = db();

// Verify the section exists and get its course_id for access check
$stmt = $pdo->prepare(
    'SELECT section_id, course_id FROM lms_course_sections WHERE section_id = :id AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute([':id' => $id]);
$section = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$section) {
    lms_error('not_found', 'Section not found.', 404);
}

// Enforce course-scoped access (prevents IDOR)
lms_course_access($user, (int)$section['course_id']);

$pdo->prepare(
    'UPDATE lms_course_sections SET deleted_at = CURRENT_TIMESTAMP WHERE section_id = :id'
)->execute([':id' => $id]);

lms_ok(['deleted' => true]);
