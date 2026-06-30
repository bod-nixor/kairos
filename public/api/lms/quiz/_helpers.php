<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/question/_validation.php';

function lms_normalize_question_explanation(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('answer_explanation must be text');
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (strlen($text) > 65535) {
        throw new InvalidArgumentException('answer_explanation must be 65535 bytes or fewer');
    }
    return $text;
}

function lms_quiz_decode_json(mixed $json): mixed
{
    if ($json === null || $json === '') {
        return null;
    }
    $decoded = json_decode((string)$json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function lms_quiz_answer_provided(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $entry) {
            if (lms_quiz_answer_provided($entry)) {
                return true;
            }
        }
        return false;
    }
    if ($value === null) {
        return false;
    }
    if (is_string($value)) {
        return trim($value) !== '';
    }
    return true;
}

function lms_quiz_normalize_answer_value(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $entry) {
            $normalized[$key] = lms_quiz_normalize_answer_value($entry);
        }
        if (array_keys($normalized) === range(0, count($normalized) - 1)) {
            sort($normalized);
        } else {
            ksort($normalized);
        }
        return $normalized;
    }
    if ($value === null) {
        return null;
    }
    return trim((string)$value);
}

function lms_quiz_answer_values(mixed $answer): array
{
    if (!lms_quiz_answer_provided($answer)) {
        return [];
    }
    $values = is_array($answer) ? $answer : [$answer];
    $out = [];
    foreach ($values as $value) {
        if (lms_quiz_answer_provided($value)) {
            $out[] = trim((string)$value);
        }
    }
    return array_values(array_unique($out));
}

function lms_quiz_answer_is_correct(string $questionType, mixed $answerKey, mixed $response): ?bool
{
    $type = $questionType === 'multi_select' ? 'multiple_select' : $questionType;
    if (!lms_quiz_answer_provided($response) || $answerKey === null) {
        return null;
    }

    if ($type === 'mcq') {
        return is_scalar($answerKey) && is_scalar($response) && trim((string)$answerKey) === trim((string)$response);
    }
    if ($type === 'multiple_select') {
        if (!is_array($answerKey) || !is_array($response)) {
            return false;
        }
        return lms_quiz_normalize_answer_value($answerKey) === lms_quiz_normalize_answer_value($response);
    }
    if ($type === 'true_false') {
        return is_scalar($answerKey) && is_scalar($response) && strtolower(trim((string)$answerKey)) === strtolower(trim((string)$response));
    }

    return null;
}

function lms_quiz_options_from_settings_json(?string $settingsJson): array
{
    $settings = lms_quiz_decode_json($settingsJson);
    if (!is_array($settings) || !isset($settings['options']) || !is_array($settings['options'])) {
        return [];
    }

    try {
        return lms_normalize_question_options($settings['options']);
    } catch (InvalidArgumentException $e) {
        return [];
    }
}

function lms_quiz_load_options_by_question(PDO $pdo, array $questionIds, array $settingsByQuestion = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $questionIds), static fn(int $id): bool => $id > 0)));
    $optionsByQuestion = [];
    foreach ($ids as $questionId) {
        $optionsByQuestion[$questionId] = [];
    }
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT question_id, option_text, option_value, position FROM lms_question_options WHERE question_id IN ($placeholders) ORDER BY question_id ASC, position ASC, option_id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $questionId = (int)$option['question_id'];
            $optionsByQuestion[$questionId][] = [
                'value' => (string)($option['option_value'] ?? ''),
                'text' => (string)($option['option_text'] ?? ''),
            ];
        }
    }

    foreach ($ids as $questionId) {
        if ($optionsByQuestion[$questionId] !== []) {
            continue;
        }
        $optionsByQuestion[$questionId] = lms_quiz_options_from_settings_json($settingsByQuestion[$questionId] ?? null);
    }

    return $optionsByQuestion;
}

