<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/access_policy.php';

lms_require_feature(['quizzes', 'lms_quizzes']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();
$assessmentId = (int)($in['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$pdo = db();
$assessment = $pdo->prepare('SELECT assessment_id, course_id, max_attempts, status FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
$assessment->execute([':id' => $assessmentId]);
$a = $assessment->fetch();
if (!$a) {
    lms_error('not_found', 'Assessment not found', 404);
}

$courseId = (int)$a['course_id'];
$access = rbac_course_access_context($pdo, $user, $courseId);
$attemptDecision = lms_quiz_student_attempt_decision($access, (string)$a['status']);
if (!$attemptDecision['allowed']) {
    lms_error(
        (string)$attemptDecision['code'],
        (string)$attemptDecision['message'],
        (int)$attemptDecision['status']
    );
}

$pdo->beginTransaction();
try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:a AND user_id=:u FOR UPDATE');
    $countStmt->execute([':a' => $assessmentId, ':u' => (int)$user['user_id']]);
    $count = (int)$countStmt->fetchColumn();

    if ((int)$a['max_attempts'] > 0 && $count >= (int)$a['max_attempts']) {
        $pdo->rollBack();
        lms_error('attempt_limit', 'Attempt limit reached', 409);
    }

    $pdo->prepare('INSERT INTO lms_assessment_attempts (assessment_id,course_id,user_id) VALUES (:a,:c,:u)')->execute([
        ':a' => $assessmentId,
        ':c' => $courseId,
        ':u' => (int)$user['user_id'],
    ]);
    $attemptId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(json_encode([
        'context' => 'lms.quiz.attempt.start',
        'assessment_id' => $assessmentId,
        'course_id' => $courseId,
        'user_id' => (int)$user['user_id'],
        'exception_message' => $e->getMessage(),
    ]));
    lms_error('attempt_create_failed', 'Unable to start attempt', 500);
}

lms_ok(['attempt_id' => $attemptId]);
