<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/logger.php';
require_once dirname(__DIR__) . '/public/api/_helpers.php';
require_once dirname(__DIR__) . '/public/api/ta/common.php';

function rbac_user_id(array $user): int
{
    return isset($user['user_id']) ? (int)$user['user_id'] : 0;
}

function rbac_role_rank(PDO $pdo, array $user): int
{
    return user_role_rank($pdo, $user);
}

function rbac_is_admin(PDO $pdo, array $user): bool
{
    return user_role_at_least($pdo, $user, 'admin');
}

function rbac_is_manager(PDO $pdo, array $user): bool
{
    return user_role_at_least($pdo, $user, 'manager');
}

function rbac_is_ta(PDO $pdo, array $user): bool
{
    return user_role_at_least($pdo, $user, 'ta');
}

function rbac_is_student(PDO $pdo, array $user): bool
{
    return user_role_at_least($pdo, $user, 'student');
}

function rbac_quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function rbac_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1');
    $stmt->execute([':t' => $table]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}

function rbac_table_has_columns(PDO $pdo, string $table, array $columns): bool
{
    $columns = array_map(static fn($col) => strtolower((string)$col), $columns);
    sort($columns);
    $key = strtolower($table) . '|' . implode(',', $columns);
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!rbac_table_exists($pdo, $table)) {
        return $cache[$key] = false;
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'SELECT COUNT(*) FROM information_schema.COLUMNS'
         . ' WHERE TABLE_SCHEMA = DATABASE()'
         . '   AND TABLE_NAME = ?'
         . "   AND LOWER(COLUMN_NAME) IN ($placeholders)";
    $args = array_merge([$table], $columns);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $count = (int)$stmt->fetchColumn();
    return $cache[$key] = ($count === count($columns));
}

function rbac_course_exists(PDO $pdo, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    if (!rbac_table_exists($pdo, 'courses') || !rbac_table_has_columns($pdo, 'courses', ['course_id'])) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE course_id = :cid LIMIT 1');
    $stmt->execute([':cid' => $courseId]);
    return (bool)$stmt->fetchColumn();
}

function rbac_fetch_all_course_ids(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!rbac_table_exists($pdo, 'courses') || !rbac_table_has_columns($pdo, 'courses', ['course_id'])) {
        return $cache = [];
    }
    $stmt = $pdo->query('SELECT CAST(course_id AS UNSIGNED) AS course_id FROM courses');
    $ids = [];
    foreach ($stmt?->fetchAll(PDO::FETCH_COLUMN) ?? [] as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $ids[$cid] = true;
        }
    }
    $cache = array_keys($ids);
    sort($cache);
    return $cache;
}

function rbac_manager_course_ids(PDO $pdo, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if ($userId <= 0) {
        return $cache[$userId] = [];
    }
    try {
        $ids = ta_manager_course_ids($pdo, $userId);
    } catch (Throwable $e) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $cache[$userId] = $ids;
}

function rbac_ta_course_ids(PDO $pdo, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if ($userId <= 0) {
        return $cache[$userId] = [];
    }
    try {
        $courses = ta_courses($pdo, $userId);
    } catch (Throwable $e) {
        $courses = [];
    }
    $ids = [];
    foreach ($courses as $course) {
        if (isset($course['course_id'])) {
            $cid = (int)$course['course_id'];
            if ($cid > 0) {
                $ids[$cid] = true;
            }
        }
    }
    $cache[$userId] = array_keys($ids);
    sort($cache[$userId]);
    return $cache[$userId];
}

function rbac_student_course_mappings(): array
{
    return [
        ['table' => 'student_courses', 'user_col' => 'user_id', 'course_col' => 'course_id'],
        ['table' => 'user_courses',    'user_col' => 'user_id', 'course_col' => 'course_id'],
        ['table' => 'enrollments',     'user_col' => 'user_id', 'course_col' => 'course_id'],
    ];
}

function rbac_student_course_ids(PDO $pdo, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if ($userId <= 0) {
        return $cache[$userId] = [];
    }
    $ids = [];
    foreach (rbac_student_course_mappings() as $map) {
        $columns = [$map['user_col'], $map['course_col']];
        if (!rbac_table_has_columns($pdo, $map['table'], $columns)) {
            continue;
        }
        $table = rbac_quote_identifier($map['table']);
        $userCol = rbac_quote_identifier($map['user_col']);
        $courseCol = rbac_quote_identifier($map['course_col']);
        $sql = "SELECT DISTINCT CAST($courseCol AS UNSIGNED) AS cid FROM $table WHERE $userCol = :uid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $ids[$cid] = true;
            }
        }
        if ($ids) {
            break;
        }
    }
    $cache[$userId] = array_keys($ids);
    sort($cache[$userId]);
    return $cache[$userId];
}

function rbac_accessible_course_ids(PDO $pdo, array $user): ?array
{
    if (rbac_is_admin($pdo, $user)) {
        return null; // Admins see every course.
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return [];
    }
    $courses = [];
    if (rbac_is_manager($pdo, $user)) {
        $courses = array_merge($courses, rbac_manager_course_ids($pdo, $userId));
    }
    if (rbac_is_ta($pdo, $user)) {
        $courses = array_merge($courses, rbac_ta_course_ids($pdo, $userId));
    }
    if (rbac_is_student($pdo, $user)) {
        $courses = array_merge($courses, rbac_student_course_ids($pdo, $userId));
    }
    $courses = array_values(array_unique(array_map('intval', $courses)));
    sort($courses);
    return $courses;
}

