<?php
/**
 * GET /api/lms/analytics_metrics.php?course_id=<id>&period=<days>
 * High-level metrics for the analytics dashboard.
 * Returns: {total_students, avg_completion, avg_grade, pending_reviews}
 *
 * The optional `period` parameter (integer days, 1-365, default 30) filters
 * avg_grade and pending_reviews to activity within that window.
 * total_students and avg_completion are point-in-time and not period-filtered.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$courseId = (int) ($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
lms_course_access($user, $courseId);
$pdo = db();

// Period filtering (applies to grade and pending metrics)
$period = max(1, min((int) ($_GET['period'] ?? 30), 365));
$cutoff = date('Y-m-d H:i:s', time() - ($period * 86400));

// Total enrolled students (point-in-time, not period-filtered)
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM student_courses WHERE course_id = :cid');
    $st->execute([':cid' => $courseId]);
    $totalStudents = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_metrics: student count failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to compute student count', 500);
}

// Average completion percentage across all students (point-in-time)
$avgCompletion = 0;
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM lms_lessons l JOIN lms_course_sections s ON s.section_id = l.section_id WHERE s.course_id = :cid AND l.deleted_at IS NULL AND s.deleted_at IS NULL');
    $st->execute([':cid' => $courseId]);
    $totalLessons = (int) $st->fetchColumn();

    if ($totalLessons > 0 && $totalStudents > 0) {
        $st = $pdo->prepare('SELECT COUNT(DISTINCT c.completion_id) FROM lms_lesson_completions c JOIN lms_lessons l ON l.lesson_id = c.lesson_id JOIN lms_course_sections s ON s.section_id = l.section_id WHERE s.course_id = :cid');
        $st->execute([':cid' => $courseId]);
        $completions = (int) $st->fetchColumn();
        $avgCompletion = round(($completions / ($totalLessons * $totalStudents)) * 100, 1);
    }
} catch (\PDOException $e) {
    error_log('analytics_metrics: completion calc failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to compute completion metrics', 500);
}

// Average grade across released grades only (period-filtered)
$avgGrade = null;
try {
    $st = $pdo->prepare(
        'SELECT ROUND(AVG((g.score / NULLIF(g.max_score, 0)) * 100), 1)
         FROM lms_grades g
         WHERE g.course_id = :cid AND g.status = \'released\'
           AND g.updated_at >= :cutoff'
    );
    $st->execute([':cid' => $courseId, ':cutoff' => $cutoff]);
    $val = $st->fetchColumn();
    if ($val !== false && $val !== null) {
        $avgGrade = (float) $val;
    }
} catch (\PDOException $e) {
    error_log('analytics_metrics: avg grade failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to compute grade metrics', 500);
}

// Pending reviews — submissions without a released grade (period-filtered)
$pendingReviews = 0;
try {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM lms_submissions s
         LEFT JOIN lms_grades g ON g.submission_id = s.submission_id
         WHERE s.course_id = :cid AND g.grade_id IS NULL
           AND s.submitted_at >= :cutoff'
    );
    $st->execute([':cid' => $courseId, ':cutoff' => $cutoff]);
    $pendingReviews = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_metrics: pending reviews failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to compute pending reviews', 500);
}

lms_ok([
    'total_students' => $totalStudents,
    'avg_completion' => $avgCompletion,
    'avg_grade' => $avgGrade,
    'pending_reviews' => $pendingReviews,
]);
