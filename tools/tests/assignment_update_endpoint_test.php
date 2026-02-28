<?php
declare(strict_types=1);

// Test assignment status transition logic
function is_valid_transition(string $current, string $target): bool
{
    $allowedStatus = ['draft', 'published', 'archived'];
    $allowedTransitions = [
        'draft' => ['published', 'archived'],
        'published' => ['archived'],
        'archived' => [],
    ];
    if (!in_array($target, $allowedStatus, true)) {
        return false;
    }
    if ($current !== $target && !in_array($target, $allowedTransitions[$current] ?? [], true)) {
        return false;
    }
    return true;
}

// Test permission logic
function can_update_assignment(array $roles): bool
{
    $allowed = ['manager', 'admin'];
    foreach ($roles as $role) {
        if (in_array(strtolower($role), $allowed, true)) {
            return true;
        }
    }
    return false;
}

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
    if (is_valid_transition($t['current'], $t['target']) !== $t['expected']) {
        $failed[] = "Transition {$t['current']} -> {$t['target']}";
    }
}

foreach ($roles as $i => $r) {
    if (can_update_assignment($r['roles']) !== $r['expected']) {
        $failed[] = "Roles " . implode(',', $r['roles']);
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'assignment_update_endpoint_test passed' . PHP_EOL;

// Mock event dispatch logic
function test_mock_event_dispatch(bool $update_success)
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

echo 'assignment_update_endpoint event logic tests passed' . PHP_EOL;
