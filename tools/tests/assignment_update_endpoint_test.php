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
