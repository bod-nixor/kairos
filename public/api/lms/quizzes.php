<?php
/**
 * GET /api/lms/quizzes.php?course_id=<id>
 * List quizzes for a course. Used by quizzes.js.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$courseId = (int) ($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

if (!lms_feature_enabled('lms_expansion_quizzes', $courseId)) {
    lms_error('feature_disabled', 'quizzes feature not enabled', 404);
}

lms_course_access($user, $courseId);

$role = strtolower($user['role_name'] ?? lms_user_role($user));
$statusFilter = ($role === 'student') ? "AND status = 'published'" : "";

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT assessment_id AS id, title, description,
            time_limit_min, max_attempts, due_at AS due_date, status
     FROM lms_assessments
     WHERE course_id = :course_id AND deleted_at IS NULL $statusFilter
     ORDER BY due_at ASC, assessment_id ASC"
);
$stmt->execute([':course_id' => $courseId]);
lms_ok($stmt->fetchAll());
