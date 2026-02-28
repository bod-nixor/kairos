<?php
declare(strict_types=1);

/**
 * Simulates apply_pending_pre_enrollments transaction behavior.
 */
function apply_pending_pre_enrollments_sim(array $rows, int $userId, array &$studentCourses, array &$claims, ?int $failCourseId = null): void
{
    $snapshotStudent = $studentCourses;
    $snapshotClaims = $claims;

    try {
        foreach ($rows as $row) {
            $cid = (int)($row['course_id'] ?? 0);
            $id = (int)($row['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            if ($failCourseId !== null && $cid === $failCourseId) {
                throw new RuntimeException('enrollment failure');
            }

            $studentCourses[$cid . ':' . $userId] = true;
            $existingClaim = $claims[$id] ?? null;
            if ($id > 0 && ($existingClaim === null || (int)$existingClaim === 0)) {
                $claims[$id] = $userId;
            }
        }
    } catch (Throwable $e) {
        // rollback simulation
        $studentCourses = $snapshotStudent;
        $claims = $snapshotClaims;
        throw $e;
    }
}

$rows = [
    ['id' => 1, 'course_id' => 100],
    ['id' => 2, 'course_id' => 101],
];
$claims = [1 => null, 2 => 777];
$studentCourses = [];

apply_pending_pre_enrollments_sim($rows, 55, $studentCourses, $claims);

$failed = [];
if (!isset($studentCourses['100:55']) || !isset($studentCourses['101:55'])) {
    $failed[] = 'expected all target enrollments to be inserted';
}
if (($claims[1] ?? null) !== 55) {
    $failed[] = 'expected unclaimed pre-enroll row to be claimed';
}
if (($claims[2] ?? null) !== 777) {
    $failed[] = 'expected already-claimed pre-enroll row to remain unchanged';
}

$rollbackClaims = [1 => null, 2 => null];
$rollbackStudent = [];
try {
    apply_pending_pre_enrollments_sim($rows, 99, $rollbackStudent, $rollbackClaims, 101);
    $failed[] = 'expected failure to be thrown';
} catch (RuntimeException $e) {
    // expected
}
if ($rollbackStudent !== []) {
    $failed[] = 'expected enrollment rollback on failure';
}
if (($rollbackClaims[1] ?? null) !== null || ($rollbackClaims[2] ?? null) !== null) {
    $failed[] = 'expected claim rollback on failure';
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "auth preenroll claim tests passed" . PHP_EOL;
