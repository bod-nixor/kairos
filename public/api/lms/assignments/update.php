<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_restriction_helpers.php';

$in = lms_json_input();
$id = (int) ($in['assignment_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'assignment_id required', 422);
}

// Fetch course_id early to pass to feature flags
$pdo = db();
$courseId = (int) ($in['course_id'] ?? $_GET['course_id'] ?? 0);
if ($courseId <= 0) {
    $stmt = $pdo->prepare('SELECT course_id FROM lms_assignments WHERE assignment_id=:id');
    $stmt->execute([':id' => $id]);
    $courseId = (int) $stmt->fetchColumn();
}

// Feature flag check — fail-closed, course scoped
try {
    lms_require_feature(['lms_assignments', 'assignments'], $courseId > 0 ? $courseId : null);
} catch (Throwable $e) {
    $courseStr = $courseId > 0 ? "course_id={$courseId}" : 'no course_context';
    error_log("[kairos] lms_require_feature check failed in update.php ({$courseStr}): " . $e->__toString());
    lms_error('feature_disabled', 'Feature check failed or disabled', 404);
}

// Pre-auth via helper if testing manually, but _common's lms_require_roles intercepts execution so we just call it
$user = lms_require_roles(['manager', 'admin']);

// $pdo already defined above
// Try to fetch assignment including restriction columns; fall back to core columns
$existing = null;
$hasRestrictionCols = true;
try {
    $existingStmt = $pdo->prepare(
        'SELECT assignment_id, course_id, title, instructions, due_at, late_allowed, max_points,
                allowed_file_extensions, max_file_mb, status
         FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1'
    );
    $existingStmt->execute([':id' => $id]);
    $existing = $existingStmt->fetch();
} catch (Throwable $e) {
    // Restriction columns may not exist yet (migration not applied)
    $hasRestrictionCols = false;
    error_log('[kairos] assignment select with restriction cols failed, falling back: ' . $e->getMessage());
    $existingStmt = $pdo->prepare(
        'SELECT assignment_id, course_id, title, instructions, due_at, late_allowed, max_points, status
         FROM lms_assignments WHERE assignment_id=:id AND deleted_at IS NULL LIMIT 1'
    );
    $existingStmt->execute([':id' => $id]);
    $existing = $existingStmt->fetch();
}

if (!$existing) {
    lms_error('not_found', 'Assignment not found', 404);
}

lms_course_access($user, (int) $existing['course_id']);

if (array_key_exists('status', $in)) {
    $targetStatus = (string) $in['status'];
    $currentStatus = (string) $existing['status'];
    if (!lms_is_valid_assignment_status_transition($currentStatus, $targetStatus)) {
        lms_error('validation_error', 'invalid status transition', 422);
    }
}

$title = array_key_exists('title', $in) ? trim((string) $in['title']) : (string) $existing['title'];
if ($title === '') {
    lms_error('validation_error', 'title cannot be blank', 422);
}

$instructionsRaw = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? $existing['instructions'];
if ($instructionsRaw === null) {
    $instructions = null;
} elseif (is_scalar($instructionsRaw)) {
    $instructions = (string) $instructionsRaw;
} else {
    lms_error('validation_error', 'instructions must be a string', 422);
}

$dueAt = $existing['due_at'];
if (array_key_exists('due_at', $in)) {
    if ($in['due_at'] === null || $in['due_at'] === '') {
        $dueAt = null;
    } else {
        $candidate = (string) $in['due_at'];
        if (strtotime($candidate) === false) {
            lms_error('validation_error', 'due_at must be a valid datetime', 422);
        }
        $dueAt = $candidate;
    }
}

$lateAllowed = array_key_exists('late_allowed', $in)
    ? (!empty($in['late_allowed']) ? 1 : 0)
    : (int) $existing['late_allowed'];

$maxPoints = (float) $existing['max_points'];
if (array_key_exists('max_points', $in)) {
    if (!is_numeric($in['max_points']) || (float) $in['max_points'] <= 0) {
        lms_error('validation_error', 'max_points must be a positive number', 422);
    }
    $maxPoints = (float) $in['max_points'];
}

$status = array_key_exists('status', $in) ? (string) $in['status'] : (string) $existing['status'];

// Main assignment update — in its own transaction
$pdo->beginTransaction();
try {
    if ($hasRestrictionCols) {
        $allowedFileExtensions = lms_normalize_allowed_file_extensions(
            array_key_exists('allowed_file_extensions', $in)
            ? $in['allowed_file_extensions']
            : ($existing['allowed_file_extensions'] ?? null)
        );
        $maxFileMb = lms_clamp_max_file_mb(
            array_key_exists('max_file_mb', $in)
            ? $in['max_file_mb']
            : ($existing['max_file_mb'] ?? 50),
            50
        );

        $pdo->prepare(
            'UPDATE lms_assignments SET title=:t, instructions=:i, due_at=:d, late_allowed=:l,
                    max_points=:m, allowed_file_extensions=:afe, max_file_mb=:mfm,
                    status=:st, updated_at=CURRENT_TIMESTAMP
             WHERE assignment_id=:id AND deleted_at IS NULL'
        )->execute([
                    ':t' => $title,
                    ':i' => $instructions,
                    ':d' => $dueAt,
                    ':l' => $lateAllowed,
                    ':m' => $maxPoints,
                    ':afe' => ($allowedFileExtensions === '' ? null : $allowedFileExtensions),
                    ':mfm' => $maxFileMb,
                    ':st' => $status,
                    ':id' => $id,
                ]);
    } else {
        // Fallback: update without restriction columns
        $pdo->prepare(
            'UPDATE lms_assignments SET title=:t, instructions=:i, due_at=:d, late_allowed=:l,
                    max_points=:m, status=:st, updated_at=CURRENT_TIMESTAMP
             WHERE assignment_id=:id AND deleted_at IS NULL'
        )->execute([
                    ':t' => $title,
                    ':i' => $instructions,
                    ':d' => $dueAt,
                    ':l' => $lateAllowed,
                    ':m' => $maxPoints,
                    ':st' => $status,
                    ':id' => $id,
                ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kairos] assignment update failed (id=' . $id . '): ' . $e->getMessage());
    lms_error('update_failed', 'Failed to update assignment.', 500);
}

// Event emission — OUTSIDE the transaction so failures don't block the update
lms_emit_event($pdo, 'assignment.updated', [
    'event_id' => lms_uuid_v4(),
    'occurred_at' => gmdate('Y-m-d H:i:s'),
    'actor_id' => (int) $user['user_id'],
    'entity_type' => 'assignment',
    'entity_id' => $id,
    'course_id' => (int) $existing['course_id'],
    'title' => $title,
    'status' => $status,
]);

lms_ok(['updated' => true]);
