<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/api/lms/_reorder.php';

function simulate_reorder(array $actor, int $courseId, array $payload, array $currentIds): array
{
    if (($actor['capabilities']['manage_course'] ?? false) !== true) {
        return ['status' => 403, 'error' => 'forbidden'];
    }
    if ((int)($actor['course_id'] ?? 0) !== $courseId) {
        return ['status' => 403, 'error' => 'forbidden'];
    }

    try {
        $submittedIds = lms_reorder_positive_ids($payload, 'module_item_ids');
        $expectedIds = array_key_exists('expected_module_item_ids', $payload)
            ? lms_reorder_positive_ids($payload, 'expected_module_item_ids')
            : null;
    } catch (InvalidArgumentException $e) {
        return ['status' => 422, 'error' => 'validation_error'];
    }

    if (!lms_reorder_same_id_set($currentIds, $submittedIds)) {
        return ['status' => 422, 'error' => 'validation_error'];
    }
    if ($expectedIds !== null && $expectedIds !== $currentIds) {
        return ['status' => 409, 'error' => 'reorder_conflict'];
    }

    return ['status' => 200, 'order' => $submittedIds];
}

$manager = ['capabilities' => ['manage_course' => true], 'course_id' => 101];
$current = [501, 502, 503];
$cases = [
    ['valid reorder', $manager, 101, ['module_item_ids' => [503, 501, 502], 'expected_module_item_ids' => $current], 200],
    ['student denied', ['capabilities' => ['manage_course' => false], 'course_id' => 101], 101, ['module_item_ids' => $current], 403],
    ['ta denied', ['capabilities' => [], 'course_id' => 101], 101, ['module_item_ids' => $current], 403],
    ['foreign course denied', $manager, 202, ['module_item_ids' => $current], 403],
    ['missing item rejected', $manager, 101, ['module_item_ids' => [501, 502]], 422],
    ['foreign item rejected', $manager, 101, ['module_item_ids' => [501, 502, 999]], 422],
    ['duplicate rejected', $manager, 101, ['module_item_ids' => [501, 501, 503]], 422],
    ['zero rejected', $manager, 101, ['module_item_ids' => [501, 0, 503]], 422],
    ['malformed numeric string rejected', $manager, 101, ['module_item_ids' => [501, '502abc', 503]], 422],
    ['float rejected', $manager, 101, ['module_item_ids' => [501, 502.5, 503]], 422],
    ['stale expected order rejected', $manager, 101, ['module_item_ids' => [503, 501, 502], 'expected_module_item_ids' => [501, 503, 502]], 409],
];

$failed = [];
foreach ($cases as [$name, $actor, $courseId, $payload, $expectedStatus]) {
    $actual = simulate_reorder($actor, $courseId, $payload, $current);
    if ((int)$actual['status'] !== $expectedStatus) {
        $failed[] = "FAIL [{$name}]: expected {$expectedStatus}, got {$actual['status']}";
    }
}

try {
    $temporary = lms_reorder_temporary_positions([1, 2, 3], 3);
    if ($temporary !== [4, 5, 6] || min($temporary) < 0) {
        $failed[] = 'temporary positions must be unique, positive, and above the current range';
    }
} catch (Throwable $e) {
    $failed[] = 'temporary position allocation failed: ' . $e->getMessage();
}

try {
    $temporary = lms_reorder_temporary_positions([4294967293], 2);
    if ($temporary !== [4294967294, 4294967295] || count(array_unique($temporary)) !== 2) {
        $failed[] = 'top unsigned-int boundary must remain a valid temporary range';
    }
} catch (Throwable $e) {
    $failed[] = 'top unsigned-int boundary was incorrectly rejected: ' . $e->getMessage();
}

try {
    lms_reorder_temporary_positions([4294967295], 1);
    $failed[] = 'temporary position overflow must be rejected';
} catch (OverflowException $e) {
    // Expected.
}

$root = dirname(__DIR__, 2);
$itemEndpoint = (string)file_get_contents($root . '/public/api/lms/module_items/reorder.php');
$sectionEndpoint = (string)file_get_contents($root . '/public/api/lms/sections/reorder.php');
$frontend = (string)file_get_contents($root . '/public/js/modules.js');

foreach ([$itemEndpoint, $sectionEndpoint] as $source) {
    if (!str_contains($source, "lms_require_course_capability(\$user, 'manage_course'")) {
        $failed[] = 'reorder endpoints must enforce the server-side manage_course capability';
    }
    if (!str_contains($source, 'FOR UPDATE')) {
        $failed[] = 'reorder endpoints must lock authoritative rows before validating order';
    }
    $event = strpos($source, 'lms_emit_event');
    $commit = strpos($source, '$pdo->commit()');
    if ($event === false || $commit === false || $event > $commit) {
        $failed[] = 'reorder outbox events must be written inside the transaction before commit';
    }
}

if (preg_match("/':pos'\\s*=>\\s*-/", $itemEndpoint) === 1) {
    $failed[] = 'module item reorder must never write negative positions to an unsigned column';
}
foreach (['expected_module_item_ids', 'itemReordersPending', 'move-item-up', 'move-item-down', 'move-module-up', 'move-module-down', 'refreshModuleMoveButtons', 'restoreElementOrder'] as $needle) {
    if (!str_contains($frontend, $needle)) {
        $failed[] = "module reorder frontend is missing {$needle}";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo 'reorder endpoint regression tests passed' . PHP_EOL;