function rbac_can_manage_course(PDO $pdo, array $user, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    if (rbac_is_admin($pdo, $user)) {
        return true;
    }
    if (!rbac_is_manager($pdo, $user)) {
        return false;
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return false;
    }
    return in_array($courseId, rbac_manager_course_ids($pdo, $userId), true);
}

function rbac_can_act_as_ta(PDO $pdo, array $user, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    if (rbac_is_admin($pdo, $user)) {
        return true;
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return false;
    }
    if (rbac_is_manager($pdo, $user) && in_array($courseId, rbac_manager_course_ids($pdo, $userId), true)) {
        return true;
    }
    if (!rbac_is_ta($pdo, $user)) {
        return false;
    }
    return in_array($courseId, rbac_ta_course_ids($pdo, $userId), true);
}

function rbac_can_access_course(PDO $pdo, array $user, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    if (rbac_is_admin($pdo, $user)) {
        return true;
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return false;
    }
    if (rbac_is_manager($pdo, $user) && in_array($courseId, rbac_manager_course_ids($pdo, $userId), true)) {
        return true;
    }
    if (rbac_is_ta($pdo, $user) && in_array($courseId, rbac_ta_course_ids($pdo, $userId), true)) {
        return true;
    }
    if (rbac_is_student($pdo, $user) && in_array($courseId, rbac_student_course_ids($pdo, $userId), true)) {
        return true;
    }
    return false;
}

function rbac_room_scope(PDO $pdo, int $roomId): ?array
{
    if ($roomId <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($roomId, $cache)) {
        return $cache[$roomId];
    }
    if (!rbac_table_exists($pdo, 'rooms') || !rbac_table_has_columns($pdo, 'rooms', ['room_id', 'course_id'])) {
        return $cache[$roomId] = null;
    }
    $stmt = $pdo->prepare('SELECT CAST(room_id AS UNSIGNED) AS room_id, CAST(course_id AS UNSIGNED) AS course_id FROM rooms WHERE room_id = :rid LIMIT 1');
    $stmt->execute([':rid' => $roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$roomId] = null;
    }
    return $cache[$roomId] = [
        'room_id'   => isset($row['room_id']) ? (int)$row['room_id'] : $roomId,
        'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
    ];
}

function rbac_queue_scope(PDO $pdo, int $queueId): ?array
{
    if ($queueId <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($queueId, $cache)) {
        return $cache[$queueId];
    }
    if (!rbac_table_exists($pdo, 'queues') || !rbac_table_has_columns($pdo, 'queues', ['queue_id', 'room_id'])) {
        return $cache[$queueId] = null;
    }
    $sql = 'SELECT CAST(q.queue_id AS UNSIGNED) AS queue_id,'
         . '       CAST(q.room_id AS UNSIGNED) AS room_id,'
         . '       CAST(r.course_id AS UNSIGNED) AS course_id'
         . '  FROM queues q'
         . '  JOIN rooms r ON r.room_id = q.room_id'
         . ' WHERE q.queue_id = :qid'
         . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':qid' => $queueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $cache[$queueId] = null;
    }
    return $cache[$queueId] = [
        'queue_id'  => isset($row['queue_id']) ? (int)$row['queue_id'] : $queueId,
        'room_id'   => isset($row['room_id']) ? (int)$row['room_id'] : null,
        'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
    ];
}

function rbac_can_view_room(PDO $pdo, array $user, int $roomId): bool
{
    $scope = rbac_room_scope($pdo, $roomId);
    if (!$scope) {
        return false;
    }
    $courseId = (int)($scope['course_id'] ?? 0);
    if ($courseId <= 0) {
        return rbac_is_admin($pdo, $user);
    }
    return rbac_can_access_course($pdo, $user, $courseId);
}

function rbac_can_view_queue(PDO $pdo, array $user, int $queueId, ?array $scope = null): bool
{
    $scope ??= rbac_queue_scope($pdo, $queueId);
    if (!$scope) {
        return false;
    }
    $courseId = (int)($scope['course_id'] ?? 0);
    if ($courseId <= 0) {
        return rbac_is_admin($pdo, $user);
    }
    return rbac_can_access_course($pdo, $user, $courseId);
}

function rbac_can_student_view_queue(PDO $pdo, array $user, int $queueId, ?array $scope = null): bool
{
    if (rbac_is_admin($pdo, $user)) {
        return true;
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return false;
    }
    $scope ??= rbac_queue_scope($pdo, $queueId);
    if (!$scope) {
        return false;
    }
    $courseId = (int)($scope['course_id'] ?? 0);
    if ($courseId <= 0) {
        return false;
    }
    return in_array($courseId, rbac_student_course_ids($pdo, $userId), true);
}

function rbac_can_student_join_queue(PDO $pdo, array $user, int $queueId, ?array $scope = null): bool
{
    if (rbac_is_admin($pdo, $user)) {
        return true;
    }
    $userId = rbac_user_id($user);
    if ($userId <= 0) {
        return false;
    }
    $scope ??= rbac_queue_scope($pdo, $queueId);
    if (!$scope) {
        return false;
    }
    $courseId = (int)($scope['course_id'] ?? 0);
    if ($courseId <= 0) {
        return false;
    }
    return in_array($courseId, rbac_student_course_ids($pdo, $userId), true);
}

function rbac_debug_deny(string $reason, array $context = []): void
{
    $context['reason'] = $reason;
    kairos_debug_log('rbac-denied', $context);
}
