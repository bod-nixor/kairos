<?php
/**
 * GET /api/lms/activity.php?course_id=<id>[&limit=8]
 * Returns recent activity for the user in a given course.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 50)) : 8;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course_id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();
$userId = (int) $user['user_id'];
$events = [];

// Recent lesson completions
try {
    $stmt = $pdo->prepare(
        'SELECT lc.completed_at AS created_at, l.title AS lesson_name
         FROM lms_lesson_completions lc
         JOIN lms_lessons l ON l.lesson_id = lc.lesson_id
         WHERE l.course_id = :cid AND lc.user_id = :uid AND l.deleted_at IS NULL
         ORDER BY lc.completed_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':cid', $courseId, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = [
            'type' => 'lesson_complete',
            'message' => 'Completed lesson: ' . ($row['lesson_name'] ?? 'Untitled'),
            'created_at' => $row['created_at'],
        ];
    }
} catch (\PDOException $e) {
    error_log('lms/activity.php: lesson completions query failed: ' . $e->getMessage());
}

// Recent submissions
try {
    $stmt = $pdo->prepare(
        'SELECT s.submitted_at AS created_at, a.title AS assignment_name
         FROM lms_submissions s
         JOIN lms_assignments a ON a.assignment_id = s.assignment_id
         WHERE a.course_id = :cid AND s.student_user_id = :uid AND a.deleted_at IS NULL
         ORDER BY s.submitted_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':cid', $courseId, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = [
            'type' => 'assignment_submit',
            'message' => 'Submitted: ' . ($row['assignment_name'] ?? 'Assignment'),
            'created_at' => $row['created_at'],
        ];
    }
} catch (\PDOException $e) {
    error_log('lms/activity.php: submissions query failed: ' . $e->getMessage());
}

// Sort by date descending and limit
usort($events, function ($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

$events = array_slice($events, 0, $limit);

lms_ok($events);
