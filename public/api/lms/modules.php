<?php
/**
 * GET /api/lms/modules.php?course_id=<id>[&preview=1]
 * Returns course modules (sections) with lesson counts.
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

$stmt = $pdo->prepare(
    'SELECT s.section_id, s.title AS name, s.description, s.position,
            (SELECT COUNT(*) FROM lms_lessons l WHERE l.section_id = s.section_id AND l.deleted_at IS NULL) AS total_items,
            (SELECT COUNT(*) FROM lms_lesson_completions lc
             JOIN lms_lessons l2 ON l2.lesson_id = lc.lesson_id
             WHERE l2.section_id = s.section_id AND l2.deleted_at IS NULL AND lc.user_id = :uid) AS completed_items
     FROM lms_course_sections s
     WHERE s.course_id = :cid AND s.deleted_at IS NULL
     ORDER BY s.position ASC, s.section_id ASC'
);
$stmt->execute([':cid' => $courseId, ':uid' => $userId]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

lms_ok($modules);
