<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_feature(['quizzes', 'lms_quizzes']);

function lms_normalize_answer_value($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[$k] = lms_normalize_answer_value($v);
        }
        if (array_keys($normalized) === range(0, count($normalized) - 1)) {
            sort($normalized);
        } else {
            ksort($normalized);
        }
        return $normalized;
    }
    return $value;
}

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();
$attemptId = (int)($in['attempt_id'] ?? 0);
$responses = $in['responses'] ?? [];
if ($attemptId <= 0 || !is_array($responses)) {
    lms_error('validation_error', 'attempt_id and responses required', 422);
}

$pdo = db();
$attemptStmt = $pdo->prepare('SELECT attempt_id, assessment_id, course_id, user_id, status FROM lms_assessment_attempts WHERE attempt_id=:id LIMIT 1');
$attemptStmt->execute([':id' => $attemptId]);
$attempt = $attemptStmt->fetch();
if (!$attempt) {
    lms_error('not_found', 'Attempt not found', 404);
}
if ((int)$attempt['user_id'] !== (int)$user['user_id']) {
    lms_error('forbidden', 'Cannot submit another student attempt', 403);
}

// Verify enrollment is still valid (defense-in-depth)
lms_course_access($user, (int)$attempt['course_id']);

if ((string)$attempt['status'] !== 'in_progress') {
    lms_error('conflict', 'Attempt is not in progress', 409);
}

$questionsStmt = $pdo->prepare('SELECT question_id, question_type, points, is_required, answer_key_json FROM lms_questions WHERE assessment_id=:a AND deleted_at IS NULL');
$questionsStmt->execute([':a' => (int)$attempt['assessment_id']]);
$questions = [];
foreach ($questionsStmt->fetchAll() as $q) {
    $questions[(int)$q['question_id']] = $q;
}


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
    $empty = false;
    if (is_string($raw)) {
        $empty = trim($raw) === '';
    } elseif (is_array($raw)) {
        $empty = count(array_filter($raw, static fn($v) => !((is_string($v) && trim($v) === '') || $v === null))) === 0;
    } else {
        $empty = $raw === null;
    }
    if ($empty) {
        $missingRequired[] = $qid;
    }
}
if (!empty($missingRequired)) {
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
    foreach ($responses as $qidRaw => $resp) {
        $qid = (int)$qidRaw;
        if (!isset($questions[$qid])) {
            continue;
        }

        $q = $questions[$qid];
        $pts = (float)$q['points'];
        $needsManual = in_array($q['question_type'], ['long_answer', 'file_upload'], true);
        $earned = 0.0;

        if (!$needsManual) {
            $answerKey = json_decode((string)$q['answer_key_json'], true);
            if (is_scalar($answerKey) && is_scalar($resp) && $answerKey === $resp) {
                $earned = $pts;
            } elseif (is_array($answerKey) && is_array($resp)) {
                if (lms_normalize_answer_value($answerKey) === lms_normalize_answer_value($resp)) {
                    $earned = $pts;
                }
            }
        } else {
            $manual = true;
        }

        $score += $earned;
        $pdo->prepare('INSERT INTO lms_assessment_responses (attempt_id,question_id,response_json,score,max_score,needs_manual_grading) VALUES (:a,:q,:r,:s,:m,:n) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json), score=VALUES(score), max_score=VALUES(max_score), needs_manual_grading=VALUES(needs_manual_grading), updated_at=CURRENT_TIMESTAMP')->execute([
            ':a' => $attemptId,
            ':q' => $qid,
            ':r' => json_encode($resp),
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
    lms_error('submit_failed', 'Failed to submit attempt', 500);
}

lms_ok(['attempt_id' => $attemptId, 'status' => $status, 'score' => $score, 'max_score' => $max]);
