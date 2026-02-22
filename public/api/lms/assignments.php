<?php
/**
 * GET /api/lms/assignments.php?course_id=<id>
 * List assignments for a course. Used by grading.js and analytics.js.
 * Proxy that queries lms_assignments directly (no nested handler for list).
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$courseId = (int) ($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
lms_course_access($user, $courseId);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT assignment_id AS id, title, instructions AS description,
            due_at AS due_date, max_points, status
     FROM lms_assignments
     WHERE course_id = :course_id AND deleted_at IS NULL
     ORDER BY due_at ASC, assignment_id ASC'
);
$stmt->execute([':course_id' => $courseId]);
lms_ok($stmt->fetchAll());
