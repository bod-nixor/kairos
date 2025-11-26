<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

function table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
    $st->execute([':table' => $table]);
    $cache[$key] = (bool)$st->fetchColumn();
    return $cache[$key];
}

function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $st->execute([':table' => $table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $cols[strtolower((string)$col)] = true;
    }
    $cache[$key] = $cols;
    return $cache[$key];
}

function table_has_columns(PDO $pdo, string $table, array $columns): bool {
    $cols = table_columns($pdo, $table);
    foreach ($columns as $col) {
        if (!isset($cols[strtolower($col)])) {
            return false;
        }
    }
    return true;
}

function manager_course_candidates(): array {
    return [
        ['table' => 'manager_courses', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => null, 'role_value' => null],
        ['table' => 'course_staff', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'course_roles', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'enrollments', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'user_courses', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
    ];
}

function enrollment_candidates(): array {
    return [
        ['table' => 'enrollments', 'user_col' => 'user_id', 'course_col' => 'course_id'],
        ['table' => 'student_courses', 'user_col' => 'user_id', 'course_col' => 'course_id'],
        ['table' => 'course_users', 'user_col' => 'user_id', 'course_col' => 'course_id'],
        ['table' => 'user_courses', 'user_col' => 'user_id', 'course_col' => 'course_id'],
    ];
}

function fetch_manager_course_ids(PDO $pdo, int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $ids = [];
    foreach (manager_course_candidates() as $map) {
        $table = $map['table'];
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $need = [$map['user_col'], $map['course_col']];
        if ($map['role_col']) {
            $need[] = $map['role_col'];
        }
        if (!table_has_columns($pdo, $table, $need)) {
            continue;
        }

        $sql = "SELECT DISTINCT CAST(`{$map['course_col']}` AS UNSIGNED) AS cid FROM `{$table}` WHERE `{$map['user_col']}` = :uid";
        $args = [':uid' => $userId];
        if ($map['role_col'] && $map['role_value'] !== null) {
            $sql .= " AND LOWER(`{$map['role_col']}`) = LOWER(:role)";
            $args[':role'] = $map['role_value'];
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $ids[(int)$cid] = true;
        }
    }

    $cache[$userId] = array_keys($ids);
    sort($cache[$userId]);
    return $cache[$userId];
}

function assert_manager_controls_course(PDO $pdo, int $userId, int $courseId): void {
    if ($courseId <= 0) {
        json_out(['error' => 'invalid_course', 'message' => 'course_id required'], 400);
    }
    $courses = fetch_manager_course_ids($pdo, $userId);
    if (!in_array($courseId, $courses, true)) {
        json_out(['error' => 'forbidden', 'message' => 'not enrolled as manager for this course'], 403);
    }
}

function resolve_enrollment_mapping(PDO $pdo): ?array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    foreach (enrollment_candidates() as $map) {
        $table = $map['table'];
        if (!table_exists($pdo, $table)) {
            continue;
        }
        if (!table_has_columns($pdo, $table, [$map['user_col'], $map['course_col']])) {
            continue;
        }
        $cache = $map;
        return $cache;
    }
    return null;
}

function course_enrollment_user_ids(PDO $pdo, int $courseId): array {
    $map = resolve_enrollment_mapping($pdo);
    if (!$map) {
        return [];
    }
    $sql = "SELECT DISTINCT CAST(`{$map['user_col']}` AS UNSIGNED) AS uid FROM `{$map['table']}` WHERE `{$map['course_col']}` = :cid";
    $st = $pdo->prepare($sql);
    $st->execute([':cid' => $courseId]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $ids[] = (int)$uid;
    }
    return $ids;
}

function enroll_user_in_course(PDO $pdo, int $userId, int $courseId): void {
    $map = resolve_enrollment_mapping($pdo);
    if (!$map) {
        json_out(['error' => 'unsupported', 'message' => 'No enrollment table found'], 500);
    }
    $sql = "INSERT INTO `{$map['table']}` (`{$map['course_col']}`, `{$map['user_col']}`) VALUES (:cid, :uid)
            ON DUPLICATE KEY UPDATE `{$map['user_col']}` = `{$map['user_col']}`";
    $st = $pdo->prepare($sql);
    $st->execute([':cid' => $courseId, ':uid' => $userId]);
}

