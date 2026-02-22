<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT assessment_id, course_id, section_id, title, instructions, status, max_attempts, time_limit_minutes, available_from, due_at FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $assessmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    lms_error('not_found', 'Quiz not found', 404);
}

if ($courseId > 0 && (int)$row['course_id'] !== $courseId) {
    lms_error('not_found', 'Quiz not found in this course', 404);
}

lms_course_access($user, (int)$row['course_id']);
$role = lms_user_role($user);
if (!lms_is_staff_role($role) && (string)$row['status'] !== 'published') {
    lms_error('forbidden', 'Quiz is not published', 403);
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:a AND user_id=:u');
$countStmt->execute([':a' => $assessmentId, ':u' => (int)$user['user_id']]);
$attemptsUsed = (int)$countStmt->fetchColumn();

$qStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_questions WHERE assessment_id=:a');
$qStmt->execute([':a' => $assessmentId]);
$questionCount = (int)$qStmt->fetchColumn();

lms_ok([
    'quiz_id' => (int)$row['assessment_id'],
    'assessment_id' => (int)$row['assessment_id'],
    'course_id' => (int)$row['course_id'],
    'title' => (string)$row['title'],
    'description' => (string)($row['instructions'] ?? ''),
    'instructions' => (string)($row['instructions'] ?? ''),
    'status' => (string)$row['status'],
    'max_attempts' => (int)$row['max_attempts'],
    'attempts_used' => $attemptsUsed,
    'time_limit_min' => $row['time_limit_minutes'] === null ? null : (int)$row['time_limit_minutes'],
    'time_limit_minutes' => $row['time_limit_minutes'] === null ? null : (int)$row['time_limit_minutes'],
    'question_count' => $questionCount,
    'available_from' => $row['available_from'],
    'due_at' => $row['due_at'],
]);
