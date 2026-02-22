<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function lms_user_role(array $user): string
{
    if (!empty($user['role_name']) && is_string($user['role_name'])) {
        return strtolower($user['role_name']);
    }

    static $cache = [];
    $userId = (int)($user['user_id'] ?? 0);
    if ($userId > 0 && isset($cache[$userId])) {
        return $cache[$userId];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT r.name FROM roles r JOIN users u ON u.role_id = r.role_id WHERE u.user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $role = strtolower((string)($stmt->fetchColumn() ?: 'student'));

    if ($userId > 0) {
        $cache[$userId] = $role;
    }

    return $role;
}

function lms_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
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
    return ((int)$value) === 1;
}

function lms_require_feature(array $flags, ?int $courseId = null): void
{
    foreach ($flags as $flag) {
        if (lms_feature_enabled((string)$flag, $courseId)) {
            return;
        }
    }
    lms_error('feature_disabled', 'This module is currently disabled.', 404);
}

function lms_course_access(array $user, int $courseId, bool $allowStaff = true): void
{
    $role = $user['role_name'] ?? lms_user_role($user);
    if (in_array($role, ['admin', 'manager'], true)) {
        return;
    }

    $pdo = db();
    if ($allowStaff && $role === 'ta') {
        $stmt = $pdo->prepare('SELECT 1 FROM course_staff WHERE user_id = :uid AND course_id = :cid AND role IN (\'ta\',\'manager\') LIMIT 1');
        $stmt->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }

    $stmt = $pdo->prepare('SELECT 1 FROM student_courses WHERE user_id = :uid AND course_id = :cid LIMIT 1');
    $stmt->execute([':uid' => (int)$user['user_id'], ':cid' => $courseId]);
    if (!$stmt->fetchColumn()) {
        lms_error('forbidden', 'You are not enrolled in this course.', 403);
    }
}

function lms_is_staff_role(string $role): bool
{
    return in_array(strtolower($role), ['admin', 'manager', 'ta'], true);
}

function lms_emit_event(PDO $pdo, string $eventName, array $event): void
{
    $sql = 'INSERT INTO lms_event_outbox (event_id, event_name, occurred_at, actor_user_id, course_id, entity_type, entity_id, payload_json) VALUES (:event_id,:event_name,:occurred_at,:actor_user_id,:course_id,:entity_type,:entity_id,:payload_json)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':event_id' => $event['event_id'],
        ':event_name' => $eventName,
        ':occurred_at' => $event['occurred_at'],
        ':actor_user_id' => $event['actor_id'] ?? null,
        ':course_id' => $event['course_id'] ?? null,
        ':entity_type' => $event['entity_type'] ?? 'unknown',
        ':entity_id' => $event['entity_id'] ?? null,
        ':payload_json' => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function lms_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
