<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_helpers.php';

lms_require_feature(['quizzes', 'lms_quizzes']);

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();
$attemptId = (int)($in['attempt_id'] ?? 0);
$responses = $in['responses'] ?? [];
if ($attemptId <= 0 || !is_array($responses)) {
    lms_error('validation_error', 'attempt_id and responses required', 422);
}
if (!lms_quiz_is_question_response_map($responses)) {
    lms_error('validation_error', 'responses must be an object keyed by question_id', 422);
}

$pdo = db();
$attemptStmt = $pdo->prepare(
    "SELECT attempt.attempt_id, attempt.assessment_id, attempt.course_id, attempt.user_id, attempt.status
     FROM lms_assessment_attempts attempt
     JOIN lms_assessments assessment
       ON assessment.assessment_id = attempt.assessment_id
      AND assessment.course_id = attempt.course_id
      AND assessment.deleted_at IS NULL
      AND assessment.status = 'published'
     WHERE attempt.attempt_id = :id
     LIMIT 1"
);
$attemptStmt->execute([':id' => $attemptId]);
$attempt = $attemptStmt->fetch();
if (!$attempt) {
    lms_error('not_found', 'Attempt not found', 404);
}
if ((int)$attempt['user_id'] !== (int)$user['user_id']) {
    lms_error('forbidden', 'Cannot submit another student attempt', 403);
}

// Verify enrollment is still valid (defense-in-depth)
lms_course_access($user, (int)$attempt['course_id'], false);

if ((string)$attempt['status'] !== 'in_progress') {
    lms_error('conflict', 'Attempt is not in progress', 409);
}

$questionsStmt = $pdo->prepare('SELECT question_id, prompt, question_type, points, position, is_required, answer_key_json, answer_explanation, settings_json FROM lms_questions WHERE assessment_id=:a AND deleted_at IS NULL ORDER BY position ASC, question_id ASC');
$questionsStmt->execute([':a' => (int)$attempt['assessment_id']]);
$questions = [];
foreach ($questionsStmt->fetchAll() as $q) {
    $questions[(int)$q['question_id']] = $q;
}

$settingsByQuestion = [];
foreach ($questions as $questionId => $question) {
    $settingsByQuestion[$questionId] = $question['settings_json'] === null ? null : (string)$question['settings_json'];
}
$optionsByQuestion = lms_quiz_load_options_by_question($pdo, array_keys($questions), $settingsByQuestion);

$missingRequired = [];
foreach ($questions as $qid => $question) {
    if ((int)($question['is_required'] ?? 0) !== 1) {
        continue;
    }
    if (!array_key_exists((string)$qid, $responses) && !array_key_exists($qid, $responses)) {
        $missingRequired[] = $qid;
        continue;
    }
    $raw = array_key_exists((string)$qid, $responses) ? $responses[(string)$qid] : $responses[$qid];
    if (!lms_quiz_answer_provided($raw)) {
        $missingRequired[] = $qid;
    }
}
if (!empty($missingRequired)) {
    error_log('lms/quiz/attempt/submit.php missing_required attempt_id=' . $attemptId . ' user_id=' . (int)$user['user_id'] . ' question_ids=' . implode(',', $missingRequired));
    lms_error('validation_error', 'Required questions must be answered before submission', 422, ['missing_question_ids' => $missingRequired]);
}

$score = 0.0;
$max = 0.0;
$manual = false;
foreach ($questions as $question) {
    $max += (float)$question['points'];
}

$pdo->beginTransaction();
try {
    $capturedAt = gmdate('c');
    foreach ($questions as $qid => $q) {
        $q = $questions[$qid];
        $pts = (float)$q['points'];
        $resp = null;
        if (array_key_exists((string)$qid, $responses)) {
            $resp = $responses[(string)$qid];
        } elseif (array_key_exists($qid, $responses)) {
            $resp = $responses[$qid];
        }
        $hasAnswer = lms_quiz_answer_provided($resp);
        $needsManual = $hasAnswer && in_array($q['question_type'], ['long_answer', 'file_upload'], true);
        $earned = 0.0;

        if ($hasAnswer && !$needsManual) {
            $answerKey = json_decode((string)$q['answer_key_json'], true);
            $correct = lms_quiz_answer_is_correct((string)$q['question_type'], $answerKey, $resp);
            if ($correct === true) {
                $earned = $pts;
            }
        } elseif ($needsManual) {
            $manual = true;
        }

        $score += $earned;
        $snapshot = lms_quiz_question_snapshot($q, $optionsByQuestion[$qid] ?? [], $capturedAt);
        $pdo->prepare('INSERT INTO lms_assessment_responses (attempt_id,question_id,response_json,question_snapshot_json,score,max_score,needs_manual_grading) VALUES (:a,:q,:r,:snapshot,:s,:m,:n) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json), question_snapshot_json=VALUES(question_snapshot_json), score=VALUES(score), max_score=VALUES(max_score), needs_manual_grading=VALUES(needs_manual_grading), updated_at=CURRENT_TIMESTAMP')->execute([
            ':a' => $attemptId,
            ':q' => $qid,
            ':r' => $hasAnswer ? json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':s' => $earned,
            ':m' => $pts,
            ':n' => $needsManual ? 1 : 0,
        ]);
    }

    $status = $manual ? 'manual_required' : 'auto_graded';
    $grading = $manual ? 'manual_required' : 'auto_graded';
    $pdo->prepare('UPDATE lms_assessment_attempts SET status=:st, grading_status=:g, submitted_at=NOW(), score=:s, max_score=:m WHERE attempt_id=:id')->execute([
        ':st' => $status,
        ':g' => $grading,
        ':s' => $score,
        ':m' => $max,
        ':id' => $attemptId,
    ]);

    $event = [
        'event_name' => $manual ? 'quiz.attempt.graded' : 'quiz.attempt.auto_graded',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('c'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'quiz_attempt',
        'entity_id' => $attemptId,
        'course_id' => (int)$attempt['course_id'],
        'score' => $score,
        'max_score' => $max,
        'grading_status' => $grading,
    ];
    lms_emit_event($pdo, $event['event_name'], $event);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(json_encode([
        'context' => 'lms.quiz.attempt.submit',
        'attempt_id' => $attemptId,
        'user_id' => (int)$user['user_id'],
        'exception_message' => $e->getMessage(),
    ]));
    lms_error('submit_failed', 'Failed to submit attempt', 500);
}

lms_ok(['attempt_id' => $attemptId, 'status' => $status, 'score' => $score, 'max_score' => $max]);
