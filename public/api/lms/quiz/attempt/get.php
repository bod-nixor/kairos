<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['ta', 'manager', 'admin']);
$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    lms_error('validation_error', 'attempt_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT a.attempt_id, a.assessment_id, a.user_id AS student_user_id, a.status, a.score, a.max_score, a.started_at, a.submitted_at, q.course_id
  FROM lms_assessment_attempts a
  JOIN lms_assessments q ON q.assessment_id = a.assessment_id
  WHERE a.attempt_id = :attempt_id LIMIT 1');
$stmt->execute([':attempt_id' => $attemptId]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) {
    lms_error('not_found', 'Attempt not found', 404);
}

lms_course_access($user, (int)$attempt['course_id']);

$respStmt = $pdo->prepare('SELECT response_id, question_id, response_json, auto_score, max_score, needs_manual_grading, graded_at
 FROM lms_assessment_responses
 WHERE attempt_id = :attempt_id
 ORDER BY response_id ASC');
$respStmt->execute([':attempt_id' => $attemptId]);

lms_ok([
    'attempt' => $attempt,
    'responses' => $respStmt->fetchAll(PDO::FETCH_ASSOC),
]);
