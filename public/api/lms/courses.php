<?php
/**
 * GET /api/lms/courses.php?course_id=<course_id>
 * Returns course metadata for the LMS course home page.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();

// Check if description column exists
$hasDesc = false;
try {
    $chk = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1');
    $chk->execute([':t' => 'courses', ':c' => 'description']);
    $hasDesc = (bool) $chk->fetchColumn();
} catch (\PDOException $e) {
}

// Check if visibility column exists
$hasVisibility = false;
try {
    $chk = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1');
    $chk->execute([':t' => 'courses', ':c' => 'visibility']);
    $hasVisibility = (bool) $chk->fetchColumn();
} catch (\PDOException $e) {
}

$columns = "CAST(course_id AS UNSIGNED) AS id, name, COALESCE(code, '') AS code";
$columns .= $hasDesc ? ", COALESCE(description, '') AS description" : ", '' AS description";
$columns .= $hasVisibility ? ", visibility" : ", 'public' AS visibility";

$stmt = $pdo->prepare("SELECT $columns FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1");
$stmt->execute([':cid' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    lms_error('not_found', 'Course not found.', 404);
}

$courseRole = lms_course_role($user, $courseId);
$course['my_role'] = $courseRole;
$course['capabilities'] = [
    'view_course' => $courseRole !== null,
    'manage_course' => rbac_can($pdo, $user, 'manage_course', $courseId),
    'grade_course' => rbac_can($pdo, $user, 'grade_course', $courseId),
    'update_student_progress' => rbac_can($pdo, $user, 'update_student_progress', $courseId),
    'manage_course_announcements' => rbac_can($pdo, $user, 'manage_course_announcements', $courseId),
];
$course['code'] = $course['code'] ?? $course['name'];

lms_ok($course);
