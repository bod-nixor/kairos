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
    lms_error('validation_error', 'Missing or invalid course id.', 422);
}

$pdo = db();
$access = lms_course_home_access($user, $courseId);

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

$stmt = $pdo->prepare("SELECT $columns FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) AND is_active = 1 LIMIT 1");
$stmt->execute([':cid' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    lms_error('not_found', 'Course not found.', 404);
}

$courseRole = lms_course_role($user, $courseId);
$course['my_role'] = $courseRole;
$course['capabilities'] = lms_course_capabilities($user, $courseId);
$course['access_context'] = $courseRole;
$course['enrolled'] = (bool)$access['view_course_enrolled'];
$course['assigned_staff'] = (bool)($access['assigned_ta'] || $access['assigned_manager']);
$course['can_self_enroll'] = (bool)$access['can_self_enroll'];
$course['code'] = $course['code'] ?? $course['name'];

lms_ok($course);
