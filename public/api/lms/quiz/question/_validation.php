<?php
declare(strict_types=1);

function lms_normalize_question_type(string $value): string
{
    $type = strtolower(trim($value));
    if ($type === 'multi_select') {
        $type = 'multiple_select';
    }
    $allowed = ['mcq', 'multiple_select', 'true_false', 'short_answer', 'long_answer', 'file_upload'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('question_type is invalid');
    }
    return $type;
}

function lms_normalize_question_options(array $options): array
{
    $normalized = [];
    $values = [];
    foreach ($options as $option) {
        if (is_array($option)) {
            $text = trim((string)($option['text'] ?? $option['label'] ?? $option['value'] ?? ''));
            $value = trim((string)($option['value'] ?? ''));
        } else {
            $text = trim((string)$option);
            $value = '';
        }
        if ($text === '') {
            continue;
        }
        if ($value === '') {
            $value = 'opt_' . (count($normalized) + 1);
        }
        if (isset($values[$value])) {
            throw new InvalidArgumentException('Question option values must be unique');
        }
        $values[$value] = true;
        $normalized[] = ['value' => $value, 'text' => $text];
    }
    return $normalized;
}

function lms_validate_question_definition(string $type, float $points, array $options, mixed $answerKey): array
{
    $normalizedType = lms_normalize_question_type($type);
    if (!is_finite($points) || $points <= 0 || $points > 10000) {
        throw new InvalidArgumentException('points must be greater than zero and no more than 10000');
    }
    $normalizedOptions = lms_normalize_question_options($options);
    $optionValues = array_column($normalizedOptions, 'value');

    if (in_array($normalizedType, ['mcq', 'multiple_select'], true) && count($normalizedOptions) < 2) {
        throw new InvalidArgumentException('Choice questions require at least two options');
    }
    if ($normalizedType === 'mcq') {
        if (!is_string($answerKey) || !in_array($answerKey, $optionValues, true)) {
            throw new InvalidArgumentException('Multiple-choice questions require one valid answer');
        }
    } elseif ($normalizedType === 'multiple_select') {
        if (!is_array($answerKey) || $answerKey === []) {
            throw new InvalidArgumentException('Multiple-select questions require at least one valid answer');
        }
        $answerKey = array_values(array_unique(array_map('strval', $answerKey)));
        foreach ($answerKey as $value) {
            if (!in_array($value, $optionValues, true)) {
                throw new InvalidArgumentException('Multiple-select answers must match the available options');
            }
        }
    } elseif ($normalizedType === 'true_false') {
        $answerKey = strtolower(trim((string)$answerKey));
        if (!in_array($answerKey, ['true', 'false'], true)) {
            throw new InvalidArgumentException('True/false questions require true or false as the answer');
        }
    } elseif (in_array($normalizedType, ['short_answer', 'long_answer', 'file_upload'], true)) {
        $answerKey = null;
    }

    return [
        'question_type' => $normalizedType,
        'points' => $points,
        'options' => $normalizedOptions,
        'answer_key' => $answerKey,
    ];
}
