<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

lms_require_feature(['quizzes', 'lms_quizzes']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
$assessment = lms_require_published_assessment($assessmentId, $user);

if ($courseId > 0 && (int)$assessment['course_id'] !== $courseId) {
    lms_error('not_found', 'Quiz not found in this course', 404);
}

$pdo = db();
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:a AND user_id=:u');
$countStmt->execute([':a' => $assessmentId, ':u' => (int)$user['user_id']]);
$attemptsUsed = (int)$countStmt->fetchColumn();

$qStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_questions WHERE assessment_id=:a AND deleted_at IS NULL');
$qStmt->execute([':a' => $assessmentId]);
$questionCount = (int)$qStmt->fetchColumn();

lms_ok([
    'quiz_id' => (int)$assessment['assessment_id'],
    'assessment_id' => (int)$assessment['assessment_id'],
    'course_id' => (int)$assessment['course_id'],
    'title' => (string)$assessment['title'],
    'description' => (string)($assessment['instructions'] ?? ''),
    'instructions' => (string)($assessment['instructions'] ?? ''),
    'status' => (string)$assessment['status'],
    'max_attempts' => (int)$assessment['max_attempts'],
    'attempts_used' => $attemptsUsed,
    'time_limit_min' => $assessment['time_limit_minutes'] === null ? null : (int)$assessment['time_limit_minutes'],
    'time_limit_minutes' => $assessment['time_limit_minutes'] === null ? null : (int)$assessment['time_limit_minutes'],
    'question_count' => $questionCount,
    'available_from' => $assessment['available_from'],
    'due_at' => $assessment['due_at'],
]);
