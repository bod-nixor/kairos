<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$assessmentId = (int)($in['assessment_id'] ?? 0);
$prompt = trim((string)($in['prompt'] ?? ''));
$questionType = trim((string)($in['question_type'] ?? $in['type'] ?? ''));

if ($assessmentId <= 0 || $prompt === '' || $questionType === '') {
    lms_error('validation_error', 'assessment_id, prompt, question_type required', 422);
}

$allowedQuestionTypes = ['mcq', 'multi_select', 'true_false', 'short_answer', 'long_answer', 'file_upload'];
if (!in_array($questionType, $allowedQuestionTypes, true)) {
    lms_error('validation_error', 'question_type is invalid', 422);
}

$pdo = db();
$assessmentStmt = $pdo->prepare('SELECT assessment_id, course_id FROM lms_assessments WHERE assessment_id = :assessment_id AND deleted_at IS NULL LIMIT 1');
$assessmentStmt->execute([':assessment_id' => $assessmentId]);
$assessment = $assessmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$assessment) {
    lms_error('not_found', 'Quiz not found', 404);
}

lms_course_access($user, (int)$assessment['course_id']);

$points = isset($in['points']) && is_numeric($in['points']) ? (float)$in['points'] : 1.0;
$position = isset($in['position']) ? (int)$in['position'] : 0;
$answerKey = $in['answer_key'] ?? $in['correct_answer'] ?? null;
$settings = $in['settings'] ?? [];

if (array_key_exists('options', $in) && is_array($in['options'])) {
    if (!is_array($settings)) {
        $settings = [];
    }
    $settings['options'] = $in['options'];
}

$answerKeyJson = $answerKey === null
    ? null
    : json_encode($answerKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$settingsJson = empty($settings)
    ? null
    : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$insertStmt = $pdo->prepare('INSERT INTO lms_questions (assessment_id, prompt, question_type, points, position, answer_key_json, settings_json)
    VALUES (:assessment_id, :prompt, :question_type, :points, :position, :answer_key_json, :settings_json)');
$insertStmt->execute([
    ':assessment_id' => $assessmentId,
    ':prompt' => $prompt,
    ':question_type' => $questionType,
    ':points' => $points,
    ':position' => $position,
    ':answer_key_json' => $answerKeyJson,
    ':settings_json' => $settingsJson,
]);

lms_ok(['question_id' => (int)$pdo->lastInsertId()]);
