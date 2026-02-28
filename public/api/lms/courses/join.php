<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$role = lms_user_role($user);
if (!in_array($role, ['student', 'manager', 'admin'], true)) {
    lms_error('forbidden', 'Insufficient permissions to join course.', 403);
}

$in = lms_json_input();
$courseId = (int)($in['course_id'] ?? 0);
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

$pdo = db();
$courseStmt = $pdo->prepare('SELECT course_id, COALESCE(visibility, "public") AS visibility FROM courses WHERE course_id = :cid LIMIT 1');
$courseStmt->execute([':cid' => $courseId]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    lms_error('not_found', 'Course not found', 404);
}

$canJoin = ((string)$course['visibility'] === 'public');
$email = strtolower((string)($user['email'] ?? ''));
if (!$canJoin && $email !== '') {
    $allowStmt = $pdo->prepare('SELECT 1 FROM course_allowlist WHERE course_id = :cid AND LOWER(email) = :email LIMIT 1');
    $allowStmt->execute([':cid' => $courseId, ':email' => $email]);
    $canJoin = (bool)$allowStmt->fetchColumn();
}

if ($role === 'admin') {
    $canJoin = true;
}

if (!$canJoin) {
    lms_error('forbidden', 'You are not eligible to join this course', 403);
}

$ins = $pdo->prepare('INSERT INTO student_courses (course_id, user_id) VALUES (:cid, :uid) ON DUPLICATE KEY UPDATE user_id = user_id');
$ins->execute([':cid' => $courseId, ':uid' => (int)$user['user_id']]);

lms_ok(['joined' => true, 'course_id' => $courseId]);
