<?php
/**
 * GET /api/lms/analytics_completion.php?course_id=<id>
 * Per-module content completion rates for charts.
 * Returns: [{module_name, completion_pct}, ...]
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

// Student count — abort on failure (bogus 0 would skew all percentages)
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM student_courses WHERE course_id = :cid');
    $st->execute([':cid' => $courseId]);
    $totalStudents = (int) $st->fetchColumn();
} catch (\PDOException $e) {
    error_log('analytics_completion: student count failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to query student count', 500);
}

$result = [];
try {
    $st = $pdo->prepare(
        'SELECT s.section_id, s.title AS module_name,
                COUNT(DISTINCT l.lesson_id) AS total_lessons,
                COUNT(DISTINCT c.completion_id) AS completions
         FROM lms_course_sections s
         LEFT JOIN lms_lessons l ON l.section_id = s.section_id AND l.deleted_at IS NULL
         LEFT JOIN lms_lesson_completions c ON c.lesson_id = l.lesson_id
         WHERE s.course_id = :cid AND s.deleted_at IS NULL
         GROUP BY s.section_id, s.title
         ORDER BY s.position ASC, s.section_id ASC'
    );
    $st->execute([':cid' => $courseId]);
    foreach ($st->fetchAll() as $row) {
        // Only compute per-student denominator when we have a valid student count
        if ($totalStudents > 0) {
            $totalPossible = (int) $row['total_lessons'] * $totalStudents;
            $pct = $totalPossible > 0
                ? round(((int) $row['completions'] / $totalPossible) * 100, 1)
                : 0;
        } else {
            $pct = 0;
        }
        $result[] = [
            'module_name' => $row['module_name'],
            'completion_pct' => $pct,
        ];
    }
} catch (\PDOException $e) {
    error_log('analytics_completion: query failed course_id=' . $courseId . ' error=' . $e->getMessage());
    lms_error('server_error', 'Failed to compute completion data', 500);
}

lms_ok($result);