function unenroll_user_from_course(PDO $pdo, int $userId, int $courseId): bool {
    $map = resolve_enrollment_mapping($pdo);
    if (!$map) {
        json_out(['error' => 'unsupported', 'message' => 'No enrollment table found'], 500);
    }
    $sql = "DELETE FROM `{$map['table']}` WHERE `{$map['course_col']}` = :cid AND `{$map['user_col']}` = :uid";
    $st = $pdo->prepare($sql);
    $st->execute([':cid' => $courseId, ':uid' => $userId]);
    return $st->rowCount() > 0;
}

function room_course_id(PDO $pdo, int $roomId): ?int {
    if ($roomId <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($roomId, $cache)) {
        return $cache[$roomId];
    }
    if (!table_exists($pdo, 'rooms') || !table_has_columns($pdo, 'rooms', ['room_id', 'course_id'])) {
        $cache[$roomId] = null;
        return null;
    }
    $st = $pdo->prepare('SELECT CAST(course_id AS UNSIGNED) FROM rooms WHERE room_id = :rid LIMIT 1');
    $st->execute([':rid' => $roomId]);
    $cid = $st->fetchColumn();
    $cache[$roomId] = $cid !== false ? (int)$cid : null;
    return $cache[$roomId];
}

function queue_room_course(PDO $pdo, int $queueId): ?array {
    if ($queueId <= 0) {
        return null;
    }
    if (!table_exists($pdo, 'queues') || !table_has_columns($pdo, 'queues', ['queue_id', 'room_id'])) {
        return null;
    }
    $sql = 'SELECT q.room_id, CAST(r.course_id AS UNSIGNED) AS course_id
            FROM queues q
            JOIN rooms r ON r.room_id = q.room_id
            WHERE q.queue_id = :qid
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([':qid' => $queueId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'room_id' => (int)$row['room_id'],
        'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
    ];
}

/**
 * Delete a queue and any related records that reference it.
 */
function delete_queue_and_dependents(PDO $pdo, int $queueId): bool {
    if ($queueId <= 0) {
        return false;
    }

    $cascadeTables = [
        ['queue_entries', 'queue_id'],
        ['ta_assignments', 'queue_id'],
        ['queue_staff', 'queue_id'],
        ['queue_tas', 'queue_id'],
        ['queue_permissions', 'queue_id'],
        ['queue_users', 'queue_id'],
        ['queue_members', 'queue_id'],
    ];

    foreach ($cascadeTables as [$table, $col]) {
        if (!table_exists($pdo, $table) || !table_has_columns($pdo, $table, [$col])) {
            continue;
        }
        $st = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$col}` = :qid");
        $st->execute([':qid' => $queueId]);
    }

    if (!table_exists($pdo, 'queues') || !table_has_columns($pdo, 'queues', ['queue_id'])) {
        return false;
    }

    $st = $pdo->prepare('DELETE FROM queues WHERE queue_id = :qid LIMIT 1');
    $st->execute([':qid' => $queueId]);
    return $st->rowCount() > 0;
}

/**
 * Delete a room, its queues, and any related queue/room records.
 */
function delete_room_and_dependents(PDO $pdo, int $roomId): bool {
    if ($roomId <= 0) {
        return false;
    }

    if (!table_exists($pdo, 'rooms') || !table_has_columns($pdo, 'rooms', ['room_id'])) {
        return false;
    }

    if (table_exists($pdo, 'queues') && table_has_columns($pdo, 'queues', ['queue_id', 'room_id'])) {
        $queues = $pdo->prepare('SELECT queue_id FROM queues WHERE room_id = :rid');
        $queues->execute([':rid' => $roomId]);
        foreach ($queues->fetchAll(PDO::FETCH_COLUMN) as $queueId) {
            delete_queue_and_dependents($pdo, (int)$queueId);
        }
    }

    $roomTables = [
        ['room_staff', 'room_id'],
        ['room_tas', 'room_id'],
        ['room_users', 'room_id'],
    ];
    foreach ($roomTables as [$table, $col]) {
        if (!table_exists($pdo, $table) || !table_has_columns($pdo, $table, [$col])) {
            continue;
        }
        $st = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$col}` = :rid");
        $st->execute([':rid' => $roomId]);
    }

    $st = $pdo->prepare('DELETE FROM rooms WHERE room_id = :rid LIMIT 1');
    $st->execute([':rid' => $roomId]);
    return $st->rowCount() > 0;
}

function users_for_course(PDO $pdo, int $courseId): array {
    $ids = course_enrollment_user_ids($pdo, $courseId);
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT user_id, name, email FROM users WHERE user_id IN ('.$placeholders.') ORDER BY name';
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $rows = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'name'    => $row['name'] ?? '',
            'email'   => $row['email'] ?? '',
        ];
    }
    return $rows;
}
