<?php
/**
 * GET /api/lms/analytics_engagement.php?course_id=<id>&period=<days>
 * Student engagement data for analytics bar charts.
 * Returns: [{student_name, activity_count}, ...]
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

$period = max(1, min((int) ($_GET['period'] ?? 30), 365));
$cutoff = date('Y-m-d H:i:s', time() - ($period * 86400));

$result = [];
try {
    // Count lesson completions + submissions per student within the period
    $st = $pdo->prepare(
        'SELECT u.name AS student_name, COUNT(*) AS activity_count
         FROM (
             SELECT c.user_id, c.created_at
             FROM lms_lesson_completions c
             JOIN lms_lessons l ON l.lesson_id = c.lesson_id
             JOIN lms_course_sections s ON s.section_id = l.section_id
             WHERE s.course_id = :cid1 AND c.created_at >= :cutoff1
             UNION ALL
             SELECT sub.student_user_id AS user_id, sub.submitted_at AS created_at
             FROM lms_submissions sub
             WHERE sub.course_id = :cid2 AND sub.submitted_at >= :cutoff2
         ) activity
         JOIN users u ON u.user_id = activity.user_id
         GROUP BY u.user_id, u.name
         ORDER BY activity_count DESC
         LIMIT 20'
    );
    $st->execute([
        ':cid1' => $courseId,
        ':cutoff1' => $cutoff,
        ':cid2' => $courseId,
        ':cutoff2' => $cutoff,
    ]);
    $result = $st->fetchAll();
} catch (\PDOException $e) {
    error_log('analytics_engagement: query failed: ' . $e->getMessage());
}

lms_ok($result);
