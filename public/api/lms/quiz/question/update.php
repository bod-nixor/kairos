<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_common.php';

lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['question_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'question_id required', 422);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT question_id, assessment_id, prompt, question_type, points, answer_key_json, settings_json FROM lms_questions WHERE question_id=:id AND deleted_at IS NULL LIMIT 1');
$existingStmt->execute([':id' => $id]);
$existing = $existingStmt->fetch();
if (!$existing) {
    lms_error('not_found', 'Question not found', 404);
}

$prompt = array_key_exists('prompt', $in) ? trim((string)$in['prompt']) : (string)$existing['prompt'];
$questionType = array_key_exists('question_type', $in) ? (string)$in['question_type'] : (string)$existing['question_type'];
$points = array_key_exists('points', $in) ? (float)$in['points'] : (float)$existing['points'];
$answerKeyJson = array_key_exists('answer_key', $in)
    ? json_encode($in['answer_key'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : $existing['answer_key_json'];
$settingsJson = array_key_exists('settings', $in)
    ? json_encode($in['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : $existing['settings_json'];

if ($prompt === '' || $questionType === '') {
    lms_error('validation_error', 'prompt and question_type must be non-empty', 422);
}

if (array_key_exists('answer_key', $in) && $answerKeyJson !== $existing['answer_key_json']) {
    $attemptCountStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_assessment_attempts WHERE assessment_id=:assessment_id AND submitted_at IS NOT NULL');
    $attemptCountStmt->execute([':assessment_id' => (int)$existing['assessment_id']]);
    if ((int)$attemptCountStmt->fetchColumn() > 0) {
        lms_error('conflict', 'Cannot change answer key after submitted attempts exist', 409);
    }
}

$pdo->prepare('UPDATE lms_questions SET prompt=:p, question_type=:t, points=:pts, answer_key_json=:ans, settings_json=:set, updated_at=CURRENT_TIMESTAMP WHERE question_id=:id')->execute([
    ':p' => $prompt,
    ':t' => $questionType,
    ':pts' => $points,
    ':ans' => $answerKeyJson,
    ':set' => $settingsJson,
    ':id' => $id,
]);

lms_ok(['updated' => true]);
