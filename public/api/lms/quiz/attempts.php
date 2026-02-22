<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['quizzes', 'lms_quizzes']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && lms_user_role($user) === 'admin';
$debug = ['steps' => []];

try {
    $pdo = db();
    $quizSql = 'SELECT assessment_id, course_id, status FROM lms_assessments WHERE assessment_id = :assessment_id AND deleted_at IS NULL LIMIT 1';
    $quizParams = [':assessment_id' => $assessmentId];
    $debug['steps'][] = ['step' => 'load_quiz', 'sql' => $quizSql, 'params' => $quizParams];
    $quizStmt = $pdo->prepare($quizSql);
    $quizStmt->execute($quizParams);
    $quiz = $quizStmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) {
        lms_error('not_found', 'Quiz not found', 404, $debugMode ? $debug : null);
    }

    lms_course_access($user, (int)$quiz['course_id']);

    $role = lms_user_role($user);
    if (!lms_is_staff_role($role) && (string)$quiz['status'] !== 'published') {
        lms_error('forbidden', 'Quiz is not published', 403, $debugMode ? $debug : null);
    }

    $all = in_array($role, ['manager', 'admin', 'ta'], true) ? 1 : 0;
    $attemptSql = 'SELECT attempt_id, assessment_id, user_id, status, score, max_score, started_at, submitted_at, grading_status
                   FROM lms_assessment_attempts
                   WHERE assessment_id = :assessment_id
                     AND (:all = 1 OR user_id = :user_id)
                   ORDER BY started_at DESC';
    $attemptParams = [':assessment_id' => $assessmentId, ':all' => $all, ':user_id' => (int)$user['user_id']];
    $debug['steps'][] = ['step' => 'load_attempts', 'sql' => $attemptSql, 'params' => $attemptParams];
    $stmt = $pdo->prepare($attemptSql);
    $stmt->execute($attemptParams);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $score = $row['score'] === null ? null : (float)$row['score'];
        $maxScore = $row['max_score'] === null ? null : (float)$row['max_score'];
        $items[] = [
            'attempt_id' => (int)$row['attempt_id'],
            'assessment_id' => (int)$row['assessment_id'],
            'user_id' => (int)$row['user_id'],
            'status' => (string)$row['status'],
            'score' => $score,
            'max_score' => $maxScore,
            'score_pct' => ($score !== null && $maxScore !== null && $maxScore > 0)
                ? (int)round(($score / $maxScore) * 100)
                : null,
            'started_at' => $row['started_at'],
            'submitted_at' => $row['submitted_at'],
            'grading_status' => (string)$row['grading_status'],
        ];
    }

    $response = ['items' => $items];
    if ($debugMode) {
        $response['debug'] = $debug;
    }
    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/quiz/attempts.php failed assessment_id=' . $assessmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('attempts_fetch_failed', 'Failed to load attempts', 500, $details);
}
