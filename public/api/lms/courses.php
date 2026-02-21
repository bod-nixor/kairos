<?php
/**
 * GET /api/lms/courses.php?id=<course_id>
 * Returns course metadata for the LMS course home page.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();

// Fetch course basic info
$stmt = $pdo->prepare('SELECT course_id AS id, name, COALESCE(description, \'\') AS description FROM courses WHERE course_id = :cid LIMIT 1');
$stmt->execute([':cid' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    lms_error('not_found', 'Course not found.', 404);
}

// Determine the user's role in this course context
$role = lms_user_role($user);

// Check for course-level staff role (TA/manager assigned to this course)
if ($role === 'ta' || $role === 'student') {
    $staffStmt = $pdo->prepare('SELECT role FROM course_staff WHERE user_id = :uid AND course_id = :cid LIMIT 1');
    $staffStmt->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId]);
    $staffRole = $staffStmt->fetchColumn();
    if ($staffRole) {
        $role = strtolower($staffRole);
    }
}

$course['my_role'] = $role;

// Try to get course code if available (fallback to name)
$course['code'] = $course['name'];

lms_ok($course);
