<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$courseId = (int)($_GET['course_id'] ?? 0);
if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && lms_user_role($user) === 'admin';
$debug = ['steps' => []];

try {
    $pdo = db();
    $sql = 'SELECT assessment_id, course_id, section_id, title, instructions, status, max_attempts, time_limit_minutes, available_from, due_at
            FROM lms_assessments
            WHERE assessment_id = :assessment_id AND deleted_at IS NULL
            LIMIT 1';
    $params = [':assessment_id' => $assessmentId];
    $debug['steps'][] = ['step' => 'load_quiz', 'sql' => $sql, 'params' => $params];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        lms_error('not_found', 'Quiz not found', 404, $debugMode ? $debug : null);
    }

    if ($courseId > 0 && (int)$row['course_id'] !== $courseId) {
        lms_error('not_found', 'Quiz not found in this course', 404, $debugMode ? $debug : null);
    }

    lms_course_access($user, (int)$row['course_id']);
    $role = lms_user_role($user);
    if (!lms_is_staff_role($role) && (string)$row['status'] !== 'published') {
        lms_error('forbidden', 'Quiz is not published', 403, $debugMode ? $debug : null);
    }

    $countSql = 'SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id = :assessment_id AND user_id = :user_id';
    $countParams = [':assessment_id' => $assessmentId, ':user_id' => (int)$user['user_id']];
    $debug['steps'][] = ['step' => 'count_attempts', 'sql' => $countSql, 'params' => $countParams];
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $attemptsUsed = (int)$countStmt->fetchColumn();

    $questionSql = 'SELECT COUNT(*) FROM lms_questions WHERE assessment_id = :assessment_id';
    $questionParams = [':assessment_id' => $assessmentId];
    $debug['steps'][] = ['step' => 'count_questions', 'sql' => $questionSql, 'params' => $questionParams];
    $qStmt = $pdo->prepare($questionSql);
    $qStmt->execute($questionParams);
    $questionCount = (int)$qStmt->fetchColumn();

    $response = [
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
    ];
    if ($debugMode) {
        $response['debug'] = $debug;
    }

    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/quiz/get.php failed assessment_id=' . $assessmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('quiz_fetch_failed', 'Failed to load quiz', 500, $details);
}
