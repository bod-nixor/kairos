<?php
declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $default;
    }
}

require_once dirname(__DIR__, 2) . '/public/api/lms/assignments/_restriction_helpers.php';
require_once dirname(__DIR__, 2) . '/public/api/lms/quiz/question/_validation.php';

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

$normalized = lms_assignment_normalize_extension_values('.PDF, docx;pdf JSON');
$assert($normalized['extensions'] === ['pdf', 'docx', 'json'], 'extensions should normalize and deduplicate');
$assert($normalized['errors'] === [], 'safe extensions should not produce errors');

foreach (['svg', 'html', 'js', 'php'] as $dangerous) {
    $result = lms_assignment_normalize_extension_values($dangerous);
    $assert($result['extensions'] === [], ".{$dangerous} should be rejected");
    $assert(str_contains(implode(' ', $result['errors']), 'blocked'), ".{$dangerous} should have a stable blocked message");
}

$presets = lms_assignment_file_type_presets();
$assert(($presets['documents']['extensions'] ?? []) === ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'], 'document preset should be stable');
$assert(!in_array('svg', $presets['images']['extensions'] ?? [], true), 'SVG should not be in the image preset');
$assert(!isset(lms_upload_policy()['svg']), 'SVG should not be accepted by storage policy');

$tmp = tempnam(sys_get_temp_dir(), 'kairos_policy_');
if ($tmp === false) {
    $failed[] = 'failed to create upload policy fixture';
} else {
    file_put_contents($tmp, "%PDF-1.4\npolicy fixture\n");
    $validPdf = lms_upload_type_validation('pdf', 'application/pdf', $tmp);
    $mismatch = lms_upload_type_validation('png', 'application/pdf', $tmp);
    $unsupported = lms_upload_type_validation('svg', 'image/svg+xml', $tmp);
    $assert($validPdf['ok'] === true, 'valid PDF MIME should pass');
    $assert($mismatch['ok'] === false && $mismatch['reason'] === 'content_mismatch', 'extension/MIME mismatch should fail');
    $assert($unsupported['ok'] === false && $unsupported['reason'] === 'unsupported_extension', 'unsupported extension should fail');
    unlink($tmp);
}

$assert(!lms_upload_size_allowed(1025, 1024), 'oversized upload should fail');
$assert(lms_upload_size_allowed(1024, 1024), 'upload at the limit should pass');
$assert(lms_assignment_allowed_extensions_for_validation('') === null, 'empty assignment restriction means any supported safe type');
$assert(lms_assignment_allowed_extensions_for_validation('PDF,docx,pdf') === ['pdf', 'docx'], 'saved assignment restrictions normalize consistently');
$assert(lms_assignment_effective_max_bytes(1) <= 1024 * 1024, 'assignment MB limit converts to bytes without exceeding the saved limit');

$submitSource = (string)file_get_contents(dirname(__DIR__, 2) . '/public/api/lms/assignments/submit.php');
$validationAt = strpos($submitSource, 'lms_validate_uploaded_file');
$driveEnabledAt = strpos($submitSource, 'lms_drive_writes_enabled');
$driveAt = strpos($submitSource, '$storage->upload');
$transactionAt = strpos($submitSource, '$pdo->beginTransaction');
$assert($validationAt !== false && $driveAt !== false && $validationAt < $driveAt, 'file validation must happen before Drive upload');
$assert($validationAt !== false && $driveEnabledAt !== false && $validationAt < $driveEnabledAt, 'metadata validation must happen before Drive-disabled error');
$assert($validationAt !== false && $transactionAt !== false && $validationAt < $transactionAt, 'file validation must happen before DB writes');
$assert($driveEnabledAt !== false && $transactionAt !== false && $driveEnabledAt < $transactionAt, 'Drive-disabled upload must fail before DB writes');
$assert(str_contains($submitSource, 'lms_require_assignment_restriction_schema($pdo)'), 'upload must read the canonical assignment restriction schema');

$driveSource = (string)file_get_contents(dirname(__DIR__, 2) . '/public/api/lms/drive_client.php');
$assert(str_contains($driveSource, 'File type .'), 'file type errors should remain explicit');
$assert(str_contains($driveSource, 'File content does not match'), 'MIME mismatch error should remain stable');
$assert(str_contains($driveSource, 'File exceeds the maximum allowed size'), 'size error should remain stable');

try {
    $mcq = lms_validate_question_definition('mcq', 2.0, [
        ['value' => 'opt_1', 'text' => 'One'],
        ['value' => 'opt_2', 'text' => 'Two'],
    ], 'opt_2');
    $assert($mcq['answer_key'] === 'opt_2', 'valid MCQ answer should be retained');
} catch (Throwable $e) {
    $failed[] = 'valid MCQ unexpectedly failed: ' . $e->getMessage();
}

foreach ([
    ['mcq', 0.0, [['value' => 'opt_1', 'text' => 'One'], ['value' => 'opt_2', 'text' => 'Two']], 'opt_1'],
    ['mcq', 1.0, [['value' => 'opt_1', 'text' => 'One']], 'opt_1'],
    ['true_false', 1.0, [], 'maybe'],
] as [$type, $points, $options, $answer]) {
    try {
        lms_validate_question_definition($type, $points, $options, $answer);
        $failed[] = "invalid {$type} definition should fail";
    } catch (InvalidArgumentException $e) {
        // Expected.
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'LMS upload and question policy tests passed' . PHP_EOL;
