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
require_once dirname(__DIR__) . '/_reorder.php';

$user = require_login();
$in = lms_json_input();
$courseId = (int) ($in['course_id'] ?? 0);

if ($courseId <= 0) {
    lms_error('validation_error', 'course_id required', 422);
}

try {
    $sectionIds = lms_reorder_positive_ids($in, 'section_ids');
    $expectedSectionIds = array_key_exists('expected_section_ids', $in)
        ? lms_reorder_positive_ids($in, 'expected_section_ids')
        : null;
} catch (InvalidArgumentException $e) {
    lms_error('validation_error', $e->getMessage(), 422);
}

lms_require_course_capability($user, 'manage_course', $courseId);

$pdo = db();
try {
    $pdo->beginTransaction();

    $verifyStmt = $pdo->prepare(
        'SELECT section_id FROM lms_course_sections'
        . ' WHERE course_id = :cid AND deleted_at IS NULL'
        . ' ORDER BY position ASC, section_id ASC FOR UPDATE'
    );
    $verifyStmt->execute([':cid' => $courseId]);
    $currentIds = array_map('intval', $verifyStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!lms_reorder_same_id_set($currentIds, $sectionIds)) {
        $pdo->rollBack();
        lms_error('validation_error', 'section_ids must include all active sections exactly once', 422);
    }
    if ($expectedSectionIds !== null && $expectedSectionIds !== $currentIds) {
        $pdo->rollBack();
        lms_error('reorder_conflict', 'The module order changed before this request was saved. Refresh and try again.', 409);
    }

    $updateStmt = $pdo->prepare('UPDATE lms_course_sections SET position = :pos, updated_at = CURRENT_TIMESTAMP WHERE section_id = :id AND course_id = :cid AND deleted_at IS NULL');
    foreach ($sectionIds as $position => $sectionId) {
        $updateStmt->execute([
            ':pos' => $position + 1,
            ':id' => $sectionId,
            ':cid' => $courseId,
        ]);
        if ($updateStmt->rowCount() > 1) {
            throw new RuntimeException('Unexpected section reorder row count');
        }
    }

    lms_emit_event($pdo, 'sections.reordered', [
        'event_name' => 'sections.reordered',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)($user['user_id'] ?? 0),
        'entity_type' => 'course',
        'entity_id' => $courseId,
        'course_id' => $courseId,
        'section_ids' => $sectionIds,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] sections/reorder failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to reorder sections', 500);
}

lms_ok(['reordered' => true]);
