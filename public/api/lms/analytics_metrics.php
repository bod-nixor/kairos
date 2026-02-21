<?php
/**
 * GET /api/lms/analytics_metrics.php?course_id=<id>&period=<days>
 * High-level metrics for the analytics dashboard.
 * Returns: {total_students, avg_completion, avg_grade, pending_reviews}
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

// Total enrolled students
$totalStudents = 0;
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM student_courses WHERE course_id = :cid');
    $st->execute([':cid' => $courseId]);
    $totalStudents = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_metrics: student count failed: ' . $e->getMessage());
}

// Average completion percentage across all students
$avgCompletion = 0;
try {
    $totalLessons = 0;
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
    error_log('analytics_metrics: completion calc failed: ' . $e->getMessage());
}

// Average grade across all graded submissions
$avgGrade = null;
try {
    $st = $pdo->prepare('SELECT ROUND(AVG((g.score / NULLIF(g.max_score, 0)) * 100), 1) FROM lms_grades g WHERE g.course_id = :cid AND g.status IN (\'draft\', \'released\')');
    $st->execute([':cid' => $courseId]);
    $val = $st->fetchColumn();
    if ($val !== false && $val !== null) {
        $avgGrade = (float) $val;
    }
} catch (\PDOException $e) {
    error_log('analytics_metrics: avg grade failed: ' . $e->getMessage());
}

// Pending reviews (submissions without a grade yet)
$pendingReviews = 0;
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM lms_submissions s LEFT JOIN lms_grades g ON g.submission_id = s.submission_id WHERE s.course_id = :cid AND g.grade_id IS NULL');
    $st->execute([':cid' => $courseId]);
    $pendingReviews = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_metrics: pending reviews failed: ' . $e->getMessage());
}

lms_ok([
    'total_students' => $totalStudents,
    'avg_completion' => $avgCompletion,
    'avg_grade' => $avgGrade,
    'pending_reviews' => $pendingReviews,
]);
