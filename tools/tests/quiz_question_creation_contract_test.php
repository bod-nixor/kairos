<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/api/lms/quiz/question/_validation.php';

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

try {
    $definition = lms_validate_question_definition('mcq', 2.5, [
        ['value' => 'opt_1', 'text' => 'Alpha'],
        ['value' => 'opt_2', 'text' => 'Beta'],
        ['value' => 'opt_3', 'text' => 'Gamma'],
    ], 'opt_2');
    $assert($definition['question_type'] === 'mcq', 'MCQ type should remain canonical');
    $assert($definition['points'] === 2.5, 'MCQ points should be retained');
    $assert($definition['answer_key'] === 'opt_2', 'MCQ should keep exactly one correct answer value');
    $assert(count($definition['options']) === 3, 'MCQ should keep all normalized options');
} catch (Throwable $e) {
    $failed[] = 'valid MCQ question definition failed: ' . $e->getMessage();
}

try {
    lms_validate_question_definition('mcq', 1.0, [
        ['value' => 'opt_1', 'text' => 'Alpha'],
        ['value' => 'opt_2', 'text' => 'Beta'],
    ], 'opt_3');
    $failed[] = 'MCQ with answer outside option values should fail validation';
} catch (InvalidArgumentException $e) {
    $assert(str_contains($e->getMessage(), 'one valid answer'), 'invalid MCQ answer should return stable validation message');
}

$createSource = (string)file_get_contents(dirname(__DIR__, 2) . '/public/api/lms/quiz/question/create.php');
$assert(str_contains($createSource, "'context' => 'lms.quiz.question.create'"), 'create endpoint should log structured internal failure context');
$assert(str_contains($createSource, "'exception_message' => $" . "e->getMessage()"), 'create endpoint should log the real exception message server-side');
$assert(str_contains($createSource, "lms_error('question_create_failed', 'Failed to create question', 500)"), 'create endpoint should keep a safe client-facing error');

$migration = (string)file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260629_1200_fix_quiz_question_schema.sql');
$assert(str_contains($migration, 'ADD COLUMN is_required'), 'migration should add missing is_required column');
$assert(str_contains($migration, "'multiple_select'"), 'migration should allow canonical multiple_select enum value');
$assert(str_contains($migration, 'idx_lms_questions_required'), 'migration should add required-question lookup index');

$htaccess = (string)file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
$assert(str_contains($htaccess, 'websocket/socket\\.io'), 'Apache routing should proxy configured Socket.IO path');
$assert(str_contains($htaccess, 'http://127.0.0.1:8090/websocket/socket.io'), 'Socket.IO polling should proxy to realtime service');
$assert(str_contains($htaccess, 'ws://127.0.0.1:8090/websocket/socket.io'), 'Socket.IO websocket upgrades should proxy to realtime service');

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'quiz question creation contract tests passed' . PHP_EOL;
