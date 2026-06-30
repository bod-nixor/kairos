<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_helpers.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    lms_error('validation_error', 'attempt_id required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT a.attempt_id, a.assessment_id, a.user_id AS student_user_id, a.status, a.score, a.max_score, a.started_at, a.submitted_at, a.grading_status, q.course_id, q.title AS quiz_title
  FROM lms_assessment_attempts a
  JOIN lms_assessments q ON q.assessment_id = a.assessment_id AND q.deleted_at IS NULL
  WHERE a.attempt_id = :attempt_id LIMIT 1');
$stmt->execute([':attempt_id' => $attemptId]);
$attempt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attempt) {
    lms_error('not_found', 'Attempt not found', 404);
}

$courseId = (int)$attempt['course_id'];
$userId = (int)$user['user_id'];
$studentUserId = (int)$attempt['student_user_id'];
$access = rbac_course_access_context($pdo, $user, $courseId);
$canGrade = rbac_can($pdo, $user, 'grade_course', $courseId);
$isOwnerWithStudentAccess = $studentUserId === $userId
    && (bool)($access['view_course'] ?? false)
    && (bool)($access['participate_as_student'] ?? false);
if (!$isOwnerWithStudentAccess && !$canGrade) {
    lms_error('forbidden', 'Attempt review access is required.', 403);
}

$isCompleted = (string)$attempt['status'] !== 'in_progress' && $attempt['submitted_at'] !== null;
if (!$isCompleted) {
    lms_error('conflict', 'Attempt review is available after submission.', 409);
}

$respStmt = $pdo->prepare('SELECT response_id, question_id, response_json, question_snapshot_json, score, score AS auto_score, max_score, needs_manual_grading, graded_at, feedback
 FROM lms_assessment_responses
 WHERE attempt_id = :attempt_id
 ORDER BY response_id ASC');
$respStmt->execute([':attempt_id' => $attemptId]);
$responses = $respStmt->fetchAll(PDO::FETCH_ASSOC);

$currentQuestionStmt = $pdo->prepare('SELECT question_id, prompt, question_type, points, position, is_required, answer_key_json, answer_explanation, settings_json FROM lms_questions WHERE assessment_id=:assessment_id ORDER BY position ASC, question_id ASC');
$currentQuestionStmt->execute([':assessment_id' => (int)$attempt['assessment_id']]);
$currentQuestions = [];
$settingsByQuestion = [];
foreach ($currentQuestionStmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
    $questionId = (int)$question['question_id'];
    $currentQuestions[$questionId] = $question;
    $settingsByQuestion[$questionId] = $question['settings_json'] === null ? null : (string)$question['settings_json'];
}
$optionsByQuestion = lms_quiz_load_options_by_question($pdo, array_keys($currentQuestions), $settingsByQuestion);

$items = [];
foreach ($responses as $response) {
    $questionId = (int)$response['question_id'];
    $snapshot = lms_quiz_decode_json($response['question_snapshot_json'] ?? null);
    $snapshotSource = 'snapshot';
    if (!is_array($snapshot) || (int)($snapshot['question_id'] ?? 0) <= 0) {
        $currentQuestion = $currentQuestions[$questionId] ?? null;
        if ($currentQuestion) {
            $snapshot = lms_quiz_question_snapshot($currentQuestion, $optionsByQuestion[$questionId] ?? []);
            $snapshotSource = 'current_question';
        } else {
            $snapshot = [
                'question_id' => $questionId,
                'prompt' => 'Question ' . $questionId,
                'question_type' => 'mcq',
                'points' => (float)($response['max_score'] ?? 0),
                'position' => 0,
                'is_required' => 0,
                'options' => [],
                'answer_key' => null,
                'answer_explanation' => null,
            ];
            $snapshotSource = 'missing_question';
        }
    }

    $questionType = (string)($snapshot['question_type'] ?? 'mcq');
    $options = isset($snapshot['options']) && is_array($snapshot['options']) ? $snapshot['options'] : [];
    $selectedAnswer = lms_quiz_decode_json($response['response_json'] ?? null);
    $correctAnswer = $snapshot['answer_key'] ?? null;
    $isAnswered = lms_quiz_answer_provided($selectedAnswer);
    $isCorrect = $isAnswered ? lms_quiz_answer_is_correct($questionType, $correctAnswer, $selectedAnswer) : null;
    $earnedScore = $response['score'] === null ? null : (float)$response['score'];
    $maxScore = $response['max_score'] === null ? null : (float)$response['max_score'];

    $items[] = [
        'response_id' => (int)$response['response_id'],
        'question_id' => $questionId,
        'position' => (int)($snapshot['position'] ?? 0),
        'prompt' => (string)($snapshot['prompt'] ?? ''),
        'question_text' => (string)($snapshot['prompt'] ?? ''),
        'question_type' => $questionType === 'multi_select' ? 'multiple_select' : $questionType,
        'points' => (float)($snapshot['points'] ?? ($maxScore ?? 0)),
        'is_required' => (int)($snapshot['is_required'] ?? 0),
        'selected_answer' => $selectedAnswer,
        'selected_answer_text' => lms_quiz_answer_text($selectedAnswer, $options, $questionType),
        'is_answered' => $isAnswered,
        'is_correct' => $isCorrect,
        'correct' => $isCorrect,
        'correct_answer' => $correctAnswer,
        'correct_answer_text' => lms_quiz_answer_text($correctAnswer, $options, $questionType),
        'answer_explanation' => $snapshot['answer_explanation'] ?? null,
        'explanation' => $snapshot['answer_explanation'] ?? null,
        'options' => lms_quiz_review_options($questionType, $options, $selectedAnswer, $correctAnswer),
        'score' => $earnedScore,
        'earned_score' => $earnedScore,
        'max_score' => $maxScore,
        'needs_manual_grading' => (int)($response['needs_manual_grading'] ?? 0),
        'graded_at' => $response['graded_at'],
        'feedback' => $response['feedback'],
        'snapshot_source' => $snapshotSource,
    ];
}

usort($items, static function (array $a, array $b): int {
    $position = ((int)$a['position']) <=> ((int)$b['position']);
    return $position !== 0 ? $position : ((int)$a['question_id'] <=> (int)$b['question_id']);
});

$score = $attempt['score'] === null ? null : (float)$attempt['score'];
$maxScore = $attempt['max_score'] === null ? null : (float)$attempt['max_score'];
$attempt['score'] = $score;
$attempt['max_score'] = $maxScore;
$attempt['score_pct'] = ($score !== null && $maxScore !== null && $maxScore > 0)
    ? (int)round(($score / $maxScore) * 100)
    : null;
$attempt['total_questions'] = count($items);

lms_ok([
    'attempt' => $attempt,
    'items' => $items,
    'questions' => $items,
    'responses' => $responses,
    'review_available' => true,
]);