function lms_quiz_question_snapshot(array $question, array $options, ?string $capturedAt = null): array
{
    $questionType = (string)($question['question_type'] ?? 'mcq');
    try {
        $questionType = lms_normalize_question_type($questionType);
    } catch (InvalidArgumentException $e) {
        $questionType = $questionType === 'multi_select' ? 'multiple_select' : $questionType;
    }

    $answerKey = array_key_exists('answer_key', $question)
        ? $question['answer_key']
        : lms_quiz_decode_json($question['answer_key_json'] ?? null);
    $explanation = $question['answer_explanation'] ?? null;
    if ($explanation !== null) {
        $explanation = trim((string)$explanation);
        if ($explanation === '') {
            $explanation = null;
        }
    }

    return [
        'question_id' => (int)($question['question_id'] ?? 0),
        'prompt' => (string)($question['prompt'] ?? ''),
        'question_type' => $questionType,
        'points' => (float)($question['points'] ?? 0),
        'position' => (int)($question['position'] ?? 0),
        'is_required' => (int)($question['is_required'] ?? 0),
        'options' => array_values($options),
        'answer_key' => $answerKey,
        'answer_explanation' => $explanation,
        'captured_at' => $capturedAt ?? gmdate('c'),
    ];
}

function lms_quiz_answer_text(mixed $answer, array $options, string $questionType): mixed
{
    if (!lms_quiz_answer_provided($answer)) {
        return null;
    }

    $optionTextByValue = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $value = trim((string)($option['value'] ?? $option['option_value'] ?? ''));
        $text = trim((string)($option['text'] ?? $option['label'] ?? $option['option_text'] ?? $value));
        if ($value !== '') {
            $optionTextByValue[$value] = $text !== '' ? $text : $value;
        }
    }

    $format = static function (mixed $value) use ($optionTextByValue, $questionType): string {
        $raw = trim((string)$value);
        if ($questionType === 'true_false') {
            return strtolower($raw) === 'true' ? 'True' : (strtolower($raw) === 'false' ? 'False' : $raw);
        }
        return $optionTextByValue[$raw] ?? $raw;
    };

    if (is_array($answer)) {
        return array_map($format, lms_quiz_answer_values($answer));
    }
    return $format($answer);
}

function lms_quiz_review_options(string $questionType, array $options, mixed $selectedAnswer, mixed $correctAnswer): array
{
    $type = $questionType === 'multi_select' ? 'multiple_select' : $questionType;
    if ($type === 'true_false' && $options === []) {
        $options = [
            ['value' => 'true', 'text' => 'True'],
            ['value' => 'false', 'text' => 'False'],
        ];
    }
    if (!in_array($type, ['mcq', 'multiple_select', 'true_false'], true)) {
        return [];
    }

    $selected = array_flip(lms_quiz_answer_values($selectedAnswer));
    $correct = array_flip(lms_quiz_answer_values($correctAnswer));
    $rows = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $value = trim((string)($option['value'] ?? $option['option_value'] ?? ''));
        $text = trim((string)($option['text'] ?? $option['label'] ?? $option['option_text'] ?? $value));
        if ($value === '' && $text === '') {
            continue;
        }
        $rows[] = [
            'value' => $value,
            'text' => $text !== '' ? $text : $value,
            'is_selected' => isset($selected[$value]),
            'is_correct' => isset($correct[$value]),
        ];
    }
    return $rows;
}

function lms_require_published_assessment(int $assessmentId, array $user): array
{
    if ($assessmentId <= 0) {
        lms_error('validation_error', 'assessment_id required', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT assessment_id, course_id, section_id, title, instructions, status, max_attempts, time_limit_minutes, available_from, due_at FROM lms_assessments WHERE assessment_id=:id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $assessmentId]);
    $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assessment) {
        lms_error('not_found', 'Quiz not found', 404);
    }

    lms_course_access($user, (int)$assessment['course_id']);
    $role = lms_user_role($user);
    if (!lms_is_staff_role($role) && (string)$assessment['status'] !== 'published') {
        lms_error('forbidden', 'Quiz is not published', 403);
    }

    return $assessment;
}
