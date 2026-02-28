<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = lms_require_roles(['student']);
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

$email = (string)($user['email'] ?? '');
$canJoin = ((string)$course['visibility'] === 'public');
if (!$canJoin) {
    $allowStmt = $pdo->prepare('SELECT 1 FROM course_allowlist WHERE course_id = :cid AND LOWER(email) = LOWER(:email) LIMIT 1');
    $allowStmt->execute([':cid' => $courseId, ':email' => $email]);
    $canJoin = (bool)$allowStmt->fetchColumn();
}

if (!$canJoin) {
    lms_error('forbidden', 'You are not eligible to join this course', 403);
}

$ins = $pdo->prepare('INSERT INTO student_courses (course_id, user_id) VALUES (:cid, :uid) ON DUPLICATE KEY UPDATE user_id = user_id');
$ins->execute([':cid' => $courseId, ':uid' => (int)$user['user_id']]);

lms_ok(['joined' => true]);
