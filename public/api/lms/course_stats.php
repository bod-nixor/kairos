<?php
/**
 * GET /api/lms/course_stats.php?course_id=<id>
 * Returns aggregate stats for the course home banner.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course_id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();
$userId = (int) $user['user_id'];

// Count modules (sections)
$modules = 0;
try {
    $modStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_course_sections WHERE course_id = :cid AND deleted_at IS NULL');
    $modStmt->execute([':cid' => $courseId]);
    $modules = (int) $modStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('lms/course_stats.php: modules query failed: ' . $e->getMessage());
}

// Count lessons total and completed
$lessonTotal = 0;
$lessonDone = 0;
try {
    $ltStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_lessons WHERE course_id = :cid AND deleted_at IS NULL');
    $ltStmt->execute([':cid' => $courseId]);
    $lessonTotal = (int) $ltStmt->fetchColumn();

    $ldStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_lesson_completions WHERE user_id = :uid AND lesson_id IN (SELECT lesson_id FROM lms_lessons WHERE course_id = :cid AND deleted_at IS NULL)');
    $ldStmt->execute([':uid' => $userId, ':cid' => $courseId]);
    $lessonDone = (int) $ldStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('lms/course_stats.php: lessons query failed: ' . $e->getMessage());
}

// Count assignments
$assignments = 0;
try {
    $aStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assignments WHERE course_id = :cid AND deleted_at IS NULL');
    $aStmt->execute([':cid' => $courseId]);
    $assignments = (int) $aStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('lms/course_stats.php: assignments query failed: ' . $e->getMessage());
}

// Count quizzes
$quizzes = 0;
try {
    $qStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessments WHERE course_id = :cid AND deleted_at IS NULL');
    $qStmt->execute([':cid' => $courseId]);
    $quizzes = (int) $qStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('lms/course_stats.php: quizzes query failed: ' . $e->getMessage());
}

$completionPct = $lessonTotal > 0 ? (int) round(($lessonDone / $lessonTotal) * 100) : 0;

lms_ok([
    'modules' => $modules,
    'completed_items' => $lessonDone,
    'assignments' => $assignments,
    'assignments_due' => $assignments,
    'quizzes' => $quizzes,
    'completion_pct' => $completionPct,
]);
