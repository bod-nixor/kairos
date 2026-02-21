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

// Fetch course basic info
if ($hasDesc) {
    $stmt = $pdo->prepare("SELECT CAST(course_id AS UNSIGNED) AS id, name, COALESCE(code, '') AS code, COALESCE(description, '') AS description FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1");
} else {
    $stmt = $pdo->prepare("SELECT CAST(course_id AS UNSIGNED) AS id, name, COALESCE(code, '') AS code, '' AS description FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1");
}
$stmt->execute([':cid' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    lms_error('not_found', 'Course not found.', 404);
}

// Determine the user's role in this course context
$role = lms_user_role($user);

// Check for course-level staff role (TA/manager assigned to this course)
if ($role === 'ta' || $role === 'student') {
    try {
        $staffStmt = $pdo->prepare('SELECT role FROM course_staff WHERE user_id = :uid AND course_id = :cid LIMIT 1');
        $staffStmt->execute([':uid' => (int) $user['user_id'], ':cid' => $courseId]);
        $staffRole = $staffStmt->fetchColumn();
        if ($staffRole) {
            $role = strtolower($staffRole);
        }
    } catch (\PDOException $e) {
        error_log('lms/courses.php: course_staff lookup failed: ' . $e->getMessage());
    }
}

$course['my_role'] = $role;
$course['code'] = $course['code'] ?? $course['name'];

lms_ok($course);

