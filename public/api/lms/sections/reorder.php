<?php
/**
 * POST /api/lms/sections/reorder.php
 * Reorder course sections (modules). Requires manager/admin with course access.
 *
 * Payload: { course_id: int, section_ids: [int, int, ...] }
 *   section_ids is the ordered array of section IDs in their new display order.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$courseId = (int) ($in['course_id'] ?? 0);

if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

$sectionIds = $in['section_ids'] ?? [];
if (!is_array($sectionIds) || count($sectionIds) === 0) {
    lms_error('validation_error', 'section_ids must be a non-empty array', 422);
}

$sectionIds = array_map('intval', $sectionIds);
foreach ($sectionIds as $id) {
    if ($id <= 0) {
        lms_error('validation_error', 'section_ids must contain positive integers', 422);
    }
}

if (count(array_unique($sectionIds)) !== count($sectionIds)) {
    lms_error('validation_error', 'section_ids must not contain duplicates', 422);
}

lms_course_access($user, $courseId);

$pdo = db();

// Verify the payload includes exactly all active sections in the course exactly once
$verifyStmt = $pdo->prepare('SELECT section_id FROM lms_course_sections WHERE course_id = :cid AND deleted_at IS NULL');
$verifyStmt->execute([':cid' => $courseId]);
$existingIds = array_map('intval', $verifyStmt->fetchAll(PDO::FETCH_COLUMN));

if (count($existingIds) !== count($sectionIds)) {
    lms_error('validation_error', 'section_ids must include all active sections exactly once', 422);
}

$diff1 = array_diff($existingIds, $sectionIds);
$diff2 = array_diff($sectionIds, $existingIds);
if (count($diff1) > 0 || count($diff2) > 0) {
    lms_error('validation_error', 'section_ids must include all active sections exactly once', 422);
}

// Update positions in a transaction
$pdo->beginTransaction();
try {
    $updateStmt = $pdo->prepare('UPDATE lms_course_sections SET position = :pos, updated_at = CURRENT_TIMESTAMP WHERE section_id = :id AND course_id = :cid AND deleted_at IS NULL');
    foreach ($sectionIds as $position => $sectionId) {
        $updateStmt->execute([
            ':pos' => $position + 1,
            ':id' => $sectionId,
            ':cid' => $courseId,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] sections/reorder failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to reorder sections', 500);
}

lms_ok(['reordered' => true]);
