<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_helpers.php';

lms_require_feature(['quiz', 'quizzes', 'lms_quizzes']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['question_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'question_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT q.question_id, q.assessment_id, q.prompt, q.question_type, q.points, q.position, q.is_required, q.answer_key_json, q.settings_json, a.course_id FROM lms_questions q JOIN lms_assessments a ON a.assessment_id = q.assessment_id WHERE q.question_id=:id AND q.deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    lms_error('not_found', 'Question not found', 404);
}
$existingOptionsStmt = $pdo->prepare('SELECT option_text, option_value FROM lms_question_options WHERE question_id = :id ORDER BY position ASC, option_id ASC');
$existingOptionsStmt->execute([':id' => $id]);
$existingOptions = array_map(
    static fn(array $option): array => [
        'text' => (string)$option['option_text'],
        'value' => (string)$option['option_value'],
    ],
    $existingOptionsStmt->fetchAll(PDO::FETCH_ASSOC)
);

lms_require_course_capability($user, 'manage_course', (int)$existing['course_id']);

$prompt = array_key_exists('prompt', $in) ? trim((string)$in['prompt']) : (string)$existing['prompt'];
$questionType = array_key_exists('question_type', $in)
    ? trim((string)$in['question_type'])
    : trim((string)$existing['question_type']);

$points = array_key_exists('points', $in) ? (float)$in['points'] : (float)$existing['points'];
$position = array_key_exists('position', $in) ? max(1, (int)$in['position']) : (int)$existing['position'];
$isRequired = array_key_exists('is_required', $in) ? (!empty($in['is_required']) ? 1 : 0) : (int)$existing['is_required'];

$answerKeyJson = array_key_exists('answer_key', $in)
    ? json_encode($in['answer_key'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : $existing['answer_key_json'];

$settings = json_decode((string)$existing['settings_json'], true) ?: [];
if (!is_array($settings)) {
    $settings = [];
}
if (array_key_exists('settings', $in) && is_array($in['settings'])) {
    foreach ($in['settings'] as $key => $val) {
        $settings[$key] = $val;
    }
}

if (array_key_exists('options', $in) && is_array($in['options'])) {
    $options = $in['options'];
} elseif (array_key_exists('settings', $in) && is_array($in['settings']) && array_key_exists('options', $in['settings']) && is_array($in['settings']['options'])) {
    $options = $in['settings']['options'];
} elseif ($existingOptions !== []) {
    $options = $existingOptions;
} else {
    $options = (isset($settings['options']) && is_array($settings['options'])) ? $settings['options'] : [];
}
$settings['options'] = $options;

if ($prompt === '' || $questionType === '') {
    lms_error('validation_error', 'prompt and question_type must be non-empty', 422);
}
$definitionFields = ['prompt', 'question_type', 'points', 'options', 'settings', 'answer_key'];
$validateDefinition = array_intersect($definitionFields, array_keys($in)) !== [];
if ($validateDefinition) {
    try {
        $definition = lms_validate_question_definition(
            $questionType,
            $points,
            $options,
            $answerKeyJson === null ? null : json_decode((string)$answerKeyJson, true)
        );
    } catch (InvalidArgumentException $e) {
        lms_error('validation_error', $e->getMessage(), 422);
    }
    $questionType = $definition['question_type'];
    $points = $definition['points'];
    $options = $definition['options'];
    $answerKeyJson = $definition['answer_key'] === null
        ? null
        : json_encode($definition['answer_key'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    try {
        $questionType = lms_normalize_question_type($questionType);
    } catch (InvalidArgumentException $e) {
        lms_error('validation_error', $e->getMessage(), 422);
    }
}
$settings['options'] = $options;
$settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (array_key_exists('answer_key', $in)) {
    $newAnswer = json_decode((string)$answerKeyJson, true);
    $oldAnswer = json_decode((string)$existing['answer_key_json'], true);
    if ($newAnswer !== $oldAnswer) {
        $attemptCountStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:assessment_id AND submitted_at IS NOT NULL');
        $attemptCountStmt->execute([':assessment_id' => (int)$existing['assessment_id']]);
        if ((int)$attemptCountStmt->fetchColumn() > 0) {
            lms_error('conflict', 'Cannot change answer key after submitted attempts exist', 409);
        }
    }
}

$pdo->beginTransaction();
try {
    $updateStmt = $pdo->prepare('UPDATE lms_questions SET prompt=:p, question_type=:t, points=:pts, position=:position, is_required=:is_required, answer_key_json=:ans, settings_json=:set, updated_at=CURRENT_TIMESTAMP WHERE question_id=:id AND deleted_at IS NULL');
    $updateStmt->execute([
        ':p' => $prompt,
        ':t' => $questionType,
        ':pts' => $points,
        ':position' => $position,
        ':is_required' => $isRequired,
        ':ans' => $answerKeyJson,
        ':set' => $settingsJson,
        ':id' => $id,
    ]);


    $pdo->prepare('DELETE FROM lms_question_options WHERE question_id = :question_id')->execute([':question_id' => $id]);
    if (!empty($options)) {
        $optStmt = $pdo->prepare('INSERT INTO lms_question_options (question_id, option_text, option_value, position, is_correct) VALUES (:question_id, :option_text, :option_value, :position, 0)');
        $idx = 1;
        foreach ($options as $opt) {
            if (is_array($opt)) {
                $text = trim((string)($opt['text'] ?? $opt['label'] ?? $opt['value'] ?? ''));
                $value = trim((string)($opt['value'] ?? ''));
            } else {
                $text = trim((string)$opt);
                $value = '';
            }
            if ($text === '') {
                continue;
            }
            if ($value === '') {
                $value = 'opt_' . $idx;
            }
            $optStmt->execute([
                ':question_id' => $id,
                ':option_text' => $text,
                ':option_value' => $value,
                ':position' => $idx,
            ]);
            $idx++;
        }
    }

    $pdo->commit();
    lms_ok(['updated' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('question_update_failed', 'Failed to update question', 500);
}
