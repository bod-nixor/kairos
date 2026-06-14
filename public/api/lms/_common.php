<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/rbac.php';

function lms_user_role(array $user): string
{
    if (!empty($user['role_name']) && is_string($user['role_name'])) {
        return strtolower($user['role_name']);
    }

    static $cache = [];
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId > 0 && isset($cache[$userId])) {
        return $cache[$userId];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT r.name FROM roles r JOIN users u ON u.role_id = r.role_id WHERE u.user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $role = strtolower((string) ($stmt->fetchColumn() ?: 'student'));

    if ($userId > 0) {
        $cache[$userId] = $role;
    }

    return $role;
}

function lms_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        lms_error('invalid_json', 'Malformed JSON request body', 400);
    }
    if (!$decoded instanceof stdClass) {
        lms_error('invalid_json', 'JSON request body must be an object', 400);
    }
    $encoded = json_encode($decoded);
    $decodedArray = is_string($encoded) ? json_decode($encoded, true) : null;
    return is_array($decodedArray) ? $decodedArray : [];
}

function lms_ok($data = []): void
{
    json_out(['ok' => true, 'data' => $data]);
}

function lms_error(string $code, string $message, int $status = 400, ?array $details = null): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }
    json_out(['ok' => false, 'error' => $error], $status);
}

function lms_require_roles(array $roles): array
{
    $user = require_login();
    $role = lms_user_role($user);
    $allowed = array_map('strtolower', $roles);
    if (!in_array($role, $allowed, true)) {
        lms_error('forbidden', 'Insufficient permissions.', 403);
    }
    $user['role_name'] = $role;
    return $user;
}

function lms_feature_enabled(string $flagKey, ?int $courseId = null): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT enabled FROM lms_feature_flags WHERE flag_key = :flag_key AND (course_id = :course_id OR course_id IS NULL) ORDER BY course_id IS NULL ASC LIMIT 1'
    );
    $stmt->execute([
        ':flag_key' => $flagKey,
        ':course_id' => $courseId,
    ]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return false;
    }
    return ((int) $value) === 1;
}

function lms_require_feature(array $flags, ?int $courseId = null): void
{
    foreach ($flags as $flag) {
        if (lms_feature_enabled((string) $flag, $courseId)) {
            return;
        }
    }
    lms_error('feature_disabled', 'This module is currently disabled.', 404);
}

function lms_course_access(array $user, int $courseId, bool $allowStaff = true): void
{
    $pdo = db();
    $context = rbac_course_access_context($pdo, $user, $courseId);
    if ($context['view_course'] && ($allowStaff || $context['participate_as_student'])) {
        return;
    }
    if ($context['view_course_home'] && $context['can_self_enroll']) {
        lms_error('forbidden', 'You need to enrol before accessing this content.', 403);
    }
    if (!$allowStaff && $context['view_course']) {
        lms_error('forbidden', 'Student participation is required for this action.', 403);
    }

    lms_error('forbidden', 'Course access is required.', 403);
}

function lms_course_home_access(array $user, int $courseId): array
{
    if ($courseId <= 0) {
        lms_error('validation_error', 'course_id required', 422);
    }

    $context = rbac_course_access_context(db(), $user, $courseId);
    if (!$context['course_exists'] || !$context['course_active']) {
        lms_error('not_found', 'Course not found.', 404);
    }
    if (!$context['view_course_home']) {
        lms_error('not_found', 'Course not found.', 404);
    }
    return $context;
}

function lms_require_course_capability(array $user, string $capability, int $courseId): void
{
    if ($courseId <= 0) {
        lms_error('validation_error', 'course_id required', 422);
    }
    if (rbac_can(db(), $user, $capability, $courseId)) {
        return;
    }
    $context = rbac_course_access_context(db(), $user, $courseId);
    if ($context['view_course_home'] && in_array($capability, ['manage_course', 'grade_course'], true)) {
        lms_error('forbidden', 'This staff-only page is unavailable for your course role.', 403);
    }
    lms_error('forbidden', 'Insufficient permissions for this course.', 403);
}

function lms_course_role(array $user, int $courseId): ?string
{
    return rbac_course_role(db(), $user, $courseId);
}

