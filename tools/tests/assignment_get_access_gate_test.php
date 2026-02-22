<?php
declare(strict_types=1);

function assignment_access_allowed(bool $isStaffRole, int $publishedFlag, string $status): bool
{
    if ($isStaffRole) {
        return true;
    }
    return $publishedFlag === 1 && $status === 'published';
}

$cases = [
    ['name' => 'student denied when module unpublished', 'staff' => false, 'published_flag' => 0, 'status' => 'published', 'expected' => false],
    ['name' => 'student denied when assignment draft', 'staff' => false, 'published_flag' => 1, 'status' => 'draft', 'expected' => false],
    ['name' => 'staff allowed when unpublished', 'staff' => true, 'published_flag' => 0, 'status' => 'draft', 'expected' => true],
];

$failed = [];
foreach ($cases as $case) {
    $actual = assignment_access_allowed($case['staff'], $case['published_flag'], $case['status']);
    if ($actual !== $case['expected']) {
        $failed[] = $case['name'];
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'assignment_get access gate tests passed' . PHP_EOL;
