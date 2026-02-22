<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    lms_error('validation_error', 'assessment_id required', 422);
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1' && lms_user_role($user) === 'admin';
$debug = ['steps' => []];

try {
    $pdo = db();
    $aSql = 'SELECT assessment_id, course_id, status FROM lms_assessments WHERE assessment_id = :assessment_id AND deleted_at IS NULL LIMIT 1';
    $aParams = [':assessment_id' => $assessmentId];
    $debug['steps'][] = ['step' => 'load_quiz', 'sql' => $aSql, 'params' => $aParams];
    $aStmt = $pdo->prepare($aSql);
    $aStmt->execute($aParams);
    $assessment = $aStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assessment) {
        lms_error('not_found', 'Quiz not found', 404, $debugMode ? $debug : null);
    }

    lms_course_access($user, (int)$assessment['course_id']);
    $role = lms_user_role($user);
    if (!lms_is_staff_role($role) && (string)$assessment['status'] !== 'published') {
        lms_error('forbidden', 'Quiz is not published', 403, $debugMode ? $debug : null);
    }

    $qSql = 'SELECT question_id, prompt, question_type, points, position FROM lms_questions WHERE assessment_id = :assessment_id ORDER BY position ASC, question_id ASC';
    $qParams = [':assessment_id' => $assessmentId];
    $debug['steps'][] = ['step' => 'load_questions', 'sql' => $qSql, 'params' => $qParams];
    $qStmt = $pdo->prepare($qSql);
    $qStmt->execute($qParams);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $questionIds = array_map(static fn(array $q): int => (int)$q['question_id'], $questions);
    $optionsByQuestion = [];
    if (!empty($questionIds)) {
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $oSql = "SELECT question_id, option_text, option_value, position FROM lms_question_options WHERE question_id IN ($ph) ORDER BY question_id ASC, position ASC, option_id ASC";
        $debug['steps'][] = ['step' => 'load_options', 'sql' => $oSql, 'params' => $questionIds];
        $oStmt = $pdo->prepare($oSql);
        $oStmt->execute($questionIds);
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            $qid = (int)$opt['question_id'];
            $optionsByQuestion[$qid][] = [
                'value' => (string)($opt['option_value'] ?? ''),
                'text' => (string)($opt['option_text'] ?? ''),
            ];
        }
    }

    $items = [];
    foreach ($questions as $q) {
        $qid = (int)$q['question_id'];
        $items[] = [
            'id' => $qid,
            'question_id' => $qid,
            'prompt' => (string)$q['prompt'],
            'question_type' => (string)$q['question_type'],
            'points' => (float)$q['points'],
            'position' => (int)$q['position'],
            'options' => $optionsByQuestion[$qid] ?? [],
        ];
    }

    $response = ['items' => $items];
    if ($debugMode) {
        $response['debug'] = $debug;
    }
    lms_ok($response);
} catch (Throwable $e) {
    error_log('lms/quiz/question/list.php failed assessment_id=' . $assessmentId . ' user_id=' . (int)$user['user_id'] . ' message=' . $e->getMessage());
    $details = $debugMode ? array_merge($debug, ['exception' => $e->getMessage()]) : null;
    lms_error('questions_fetch_failed', 'Failed to load quiz questions', 500, $details);
}
