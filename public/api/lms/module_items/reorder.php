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

$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$courseId = (int) ($in['course_id'] ?? 0);
$sectionId = (int) ($in['section_id'] ?? 0);

if ($courseId <= 0 || $sectionId <= 0) {
    lms_error('validation_error', 'course_id and section_id required', 422);
}

$itemIds = $in['module_item_ids'] ?? [];
if (!is_array($itemIds) || count($itemIds) === 0) {
    lms_error('validation_error', 'module_item_ids must be a non-empty array', 422);
}

$itemIds = array_map('intval', $itemIds);
foreach ($itemIds as $id) {
    if ($id <= 0) {
        lms_error('validation_error', 'module_item_ids must contain positive integers', 422);
    }
}

if (count(array_unique($itemIds)) !== count($itemIds)) {
    lms_error('validation_error', 'module_item_ids must not contain duplicates', 422);
}

lms_course_access($user, $courseId);

$pdo = db();

// Verify section belongs to course
$secStmt = $pdo->prepare('SELECT section_id FROM lms_course_sections WHERE section_id = :sid AND course_id = :cid AND deleted_at IS NULL LIMIT 1');
$secStmt->execute([':sid' => $sectionId, ':cid' => $courseId]);
if (!$secStmt->fetch()) {
    lms_error('not_found', 'Section not found in this course', 404);
}

// Verify the payload exactly matches the complete set of module_item_ids for this section
$verifyStmt = $pdo->prepare('SELECT module_item_id FROM lms_module_items WHERE course_id = :cid AND section_id = :sid');
$verifyStmt->execute([':cid' => $courseId, ':sid' => $sectionId]);
$existingIds = array_map('intval', $verifyStmt->fetchAll(PDO::FETCH_COLUMN));

if (count($existingIds) !== count($itemIds)) {
    lms_error('validation_error', 'module_item_ids must include all active items exactly once', 422);
}
$diff1 = array_diff($existingIds, $itemIds);
$diff2 = array_diff($itemIds, $existingIds);
if (count($diff1) > 0 || count($diff2) > 0) {
    lms_error('validation_error', 'module_item_ids must include all active items exactly once', 422);
}

// Update positions in a transaction
// First, temporarily set all positions to negative values to avoid unique constraint conflicts
$pdo->beginTransaction();
try {
    // Set all items to negative positions first
    $tmpStmt = $pdo->prepare('UPDATE lms_module_items SET position = :pos WHERE module_item_id = :id AND course_id = :cid AND section_id = :sid');
    foreach ($itemIds as $index => $itemId) {
        $tmpStmt->execute([
            ':pos' => -($index + 1),
            ':id' => $itemId,
            ':cid' => $courseId,
            ':sid' => $sectionId,
        ]);
    }
    // Now set correct positive positions
    foreach ($itemIds as $index => $itemId) {
        $tmpStmt->execute([
            ':pos' => $index + 1,
            ':id' => $itemId,
            ':cid' => $courseId,
            ':sid' => $sectionId,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] module_items/reorder failed: ' . $e->getMessage());
    lms_error('server_error', 'Failed to reorder module items', 500);
}

lms_ok(['reordered' => true]);
