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

// Feature flag check — treat missing flag as enabled so quiz listing never 500s
try {
    if (!lms_feature_enabled('lms_expansion_quizzes', $courseId)) {
        lms_error('feature_disabled', 'quizzes feature not enabled', 404);
    }
} catch (Throwable $e) {
    // Feature flag table issue should not block quiz listing
    error_log('[kairos] lms_feature_enabled check failed: ' . $e->getMessage());
}

lms_course_access($user, $courseId);

$role = strtolower($user['role_name'] ?? lms_user_role($user));
$statusFilter = ($role === 'student') ? "AND status = 'published'" : "";

$pdo = db();
try {
    $stmt = $pdo->prepare(
        "SELECT assessment_id AS id, title, instructions AS description,
                time_limit_minutes AS time_limit_min, max_attempts, due_at AS due_date, status
         FROM lms_assessments
         WHERE course_id = :course_id AND deleted_at IS NULL $statusFilter
         ORDER BY due_at ASC, assessment_id ASC"
    );
    $stmt->execute([':course_id' => $courseId]);
    lms_ok($stmt->fetchAll());
} catch (Throwable $e) {
    error_log('[kairos] quizzes listing failed: ' . $e->getMessage());
    lms_error('query_failed', 'Failed to load quizzes.', 500);
}
