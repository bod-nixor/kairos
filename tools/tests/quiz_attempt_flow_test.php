<?php
declare(strict_types=1);

/**
 * Regression tests for quiz attempt participation and response payload behavior.
 * These mirror the endpoint contract without requiring a live database.
 */

function simulate_quiz_attempt_start(array $user, array $courseContext, array $quiz, int $attemptCount): array
{
    $role = strtolower((string)($user['role_name'] ?? ''));
    if (!in_array($role, ['student', 'ta', 'manager', 'admin'], true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (empty($courseContext['participate_as_student'])) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (($quiz['status'] ?? '') !== 'published') {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    $maxAttempts = (int)($quiz['max_attempts'] ?? 0);
    if ($maxAttempts > 0 && $attemptCount >= $maxAttempts) {
        return ['status' => 409, 'error' => 'attempt_limit'];
    }
    return ['status' => 200, 'attempt_id' => 1234];
}

function response_has_required_answers(array $questions, array $responses): bool
{
    foreach ($questions as $qid => $question) {
        if ((int)($question['is_required'] ?? 0) !== 1) {
            continue;
        }
        if (!array_key_exists((string)$qid, $responses) && !array_key_exists($qid, $responses)) {
            return false;
        }
        $raw = array_key_exists((string)$qid, $responses) ? $responses[(string)$qid] : $responses[$qid];
        if (is_array($raw)) {
            $nonEmpty = array_filter($raw, static fn($value): bool => $value !== null && trim((string)$value) !== '');
            if ($nonEmpty === []) {
                return false;
            }
        } elseif ($raw === null || trim((string)$raw) === '') {
            return false;
        }
    }
    return true;
}

function simulate_quiz_submit(array $user, array $courseContext, array $attempt, array $questions, array $responses): array
{
    $role = strtolower((string)($user['role_name'] ?? ''));
    if (!in_array($role, ['student', 'ta', 'manager', 'admin'], true)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if ((int)($attempt['user_id'] ?? 0) !== (int)($user['user_id'] ?? 0)) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (empty($courseContext['participate_as_student'])) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return ['status' => 409, 'error' => 'conflict'];
    }
    if (!response_has_required_answers($questions, $responses)) {
        return ['status' => 422, 'error' => 'validation_error'];
    }
    $storedResponses = [];
    foreach ($questions as $qid => $_question) {
        $storedResponses[(string)$qid] = array_key_exists((string)$qid, $responses) ? $responses[(string)$qid] : ($responses[$qid] ?? null);
    }
    return ['status' => 200, 'responses' => $storedResponses];
}

$student = ['user_id' => 10, 'role_name' => 'student'];
$managerStudent = ['user_id' => 11, 'role_name' => 'manager'];
$guest = ['user_id' => 12, 'role_name' => 'viewer'];
$participant = ['participate_as_student' => true];
$staffPreviewOnly = ['participate_as_student' => false, 'grade_course' => true];
$quiz = ['status' => 'published', 'max_attempts' => 2];
$questions = [
    101 => ['is_required' => 1, 'answer_key' => 'opt_2'],
    102 => ['is_required' => 0, 'answer_key' => null],
];

$cases = [
    [
        'name' => 'student can start a published quiz',
        'actual' => simulate_quiz_attempt_start($student, $participant, $quiz, 0),
        'expected_status' => 200,
    ],
    [
        'name' => 'staff preview without student participation does not create an attempt',
        'actual' => simulate_quiz_attempt_start(['user_id' => 20, 'role_name' => 'admin'], $staffPreviewOnly, $quiz, 0),
        'expected_status' => 403,
    ],
    [
        'name' => 'dual-role manager can explicitly take as a student',
        'actual' => simulate_quiz_attempt_start($managerStudent, $participant, $quiz, 0),
        'expected_status' => 200,
    ],
    [
        'name' => 'unauthorized role cannot start a quiz',
        'actual' => simulate_quiz_attempt_start($guest, $participant, $quiz, 0),
        'expected_status' => 403,
    ],
    [
        'name' => 'student selected MCQ option is submitted as backend option value',
        'actual' => simulate_quiz_submit($student, $participant, ['user_id' => 10, 'status' => 'in_progress'], $questions, ['101' => 'opt_2']),
        'expected_status' => 200,
        'expected_response' => ['101' => 'opt_2', '102' => null],
    ],
    [
        'name' => 'required unanswered item is rejected before grading',
        'actual' => simulate_quiz_submit($student, $participant, ['user_id' => 10, 'status' => 'in_progress'], $questions, []),
        'expected_status' => 422,
    ],
    [
        'name' => 'user cannot submit another student attempt',
        'actual' => simulate_quiz_submit($student, $participant, ['user_id' => 99, 'status' => 'in_progress'], $questions, ['101' => 'opt_2']),
        'expected_status' => 403,
    ],
];

$failed = [];
foreach ($cases as $case) {
    if (($case['actual']['status'] ?? null) !== $case['expected_status']) {
        $failed[] = "{$case['name']}: expected {$case['expected_status']} got " . ($case['actual']['status'] ?? 'null');
        continue;
    }
    if (isset($case['expected_response']) && (($case['actual']['responses'] ?? null) !== $case['expected_response'])) {
        $failed[] = "{$case['name']}: response payload mismatch";
    }
}

$root = dirname(__DIR__, 2);
$submitSource = (string)file_get_contents($root . '/public/api/lms/quiz/attempt/submit.php');
$attemptSource = (string)file_get_contents($root . '/public/api/lms/quiz/attempt.php');

$submitAllowsCourseRoles = preg_match(
    "/lms_require_roles\\s*\\(\\s*\\[[^\\]]*'student'[^\\]]*'ta'[^\\]]*'manager'[^\\]]*'admin'[^\\]]*\\]/s",
    $submitSource
) === 1;
if (!$submitAllowsCourseRoles) {
    $failed[] = 'submit endpoint must allow dual-role student participants through the shared role gate';
}

$submitRequiresStudentParticipation = preg_match(
    '/lms_course_access\\s*\\([^;]*false\\s*\\)\\s*;/s',
    $submitSource
) === 1;
if (!$submitRequiresStudentParticipation) {
    $failed[] = 'submit endpoint must require student participation for the attempt course';
}

if (!str_contains($submitSource, 'lms_quiz_answer_is_correct')) {
    $failed[] = 'submit endpoint must compare submitted option values through the shared quiz answer helper';
}

if (!str_contains($submitSource, 'foreach ($questions as $qid => $q)') || !str_contains($submitSource, 'question_snapshot_json')) {
    $failed[] = 'submit endpoint must write submitted review rows and question snapshots for every quiz question';
}

$startRequiresStudentParticipation =
    str_contains($attemptSource, 'lms_quiz_student_attempt_decision')
    || preg_match('/lms_course_access\\s*\\([^;]*false\\s*\\)\\s*;/s', $attemptSource) === 1;
if (!$startRequiresStudentParticipation) {
    $failed[] = 'start endpoint must require student participation for durable attempts';
}

if ($failed !== []) {
    fwrite(STDERR, "quiz_attempt_flow_test FAILED:\n" . implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'quiz attempt flow tests passed' . PHP_EOL;
