<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/_restriction_helpers.php';

lms_require_feature(['assignments', 'lms_assignments']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();

$courseId = (int)($in['course_id'] ?? 0);
$title = trim((string)($in['title'] ?? ''));
$instructions = $in['instructions'] ?? $in['description'] ?? $in['description_html'] ?? null;

if ($courseId <= 0 || $title === '') {
    lms_error('validation_error', 'course_id and title required', 422);
}

lms_require_course_capability($user, 'manage_course', $courseId);

$allowedFileExtensions = lms_normalize_allowed_file_extensions($in['allowed_file_extensions'] ?? null);
$maxFileMb = lms_clamp_max_file_mb($in['max_file_mb'] ?? null, 50);
$maxPoints = $in['max_points'] ?? 100;
if (!is_numeric($maxPoints) || (float)$maxPoints <= 0) {
    lms_error('validation_error', 'max_points must be a positive number', 422);
}
if ($instructions !== null && !is_scalar($instructions)) {
    lms_error('validation_error', 'instructions must be a string', 422);
}
$dueAt = $in['due_at'] ?? null;
if ($dueAt === '') {
    $dueAt = null;
}
if ($dueAt !== null && strtotime((string)$dueAt) === false) {
    lms_error('validation_error', 'due_at must be a valid datetime', 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO lms_assignments (course_id, section_id, title, instructions, due_at, late_allowed, max_points, allowed_file_extensions, max_file_mb, status, created_by)
        VALUES (:course_id, :section_id, :title, :instructions, :due_at, :late_allowed, :max_points, :allowed_file_extensions, :max_file_mb, :status, :created_by)')
        ->execute([
        ':course_id' => $courseId,
        ':section_id' => isset($in['section_id']) ? (int)$in['section_id'] : null,
        ':title' => $title,
        ':instructions' => $instructions === null ? null : (string)$instructions,
        ':due_at' => $dueAt,
        ':late_allowed' => !empty($in['late_allowed']) ? 1 : 0,
        ':max_points' => (float)$maxPoints,
        ':allowed_file_extensions' => ($allowedFileExtensions === '' ? null : $allowedFileExtensions),
        ':max_file_mb' => $maxFileMb,
        ':status' => 'draft',
        ':created_by' => (int)$user['user_id'],
    ]);
    $assignmentId = (int)$pdo->lastInsertId();
    lms_emit_event($pdo, 'assignment.created', [
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'assignment',
        'entity_id' => $assignmentId,
        'course_id' => $courseId,
        'title' => $title,
        'status' => 'draft',
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('assignment_create_failed course_id=' . $courseId . ' user_id=' . (int)$user['user_id'] . ' exception=' . get_class($e));
    lms_error('assignment_create_failed', 'Failed to create assignment', 500);
}

lms_ok(['assignment_id' => $assignmentId]);