function lms_course_capabilities(array $user, int $courseId): array
{
    $pdo = db();
    $context = rbac_course_access_context($pdo, $user, $courseId);
    return [
        'view_course_public' => (bool)$context['view_course_public'],
        'view_course_home' => (bool)$context['view_course_home'],
        'view_course' => (bool)$context['view_course'],
        'view_course_enrolled' => (bool)$context['view_course_enrolled'],
        'participate_as_student' => (bool)$context['participate_as_student'],
        'manage_course' => rbac_can($pdo, $user, 'manage_course', $courseId),
        'grade_course' => rbac_can($pdo, $user, 'grade_course', $courseId),
        'admin_course' => (bool)$context['admin_course'],
        'update_student_progress' => rbac_can($pdo, $user, 'update_student_progress', $courseId),
        'manage_course_announcements' => rbac_can($pdo, $user, 'manage_course_announcements', $courseId),
        'can_self_enroll' => (bool)$context['can_self_enroll'],
    ];
}

function lms_can_view_unpublished(array $user, int $courseId): bool
{
    return rbac_can_manage_course(db(), $user, $courseId);
}

function lms_assignment_scope(PDO $pdo, int $assignmentId): ?array
{
    if ($assignmentId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT assignment_id, course_id, status'
        . ' FROM lms_assignments'
        . ' WHERE assignment_id = :assignment_id AND deleted_at IS NULL'
        . ' LIMIT 1'
    );
    $stmt->execute([':assignment_id' => $assignmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function lms_submission_scope(PDO $pdo, int $submissionId): ?array
{
    if ($submissionId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT s.submission_id, s.assignment_id, s.course_id, s.student_user_id'
        . ' FROM lms_submissions s'
        . ' JOIN lms_assignments a ON a.assignment_id = s.assignment_id AND a.deleted_at IS NULL'
        . ' WHERE s.submission_id = :submission_id'
        . ' LIMIT 1'
    );
    $stmt->execute([':submission_id' => $submissionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function lms_ta_assigned_to_assignment(PDO $pdo, int $userId, int $assignmentId): bool
{
    if ($userId <= 0 || $assignmentId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM lms_assignment_tas'
        . ' WHERE assignment_id = :assignment_id AND ta_user_id = :user_id'
        . ' LIMIT 1'
    );
    $stmt->execute([
        ':assignment_id' => $assignmentId,
        ':user_id' => $userId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function lms_require_submission_access(
    PDO $pdo,
    array $user,
    array $submission,
    bool $allowOwner = true,
    bool $requireGrader = false
): void {
    $courseId = (int)($submission['course_id'] ?? 0);
    $assignmentId = (int)($submission['assignment_id'] ?? 0);
    $studentUserId = (int)($submission['student_user_id'] ?? 0);
    $userId = (int)($user['user_id'] ?? 0);
    $courseRole = rbac_course_role($pdo, $user, $courseId);

    if ($allowOwner && !$requireGrader && $courseRole === 'student' && $studentUserId === $userId) {
        return;
    }
    if ($courseRole === 'ta' && lms_ta_assigned_to_assignment($pdo, $userId, $assignmentId)) {
        return;
    }
    if (in_array($courseRole, ['manager', 'admin'], true)) {
        return;
    }
    lms_error('forbidden', 'Submission access is required.', 403);
}

function lms_is_staff_role(string $role): bool
{
    return in_array(strtolower($role), ['admin', 'manager', 'ta'], true);
}

function lms_emit_event(PDO $pdo, string $eventName, array $event): void
{
    try {
        // Normalize occurred_at to MySQL DATETIME format (Y-m-d H:i:s)
        $occurredAt = $event['occurred_at'] ?? gmdate('Y-m-d H:i:s');
        if (strtotime($occurredAt) !== false) {
            $occurredAt = gmdate('Y-m-d H:i:s', strtotime($occurredAt));
        }

        $payload_json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload_json === false) {
            error_log('[kairos] lms_emit_event json_encode failed for event: ' . $eventName);
            return;
        }

        $sql = 'INSERT INTO lms_event_outbox (event_id, event_name, occurred_at, actor_user_id, course_id, entity_type, entity_id, payload_json) VALUES (:event_id,:event_name,:occurred_at,:actor_user_id,:course_id,:entity_type,:entity_id,:payload_json)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':event_id' => $event['event_id'],
            ':event_name' => $eventName,
            ':occurred_at' => $occurredAt,
            ':actor_user_id' => $event['actor_id'] ?? null,
            ':course_id' => $event['course_id'] ?? null,
            ':entity_type' => $event['entity_type'] ?? 'unknown',
            ':entity_id' => $event['entity_id'] ?? null,
            ':payload_json' => $payload_json,
        ]);
    } catch (Throwable $e) {
        // Event emission must never block the calling operation.
        // Log the error but allow the primary transaction to succeed.
        error_log('[kairos] lms_emit_event failed (' . $eventName . '): ' . $e->getMessage());
    }
}

function lms_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
