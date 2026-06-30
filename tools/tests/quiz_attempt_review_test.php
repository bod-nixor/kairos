<?php
declare(strict_types=1);

putenv('ALLOWED_DOMAIN=nixorcollege.edu.pk');

require_once dirname(__DIR__, 2) . '/public/api/lms/quiz/_helpers.php';

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

function simulate_quiz_attempt_review_access(array $user, array $attempt, array $access): array
{
    $isOwnerWithStudentAccess = (int)($attempt['student_user_id'] ?? 0) === (int)($user['user_id'] ?? 0)
        && !empty($access['view_course'])
        && !empty($access['participate_as_student']);
    $canGrade = !empty($access['grade_course']);
    if (!$isOwnerWithStudentAccess && !$canGrade) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if (($attempt['status'] ?? '') === 'in_progress' || empty($attempt['submitted_at'])) {
        return ['status' => 409, 'error' => 'conflict'];
    }
    return ['status' => 200, 'error' => null];
}

$options = [
    ['value' => 'opt_1', 'text' => 'Alpha'],
    ['value' => 'opt_2', 'text' => 'Beta'],
];

$assert(lms_normalize_question_explanation('  Because Beta is the exact match.  ') === 'Because Beta is the exact match.', 'explanation should trim and persist');
$assert(lms_normalize_question_explanation('   ') === null, 'blank explanation should remain optional/null');
try {
    lms_normalize_question_explanation(['not text']);
    $failed[] = 'non-text explanation should fail validation';
} catch (InvalidArgumentException $e) {
    $assert(str_contains($e->getMessage(), 'answer_explanation'), 'non-text explanation should return a stable validation message');
}

$assert(lms_quiz_answer_is_correct('mcq', 'opt_2', 'opt_2') === true, 'matching MCQ option value should score correct');
$assert(lms_quiz_answer_is_correct('mcq', 'opt_2', 'opt_1') === false, 'wrong MCQ option value should score incorrect');
$assert(lms_quiz_answer_is_correct('multiple_select', ['opt_1', 'opt_2'], ['opt_2', 'opt_1']) === true, 'multiple-select comparison should be order-insensitive');
$assert(lms_quiz_answer_text('opt_1', $options, 'mcq') === 'Alpha', 'selected answer text should use stored option values');
$assert(lms_quiz_answer_text('opt_2', $options, 'mcq') === 'Beta', 'correct answer text should use stored option values');

$snapshot = lms_quiz_question_snapshot([
    'question_id' => 101,
    'prompt' => 'Pick the beta option',
    'question_type' => 'mcq',
    'points' => 2,
    'position' => 1,
    'is_required' => 1,
    'answer_key_json' => json_encode('opt_2'),
    'answer_explanation' => 'Beta is correct because it matches the prompt.',
], $options, '2026-06-30T10:30:00+00:00');
$assert($snapshot['answer_explanation'] === 'Beta is correct because it matches the prompt.', 'snapshot should keep the creator explanation for later review');
$reviewOptions = lms_quiz_review_options('mcq', $options, 'opt_1', 'opt_2');
$assert($reviewOptions[0]['is_selected'] === true && $reviewOptions[0]['is_correct'] === false, 'wrong selected option should be marked selected but not correct');
$assert($reviewOptions[1]['is_selected'] === false && $reviewOptions[1]['is_correct'] === true, 'correct option should be marked for review display');

$student = ['user_id' => 10, 'role_name' => 'student'];
$otherStudent = ['user_id' => 11, 'role_name' => 'student'];
$ta = ['user_id' => 20, 'role_name' => 'ta'];
$completedAttempt = ['student_user_id' => 10, 'status' => 'auto_graded', 'submitted_at' => '2026-06-30 10:31:00'];
$activeAttempt = ['student_user_id' => 10, 'status' => 'in_progress', 'submitted_at' => null];
$studentAccess = ['view_course' => true, 'participate_as_student' => true, 'grade_course' => false];
$graderAccess = ['view_course' => true, 'participate_as_student' => false, 'grade_course' => true];

$assert(simulate_quiz_attempt_review_access($student, $completedAttempt, $studentAccess)['status'] === 200, 'student can review their own completed attempt');
$assert(simulate_quiz_attempt_review_access($student, $activeAttempt, $studentAccess)['status'] === 409, 'active attempt review should be blocked before correct answers are exposed');
$assert(simulate_quiz_attempt_review_access($otherStudent, $completedAttempt, $studentAccess)['status'] === 403, 'student cannot view another student attempt');
$assert(simulate_quiz_attempt_review_access($ta, $completedAttempt, $graderAccess)['status'] === 200, 'staff with grade_course can review an attempt');

$root = dirname(__DIR__, 2);
$attemptGetSource = (string)file_get_contents($root . '/public/api/lms/quiz/attempt/get.php');
$questionListSource = (string)file_get_contents($root . '/public/api/lms/quiz/question/list.php');
$submitSource = (string)file_get_contents($root . '/public/api/lms/quiz/attempt/submit.php');

$assert(str_contains($attemptGetSource, "lms_require_roles(['student', 'ta', 'manager', 'admin'])"), 'attempt review endpoint should allow student owners and staff roles through the role gate');
$assert(str_contains($attemptGetSource, '$isOwnerWithStudentAccess') && str_contains($attemptGetSource, 'rbac_can($pdo, $user, \'grade_course\''), 'attempt review endpoint should enforce owner or grade_course access');
$assert(str_contains($attemptGetSource, "(string)$" . "attempt['status'] !== 'in_progress'") && str_contains($attemptGetSource, "Attempt review is available after submission"), 'attempt review endpoint should block active attempts');
$assert(str_contains($questionListSource, 'if (lms_is_staff_role($role))') && str_contains($questionListSource, "answer_explanation"), 'active question list should expose explanations only in the staff-only answer block');
$assert(str_contains($submitSource, 'question_snapshot_json') && str_contains($submitSource, 'lms_quiz_question_snapshot'), 'submit endpoint should persist submitted question snapshots for review');

if ($failed !== []) {
    fwrite(STDERR, "quiz_attempt_review_test FAILED:\n" . implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'quiz attempt review tests passed' . PHP_EOL;
