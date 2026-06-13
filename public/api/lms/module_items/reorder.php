<?php
/**
 * POST /api/lms/module_items/reorder.php
 * Reorder module items within a section. Requires manager/admin with course access.
 *
 * Payload: { course_id: int, section_id: int, module_item_ids: [int, int, ...] }
 *   module_item_ids is the ordered array of item IDs in their new display order.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/_reorder.php';

$user = require_login();
$in = lms_json_input();
$courseId = (int) ($in['course_id'] ?? 0);
$sectionId = (int) ($in['section_id'] ?? 0);

if ($courseId <= 0 || $sectionId <= 0) {
    lms_error('validation_error', 'course_id and section_id required', 422);
}

try {
    $itemIds = lms_reorder_positive_ids($in, 'module_item_ids');
    $expectedItemIds = array_key_exists('expected_module_item_ids', $in)
        ? lms_reorder_positive_ids($in, 'expected_module_item_ids')
        : null;
} catch (InvalidArgumentException $e) {
    lms_error('validation_error', $e->getMessage(), 422);
}

lms_require_course_capability($user, 'manage_course', $courseId);

$pdo = db();
try {
    $pdo->beginTransaction();

    $secStmt = $pdo->prepare(
        'SELECT section_id FROM lms_course_sections'
        . ' WHERE section_id = :sid AND course_id = :cid AND deleted_at IS NULL'
        . ' LIMIT 1 FOR UPDATE'
    );
    $secStmt->execute([':sid' => $sectionId, ':cid' => $courseId]);
    if (!$secStmt->fetchColumn()) {
        $pdo->rollBack();
        lms_error('not_found', 'Section not found in this course', 404);
    }

    $verifyStmt = $pdo->prepare(
        'SELECT module_item_id, position FROM lms_module_items'
        . ' WHERE course_id = :cid AND section_id = :sid'
        . ' ORDER BY position ASC, module_item_id ASC FOR UPDATE'
    );
    $verifyStmt->execute([':cid' => $courseId, ':sid' => $sectionId]);
    $rows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentIds = array_map(static fn(array $row): int => (int)$row['module_item_id'], $rows);

    if (!lms_reorder_same_id_set($currentIds, $itemIds)) {
        $pdo->rollBack();
        lms_error('validation_error', 'module_item_ids must include all active items exactly once', 422);
    }
    if ($expectedItemIds !== null && $expectedItemIds !== $currentIds) {
        $pdo->rollBack();
        lms_error('reorder_conflict', 'The module order changed before this request was saved. Refresh and try again.', 409);
    }

    try {
        $temporaryPositions = lms_reorder_temporary_positions(
            array_map(static fn(array $row): int => (int)$row['position'], $rows),
            count($itemIds)
        );
    } catch (OverflowException $e) {
        $pdo->rollBack();
        lms_error('reorder_conflict', 'Module item positions must be normalized before reordering.', 409);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE lms_module_items'
        . ' SET position = :pos, updated_at = CURRENT_TIMESTAMP'
        . ' WHERE module_item_id = :id AND course_id = :cid AND section_id = :sid'
    );
    foreach ($itemIds as $index => $itemId) {
        $updateStmt->execute([
            ':pos' => $temporaryPositions[$index],
            ':id' => $itemId,
            ':cid' => $courseId,
            ':sid' => $sectionId,
        ]);
        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('Module item changed during reorder');
        }
    }
    foreach ($itemIds as $index => $itemId) {
        $updateStmt->execute([
            ':pos' => $index + 1,
            ':id' => $itemId,
            ':cid' => $courseId,
            ':sid' => $sectionId,
        ]);
        if ($updateStmt->rowCount() > 1) {
            throw new RuntimeException('Unexpected module item reorder row count');
        }
    }

    lms_emit_event($pdo, 'module_items.reordered', [
        'event_name' => 'module_items.reordered',
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)($user['user_id'] ?? 0),
        'entity_type' => 'course_section',
        'entity_id' => $sectionId,
        'course_id' => $courseId,
        'module_item_ids' => $itemIds,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] module_items/reorder failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to reorder module items', 500);
}

lms_ok(['reordered' => true]);
