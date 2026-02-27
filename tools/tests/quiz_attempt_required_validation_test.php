<?php
declare(strict_types=1);

function find_missing_required_questions(array $questions, array $responses): array
{
    $missingRequired = [];
    foreach ($questions as $qid => $question) {
        if ((int)($question['is_required'] ?? 0) !== 1) {
            continue;
        }
        if (!array_key_exists((string)$qid, $responses) && !array_key_exists($qid, $responses)) {
            $missingRequired[] = (int)$qid;
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
            $missingRequired[] = (int)$qid;
        }
    }
    return $missingRequired;
}

$questions = [
    101 => ['is_required' => 1],
    102 => ['is_required' => 0],
    103 => ['is_required' => 1],
];

$cases = [
    [
        'name' => 'test_required_question_missing_returns_422',
        'responses' => [102 => 'optional answer', 103 => 'answered'],
        'expected_status' => 422,
        'expected_missing' => [101],
    ],
    [
        'name' => 'test_required_question_answered_succeeds',
        'responses' => [101 => 'non-empty', 103 => ['opt_1']],
        'expected_status' => 200,
        'expected_missing' => [],
    ],
    [
        'name' => 'test_required_empty_variants_return_422',
        'responses' => [101 => '   ', 103 => ['', null]],
        'expected_status' => 422,
        'expected_missing' => [101, 103],
    ],
];

$failed = [];
foreach ($cases as $case) {
    $missing = find_missing_required_questions($questions, $case['responses']);
    $status = empty($missing) ? 200 : 422;

    if ($status !== $case['expected_status']) {
        $failed[] = "{$case['name']}: expected status {$case['expected_status']} got {$status}";
        continue;
    }

    sort($missing);
    $expectedMissing = $case['expected_missing'];
    sort($expectedMissing);
    if ($missing !== $expectedMissing) {
        $failed[] = "{$case['name']}: expected missing [" . implode(',', $expectedMissing) . "] got [" . implode(',', $missing) . "]";
        continue;
    }

    if ($status === 422) {
        $payload = ['error' => 'validation_error', 'details' => ['missing_question_ids' => $missing]];
        if (($payload['details']['missing_question_ids'] ?? []) !== $expectedMissing) {
            $failed[] = "{$case['name']}: 422 payload missing_question_ids mismatch";
        }
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'quiz_attempt required-question validation tests passed' . PHP_EOL;
