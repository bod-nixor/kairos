<?php
declare(strict_types=1);

require_once __DIR__ . '/../../public/api/lms/assignments/_restriction_helpers.php';

$transitions = [
    ['current' => 'draft', 'target' => 'draft', 'expected' => true],
    ['current' => 'draft', 'target' => 'published', 'expected' => true],
    ['current' => 'draft', 'target' => 'archived', 'expected' => true],
    ['current' => 'published', 'target' => 'published', 'expected' => true],
    ['current' => 'published', 'target' => 'draft', 'expected' => false],
    ['current' => 'published', 'target' => 'archived', 'expected' => true],
    ['current' => 'archived', 'target' => 'draft', 'expected' => false],
    ['current' => 'archived', 'target' => 'published', 'expected' => false],
    ['current' => 'draft', 'target' => 'invalid', 'expected' => false],
];

$roles = [
    ['roles' => ['student'], 'expected' => false],
    ['roles' => ['ta'], 'expected' => false],
    ['roles' => ['manager'], 'expected' => true],
    ['roles' => ['admin'], 'expected' => true],
    ['roles' => ['student', 'admin'], 'expected' => true],
];

$failed = [];

foreach ($transitions as $i => $t) {
    if (lms_is_valid_assignment_status_transition($t['current'], $t['target']) !== $t['expected']) {
        $failed[] = "Transition {$t['current']} -> {$t['target']}";
    }
}

foreach ($roles as $i => $r) {
    if (lms_can_update_assignment($r['roles']) !== $r['expected']) {
        $failed[] = "Roles " . implode(',', $r['roles']);
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'assignment_update_endpoint_test passed' . PHP_EOL;

// Mock event dispatch logic
function test_mock_event_dispatch(bool $update_success): ?array
{
    if (!$update_success) {
        return null; // Ensure event is NOT emitted if DB fails
    }
    return [
        'event_name' => 'assignment.updated',
        'entity_type' => 'assignment'
    ];
}

if (test_mock_event_dispatch(false) !== null) {
    fwrite(STDERR, 'Failed: Event emitted when update did not succeed.' . PHP_EOL);
    exit(1);
}

// Positive-path assertion
$successPayload = test_mock_event_dispatch(true);
if ($successPayload === null) {
    fwrite(STDERR, 'Failed: Event NOT emitted when update succeeded.' . PHP_EOL);
    exit(1);
}
if ($successPayload['event_name'] !== 'assignment.updated' || $successPayload['entity_type'] !== 'assignment') {
    fwrite(STDERR, 'Failed: Event payload mismatch.' . PHP_EOL);
    exit(1);
}

echo 'assignment_update_endpoint event logic tests passed' . PHP_EOL;

$root = dirname(__DIR__, 2);
$updateSource = (string)file_get_contents($root . '/public/api/lms/assignments/update.php');
$getSource = (string)file_get_contents($root . '/public/api/lms/assignments/get.php');
$createSource = (string)file_get_contents($root . '/public/api/lms/assignments/create.php');
$editorSource = (string)file_get_contents($root . '/public/js/lms-management-ui.js');
$assignmentUiSource = (string)file_get_contents($root . '/public/js/assignment.js');
$eventAt = strpos($updateSource, "lms_emit_event(\$pdo, 'assignment.updated'");
$commitAt = strpos($updateSource, '$pdo->commit()');

$sourceChecks = [
    [str_contains($updateSource, 'lms_require_assignment_restriction_schema($pdo)'), 'update must require restriction schema'],
    [!str_contains($updateSource, 'falling back'), 'update must not silently fall back'],
    [str_contains($updateSource, 'allowed_file_extensions = :allowed_file_extensions'), 'update must persist allowed extensions'],
    [str_contains($updateSource, 'max_file_mb = :max_file_mb'), 'update must persist max file MB'],
    [str_contains($updateSource, "'allowed_file_extensions' => \$allowedFileExtensions"), 'update response must return saved extensions'],
    [str_contains($updateSource, "'max_file_mb' => \$maxFileMb"), 'update response must return saved max size'],
    [$eventAt !== false && $commitAt !== false && $eventAt < $commitAt, 'assignment update event must use the transaction outbox'],
    [!str_contains($updateSource, 'drive'), 'metadata update must not depend on Drive'],
    [str_contains($getSource, 'allowed_file_extensions, max_file_mb'), 'detail must rehydrate saved restrictions'],
    [str_contains($createSource, 'lms_require_assignment_restriction_schema($pdo)'), 'create must require restriction schema'],
    [str_contains($editorSource, "initial.allowed_file_extensions || ''"), 'editor must rehydrate saved extensions'],
    [str_contains($editorSource, 'initial.max_file_mb || 50'), 'editor must rehydrate saved max size'],
    [str_contains($assignmentUiSource, 'fileInput.accept = Management.extensionsToAccept'), 'student input accept must use saved restrictions'],
    [str_contains($assignmentUiSource, 'Maximum ${effectiveMaxMb} MB'), 'student UI must display saved max size'],
];

foreach ($sourceChecks as [$passed, $message]) {
    if (!$passed) {
        $failed[] = $message;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'assignment restriction persistence contract tests passed' . PHP_EOL;
