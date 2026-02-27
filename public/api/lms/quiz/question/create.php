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

$allowedQuestionTypes = ['mcq', 'multi_select', 'multiple_select', 'true_false', 'short_answer', 'long_answer', 'file_upload'];
if ($questionType === 'multiple_select') {
    $questionType = 'multi_select';
}
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
$isRequired = !empty($in['is_required']) ? 1 : 0;
$answerKey = $in['answer_key'] ?? $in['correct_answer'] ?? null;
$settings = $in['settings'] ?? [];
$options = [];

if (array_key_exists('options', $in) && is_array($in['options'])) {
    $options = $in['options'];
} elseif (is_array($settings) && isset($settings['options']) && is_array($settings['options'])) {
    $options = $settings['options'];
}

if (!is_array($settings)) {
    $settings = [];
}
$settings['options'] = $options;

$answerKeyJson = $answerKey === null
    ? null
    : json_encode($answerKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$settingsJson = empty($settings)
    ? null
    : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$pdo->beginTransaction();
try {
    $position = isset($in['position']) ? (int)$in['position'] : 0;
    if ($position <= 0) {
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM lms_questions WHERE assessment_id = :assessment_id AND deleted_at IS NULL FOR UPDATE');
        $posStmt->execute([':assessment_id' => $assessmentId]);
        $position = (int)$posStmt->fetchColumn();
    }

    $insertStmt = $pdo->prepare('INSERT INTO lms_questions (assessment_id, prompt, question_type, points, position, is_required, answer_key_json, settings_json)
        VALUES (:assessment_id, :prompt, :question_type, :points, :position, :is_required, :answer_key_json, :settings_json)');
    $insertStmt->execute([
        ':assessment_id' => $assessmentId,
        ':prompt' => $prompt,
        ':question_type' => $questionType,
        ':points' => $points,
        ':position' => $position,
        ':is_required' => $isRequired,
        ':answer_key_json' => $answerKeyJson,
        ':settings_json' => $settingsJson,
    ]);

    $questionId = (int)$pdo->lastInsertId();
    if (!empty($options)) {
        $optStmt = $pdo->prepare('INSERT INTO lms_question_options (question_id, option_text, option_value, position, is_correct) VALUES (:question_id, :option_text, :option_value, :position, 0)');
        $idx = 1;
        foreach ($options as $opt) {
            $text = trim((string)($opt['text'] ?? $opt['label'] ?? $opt['value'] ?? ''));
            if ($text === '') {
                continue;
            }
            $value = trim((string)($opt['value'] ?? ('opt_' . $idx)));
            $optStmt->execute([
                ':question_id' => $questionId,
                ':option_text' => $text,
                ':option_value' => $value,
                ':position' => $idx,
            ]);
            $idx++;
        }
    }

    $pdo->commit();
    lms_ok(['question_id' => $questionId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('question_create_failed', 'Failed to create question', 500);
}
