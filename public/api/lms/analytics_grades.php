<?php
/**
 * GET /api/lms/analytics_grades.php?assignment_id=<id>&course_id=<id>
 * Grade distribution buckets for a specific assignment.
 * Returns: [{range, count}, ...]
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$courseId = (int) ($_GET['course_id'] ?? 0);
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}
if ($assignmentId <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}
lms_course_access($user, $courseId);
$pdo = db();

// Define grade buckets
$buckets = [
    ['label' => '0-10%', 'min' => 0, 'max' => 10],
    ['label' => '10-20%', 'min' => 10, 'max' => 20],
    ['label' => '20-30%', 'min' => 20, 'max' => 30],
    ['label' => '30-40%', 'min' => 30, 'max' => 40],
    ['label' => '40-50%', 'min' => 40, 'max' => 50],
    ['label' => '50-60%', 'min' => 50, 'max' => 60],
    ['label' => '60-70%', 'min' => 60, 'max' => 70],
    ['label' => '70-80%', 'min' => 70, 'max' => 80],
    ['label' => '80-90%', 'min' => 80, 'max' => 90],
    ['label' => '90-100%', 'min' => 90, 'max' => 101],
];

$result = [];
try {
    $st = $pdo->prepare(
        'SELECT ROUND((g.score / NULLIF(g.max_score, 0)) * 100, 2) AS pct
         FROM lms_grades g
         WHERE g.assignment_id = :aid AND g.course_id = :cid
           AND g.status IN (\'draft\', \'released\')
           AND g.max_score > 0'
    );
    $st->execute([':aid' => $assignmentId, ':cid' => $courseId]);
    $grades = $st->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($buckets as $bucket) {
        $count = 0;
        foreach ($grades as $pct) {
            $p = (float) $pct;
            if ($p >= $bucket['min'] && $p < $bucket['max']) {
                $count++;
            }
        }
        $result[] = [
            'range' => $bucket['label'],
            'count' => $count,
        ];
    }
} catch (\PDOException $e) {
    error_log('analytics_grades: query failed: ' . $e->getMessage());
    // Return empty buckets so UI still renders
    foreach ($buckets as $bucket) {
        $result[] = ['range' => $bucket['label'], 'count' => 0];
    }
}

lms_ok($result);
