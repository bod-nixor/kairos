<?php
/**
 * GET /api/lms/analytics_students.php?course_id=<id>
 * Student roster with progress data for the analytics table.
 * Returns: [{name, email, completion_pct, avg_grade, submission_count, last_active}, ...]
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

// Total lessons for completion percentage
$totalLessons = 0;
try {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM lms_lessons l
         JOIN lms_course_sections s ON s.section_id = l.section_id
         WHERE s.course_id = :cid AND l.deleted_at IS NULL AND s.deleted_at IS NULL'
    );
    $st->execute([':cid' => $courseId]);
    $totalLessons = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_students: lesson count failed: ' . $e->getMessage());
}

$result = [];
try {
    $st = $pdo->prepare(
        'SELECT u.user_id, u.name, u.email,
                (SELECT COUNT(*) FROM lms_lesson_completions c
                 JOIN lms_lessons l ON l.lesson_id = c.lesson_id
                 JOIN lms_course_sections s ON s.section_id = l.section_id
                 WHERE s.course_id = :cid2 AND c.user_id = u.user_id) AS completed_lessons,
                (SELECT ROUND(AVG((g.score / NULLIF(g.max_score, 0)) * 100), 1)
                 FROM lms_grades g WHERE g.student_user_id = u.user_id AND g.course_id = :cid3
                 AND g.status IN (\'draft\', \'released\')) AS avg_grade,
                (SELECT COUNT(*) FROM lms_submissions sub
                 WHERE sub.student_user_id = u.user_id AND sub.course_id = :cid4) AS submission_count,
                (SELECT MAX(GREATEST(
                    COALESCE((SELECT MAX(c2.created_at) FROM lms_lesson_completions c2
                              JOIN lms_lessons l2 ON l2.lesson_id = c2.lesson_id
                              JOIN lms_course_sections s2 ON s2.section_id = l2.section_id
                              WHERE s2.course_id = :cid5 AND c2.user_id = u.user_id), \'1970-01-01\'),
                    COALESCE((SELECT MAX(sub2.submitted_at) FROM lms_submissions sub2
                              WHERE sub2.student_user_id = u.user_id AND sub2.course_id = :cid6), \'1970-01-01\')
                ))) AS last_active
         FROM student_courses sc
         JOIN users u ON u.user_id = sc.user_id
         WHERE sc.course_id = :cid1
         ORDER BY u.name ASC
         LIMIT 500'
    );
    $st->execute([
        ':cid1' => $courseId,
        ':cid2' => $courseId,
        ':cid3' => $courseId,
        ':cid4' => $courseId,
        ':cid5' => $courseId,
        ':cid6' => $courseId,
    ]);
    foreach ($st->fetchAll() as $row) {
        $completedLessons = (int) $row['completed_lessons'];
        $completionPct = $totalLessons > 0
            ? round(($completedLessons / $totalLessons) * 100, 1)
            : 0;
        $lastActive = ($row['last_active'] && $row['last_active'] !== '1970-01-01')
            ? $row['last_active']
            : null;
        $result[] = [
            'name' => $row['name'],
            'email' => $row['email'],
            'completion_pct' => $completionPct,
            'avg_grade' => $row['avg_grade'] !== null ? (float) $row['avg_grade'] : null,
            'submission_count' => (int) $row['submission_count'],
            'last_active' => $lastActive,
        ];
    }
} catch (\PDOException $e) {
    error_log('analytics_students: query failed: ' . $e->getMessage());
}

lms_ok($result);
