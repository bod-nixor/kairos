<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
lms_require_feature(['course_analytics', 'analytics']);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

lms_course_access($user, $courseId);
$pdo = db();

$cacheStmt = $pdo->prepare('SELECT payload_json FROM lms_course_analytics_cache WHERE course_id = :course_id AND expires_at > NOW() LIMIT 1');
$cacheStmt->execute([':course_id' => $courseId]);
$cachedPayload = $cacheStmt->fetchColumn();
if (is_string($cachedPayload) && $cachedPayload !== '') {
    $decoded = json_decode($cachedPayload, true);
    if (is_array($decoded)) {
        lms_ok($decoded);
    }
}

$sections = $pdo->prepare(
    'SELECT s.section_id, s.title,
            ROUND((COUNT(DISTINCT c.completion_id)/NULLIF(COUNT(DISTINCT l.lesson_id)*NULLIF((SELECT COUNT(*) FROM student_courses WHERE course_id=:sub_course_id),0),0))*100,2) AS completion_percent
     FROM lms_course_sections s
     LEFT JOIN lms_lessons l ON l.section_id = s.section_id AND l.deleted_at IS NULL
     LEFT JOIN lms_lesson_completions c ON c.lesson_id = l.lesson_id
     WHERE s.course_id = :course_id AND s.deleted_at IS NULL
     GROUP BY s.section_id, s.title'
);
$sections->execute([':course_id' => $courseId, ':sub_course_id' => $courseId]);

$quiz = $pdo->prepare('SELECT ROUND(AVG(score),2) AS avg_score FROM lms_assessment_attempts WHERE course_id=:course_id AND status IN (\'auto_graded\',\'graded\')');
$quiz->execute([':course_id' => $courseId]);

$assignment = $pdo->prepare('SELECT ROUND((COUNT(DISTINCT s.student_user_id)/NULLIF((SELECT COUNT(*) FROM student_courses WHERE course_id=:sub_course_id),0))*100,2) AS submission_rate, ROUND((SUM(CASE WHEN s.is_late=1 THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0))*100,2) AS late_percent FROM lms_submissions s WHERE s.course_id=:course_id');
$assignment->execute([':course_id' => $courseId, ':sub_course_id' => $courseId]);
$assignmentStats = $assignment->fetch();
if ($assignmentStats === false) {
    $assignmentStats = null;
}

$ta = $pdo->prepare('SELECT g.graded_by AS ta_user_id, COUNT(*) AS graded_count, SUM(CASE WHEN g.status=\'draft\' THEN 1 ELSE 0 END) AS pending_count FROM lms_grades g WHERE g.course_id=:course_id GROUP BY g.graded_by');
$ta->execute([':course_id' => $courseId]);

$missing = $pdo->prepare('SELECT q.question_id, q.prompt, COUNT(*) AS misses FROM lms_assessment_responses r JOIN lms_questions q ON q.question_id=r.question_id WHERE q.assessment_id IN (SELECT assessment_id FROM lms_assessments WHERE course_id=:course_id) AND (r.max_score IS NULL OR r.score < r.max_score) GROUP BY q.question_id, q.prompt ORDER BY misses DESC LIMIT 5');
$missing->execute([':course_id' => $courseId]);

$result = [
    'section_completion' => $sections->fetchAll(),
    'quiz_stats' => ['avg_score' => $quiz->fetchColumn(), 'most_missed_questions' => $missing->fetchAll()],
    'assignment_stats' => $assignmentStats,
    'ta_workload' => $ta->fetchAll(),
];

$encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encodedResult === false) {
    error_log('analytics_cache_encode_failed course_id=' . $courseId . ' json_error=' . json_last_error_msg());
    lms_error('server_error', 'Failed to encode analytics payload', 500);
}

$ttlSeconds = (int)env('LMS_ANALYTICS_CACHE_TTL_SECONDS', 120);
if ($ttlSeconds < 60) {
    $ttlSeconds = 60;
}
$saveCache = $pdo->prepare('INSERT INTO lms_course_analytics_cache (course_id, payload_json, refreshed_at, expires_at) VALUES (:course_id, :payload_json, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND)) AS new_row ON DUPLICATE KEY UPDATE payload_json = new_row.payload_json, refreshed_at = new_row.refreshed_at, expires_at = new_row.expires_at');
$saveCache->execute([
    ':course_id' => $courseId,
    ':payload_json' => $encodedResult,
    ':ttl' => $ttlSeconds,
]);

lms_ok($result);
