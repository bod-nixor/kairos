<?php
declare(strict_types=1);

require_once __DIR__.'/ta/common.php';

/**
 * Attempt to insert a row into change_log to notify SSE listeners.
 * Silently ignores errors (e.g. table missing) because the API should still work.
 */
function emit_change(PDO $pdo, string $channel, ?int $refId = null, ?int $courseId = null, ?array $payload = null): void
{
    static $capabilities = null;
    if ($capabilities === null) {
        $capabilities = [
            'payload' => false,
        ];
        try {
            $check = $pdo->prepare(
                "SELECT 1 FROM information_schema.COLUMNS".
                " WHERE TABLE_SCHEMA = DATABASE()".
                "   AND TABLE_NAME = 'change_log'".
                "   AND COLUMN_NAME = 'payload_json' LIMIT 1"
            );
            if ($check->execute() && $check->fetchColumn()) {
                $capabilities['payload'] = true;
            }
        } catch (Throwable $e) {
            // leave defaults – table may not exist or be inaccessible
        }
    }

    $columns = ['channel', 'ref_id', 'course_id'];
    $placeholders = [':channel', ':ref_id', ':course_id'];
    $params = [
        ':channel'   => $channel,
        ':ref_id'    => $refId,
        ':course_id' => $courseId,
    ];

    if ($payload !== null && $capabilities['payload']) {
        $columns[] = 'payload_json';
        $placeholders[] = ':payload_json';
        $params[':payload_json'] = json_encode($payload);
    }

    $sql = 'INSERT INTO change_log (' . implode(',', $columns) . ')
            VALUES (' . implode(',', $placeholders) . ')';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Throwable $e) {
        // Swallow – missing table/columns should not break primary request flow.
    }
}

/**
 * Retrieve cached metadata about a queue (room/course relationship).
 */
function queue_meta(PDO $pdo, int $queueId): array
{
    static $cache = [];
    if (isset($cache[$queueId])) {
        return $cache[$queueId];
    }

    $meta = [
        'queue_id'  => $queueId,
        'room_id'   => null,
        'course_id' => null,
    ];

    $queries = [
        "SELECT CAST(queue_id AS UNSIGNED) AS queue_id,
                CAST(room_id AS UNSIGNED)  AS room_id,
                CAST(course_id AS UNSIGNED) AS course_id
         FROM queues_info
         WHERE queue_id = :qid
         LIMIT 1",
        "SELECT q.queue_id,
                q.room_id,
                r.course_id
         FROM queues q
         LEFT JOIN rooms r ON r.room_id = q.room_id
         WHERE q.queue_id = :qid
         LIMIT 1",
    ];

    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':qid' => $queueId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['queue_id'])) {
                    $meta['queue_id'] = (int)$row['queue_id'];
                }
                if (array_key_exists('room_id', $row) && $row['room_id'] !== null) {
                    $meta['room_id'] = (int)$row['room_id'];
                }
                if (array_key_exists('course_id', $row) && $row['course_id'] !== null) {
                    $meta['course_id'] = (int)$row['course_id'];
                }
                break;
            }
        } catch (Throwable $e) {
            // try next strategy
        }
    }

    return $cache[$queueId] = $meta;
}

function queue_student_name(PDO $pdo, int $userId): string
{
    static $cache = [];
    if ($userId <= 0) {
        return '';
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $st = $pdo->prepare('SELECT name FROM users WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $name = $st->fetchColumn();
        if ($name === false || $name === null) {
            $name = '';
        }
        return $cache[$userId] = (string)$name;
    } catch (Throwable $e) {
        return $cache[$userId] = '';
    }
}

function queue_snapshot_data(PDO $pdo, int $queueId): array
{
    $students = [];
    $waitingCount = 0;

    try {
        $sql = 'SELECT qe.user_id, qe.`timestamp` AS joined_at, u.name '
             . 'FROM queue_entries qe '
             . 'LEFT JOIN users u ON u.user_id = qe.user_id '
             . 'WHERE qe.queue_id = :qid '
             . 'ORDER BY qe.`timestamp`';
        $st = $pdo->prepare($sql);
        $st->execute([':qid' => $queueId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $id = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $students[] = [
                'id'        => $id,
                'name'      => isset($row['name']) ? (string)$row['name'] : '',
                'status'    => 'waiting',
                'joined_at' => isset($row['joined_at']) ? (string)$row['joined_at'] : null,
            ];
        }
        $waitingCount = count($students);
    } catch (Throwable $e) {
        // ignore, fall back to empty snapshot
    }

    $serving = null;
    try {
        $serving = ta_active_assignment($pdo, $queueId);
    } catch (Throwable $e) {
        $serving = null;
    }
    if ($serving) {
        $servingStudentId = isset($serving['student_user_id']) ? (int)$serving['student_user_id'] : 0;
        if ($servingStudentId > 0) {
            $students = array_values(array_filter($students, static function(array $student) use ($servingStudentId): bool {
                return ($student['id'] ?? 0) !== $servingStudentId;
            }));
            $students[] = [
                'id'        => $servingStudentId,
                'name'      => $serving['student_name'] ?? '',
                'status'    => 'serving',
                'joined_at' => $serving['started_at'] ?? null,
            ];
        }
    }

    return [
        'students'      => $students,
        'serving'       => $serving,
        'waiting_count' => $waitingCount,
        'updated_at'    => time(),
    ];
}

function queue_ws_notify(PDO $pdo, int $queueId, string $change, array $options = []): void
{
    $meta = queue_meta($pdo, $queueId);
    $roomId = $meta['room_id'] ?? null;
    $courseId = $meta['course_id'] ?? null;

    $studentId = isset($options['student_id']) ? (int)$options['student_id'] : null;
    $studentName = isset($options['student_name']) ? (string)$options['student_name'] : null;
    if ($studentId && ($studentName === null || $studentName === '')) {
        $studentName = queue_student_name($pdo, $studentId);
    }

    $snapshot = empty($options['skip_snapshot']) ? queue_snapshot_data($pdo, $queueId) : null;

    $servingTaId = array_key_exists('serving_ta_id', $options)
        ? $options['serving_ta_id']
        : ($snapshot['serving']['ta_user_id'] ?? null);
    $servingTaName = array_key_exists('serving_ta_name', $options)
        ? $options['serving_ta_name']
        : ($snapshot['serving']['ta_name'] ?? null);
    $servingStudentId = array_key_exists('serving_student_id', $options)
        ? $options['serving_student_id']
        : ($snapshot['serving']['student_user_id'] ?? null);
    $servingStudentName = array_key_exists('serving_student_name', $options)
        ? $options['serving_student_name']
        : ($snapshot['serving']['student_name'] ?? null);

    $payloadSnapshot = null;
    if ($snapshot) {
        $students = [];
        foreach ($snapshot['students'] ?? [] as $student) {
            if (!is_array($student) || !isset($student['id'])) {
                continue;
            }
            $status = $student['status'] ?? 'waiting';
            if (!in_array($status, ['waiting', 'serving', 'done'], true)) {
                $status = 'waiting';
            }
            $students[] = [
                'id'       => (int)$student['id'],
                'name'     => isset($student['name']) ? (string)$student['name'] : '',
                'status'   => $status,
                'joinedAt' => $student['joined_at'] ?? null,
            ];
        }
        if (!empty($options['extra_students']) && is_array($options['extra_students'])) {
            $existingIds = [];
            foreach ($students as $student) {
                $existingIds[$student['id']] = true;
            }
            foreach ($options['extra_students'] as $extra) {
                if (!is_array($extra) || !isset($extra['id'])) {
                    continue;
                }
                $extraId = (int)$extra['id'];
                if ($extraId <= 0 || isset($existingIds[$extraId])) {
                    continue;
                }
                $status = $extra['status'] ?? 'waiting';
                if (!in_array($status, ['waiting', 'serving', 'done'], true)) {
                    $status = 'waiting';
                }
                $students[] = [
                    'id'       => $extraId,
                    'name'     => isset($extra['name']) ? (string)$extra['name'] : '',
                    'status'   => $status,
                    'joinedAt' => $extra['joined_at'] ?? null,
                ];
            }
        }
        $payloadSnapshot = [
            'students'  => $students,
            'updatedAt' => $snapshot['updated_at'] ?? time(),
        ];
    }

    $payload = [
        'roomId'             => $roomId,
        'queueId'            => $queueId,
        'change'             => $change,
        'student'            => $studentId ? ['id' => $studentId, 'name' => $studentName ?? ''] : null,
        'servingTaId'        => $servingTaId !== null ? (int)$servingTaId : null,
        'servingTaName'      => $servingTaName !== null ? (string)$servingTaName : null,
        'servingStudentId'   => $servingStudentId !== null ? (int)$servingStudentId : null,
        'servingStudentName' => $servingStudentName !== null ? (string)$servingStudentName : null,
        'snapshot'           => $payloadSnapshot,
    ];
    if ($snapshot && array_key_exists('waiting_count', $snapshot)) {
        $payload['waitingCount'] = (int)$snapshot['waiting_count'];
    }
    if (isset($options['assignment_id'])) {
        $payload['assignmentId'] = $options['assignment_id'];
    }

    ws_notify([
        'event'     => 'queue',
        'course_id' => $courseId,
        'room_id'   => $roomId,
        'ref_id'    => $options['ref_id'] ?? $queueId,
        'payload'   => $payload,
    ]);
}

/**
 * Determine whether a user has TA/staff permissions for a queue.
 *
 * The legacy schemas we integrate with are not consistent, so this helper
 * checks several common layouts before falling back to role-based checks.
 */
function user_can_manage_queue(PDO $pdo, int $userId, int $queueId): bool
{
    if ($userId <= 0 || $queueId <= 0) {
        return false;
    }

    $meta = queue_meta($pdo, $queueId);
    $roomId = $meta['room_id'] ?? null;
    $courseId = $meta['course_id'] ?? null;

    $tableHasColumns = function(string $table, array $columns) use ($pdo): bool {
        static $cache = [];
        $key = strtolower($table).'|'.implode(',', array_map('strtolower', $columns));
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS".
                   " WHERE TABLE_SCHEMA = DATABASE()".
                   "   AND TABLE_NAME = ?".
                   "   AND COLUMN_NAME IN ($placeholders)";
            $args = array_merge([$table], $columns);
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $count = (int)$st->fetchColumn();
            return $cache[$key] = ($count === count($columns));
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    };

    $checkSimpleMapping = function(string $table, string $refCol, string $userCol, int $refId) use ($pdo, $userId, $tableHasColumns): bool {
        if (!$tableHasColumns($table, [$refCol, $userCol])) {
            return false;
        }

        try {
            $sql = "SELECT 1 FROM `$table` WHERE `$refCol` = :ref AND `$userCol` = :uid LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':ref' => $refId, ':uid' => $userId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    };

    $checkRoleMapping = function(string $table, string $refCol, string $userCol, string $roleCol, int $refId, array $allowedRoles) use ($pdo, $userId, $tableHasColumns): bool {
        $columns = [$refCol, $userCol, $roleCol];
        if (!$tableHasColumns($table, $columns)) {
            return false;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
            $sql = "SELECT 1 FROM `$table`".
                   " WHERE `$refCol` = ? AND `$userCol` = ?".
                   "   AND LOWER(`$roleCol`) IN ($placeholders)".
                   " LIMIT 1";
            $args = array_merge([$refId, $userId], array_map('strtolower', $allowedRoles));
            $st = $pdo->prepare($sql);
            $st->execute($args);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    };

    $roleNames = ['ta', 'assistant', 'staff', 'instructor', 'teacher', 'admin', 'manager'];

    // Direct queue level assignments.
    $queueTables = [
        ['queue_staff', 'queue_id', 'user_id'],
        ['queue_tas', 'queue_id', 'user_id'],
        ['queue_permissions', 'queue_id', 'user_id'],
    ];
    foreach ($queueTables as [$table, $refCol, $userCol]) {
        if ($checkSimpleMapping($table, $refCol, $userCol, $queueId)) {
            return true;
        }
    }

    // Queue level mappings where a role flag determines the permission.
    $queueRoleTables = [
        ['queue_users', 'queue_id', 'user_id', 'role'],
        ['queue_members', 'queue_id', 'user_id', 'role'],
    ];
    foreach ($queueRoleTables as [$table, $refCol, $userCol, $roleCol]) {
        if ($checkRoleMapping($table, $refCol, $userCol, $roleCol, $queueId, $roleNames)) {
            return true;
        }
    }

    // Room level assignments (a room hosts a queue).
    if ($roomId) {
        $roomTables = [
            ['room_staff', 'room_id', 'user_id'],
            ['room_tas', 'room_id', 'user_id'],
        ];
        foreach ($roomTables as [$table, $refCol, $userCol]) {
            if ($checkSimpleMapping($table, $refCol, $userCol, (int)$roomId)) {
                return true;
            }
        }

        $roomRoleTables = [
            ['room_users', 'room_id', 'user_id', 'role'],
        ];
        foreach ($roomRoleTables as [$table, $refCol, $userCol, $roleCol]) {
            if ($checkRoleMapping($table, $refCol, $userCol, $roleCol, (int)$roomId, $roleNames)) {
                return true;
            }
        }
    }

    // Course level staff (queues belong to a course via room -> course).
    if ($courseId) {
        $courseTables = [
            ['course_staff', 'course_id', 'user_id'],
            ['course_tas', 'course_id', 'user_id'],
            ['courses_staff', 'course_id', 'user_id'],
            ['staff_courses', 'course_id', 'user_id'],
        ];
        foreach ($courseTables as [$table, $refCol, $userCol]) {
            if ($checkSimpleMapping($table, $refCol, $userCol, (int)$courseId)) {
                return true;
            }
        }

        $courseRoleTables = [
            ['course_users', 'course_id', 'user_id', 'role'],
            ['course_members', 'course_id', 'user_id', 'role'],
        ];
        foreach ($courseRoleTables as [$table, $refCol, $userCol, $roleCol]) {
            if ($checkRoleMapping($table, $refCol, $userCol, $roleCol, (int)$courseId, $roleNames)) {
                return true;
            }
        }
    }

    // Finally fall back to checking the user's global role assignment.
    try {
        $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
        $sql = "SELECT 1".
               " FROM users u".
               " JOIN roles r ON r.role_id = u.role_id".
               " WHERE u.user_id = ? AND LOWER(r.name) IN ($placeholders)".
               " LIMIT 1";
        $args = array_merge([$userId], array_map('strtolower', $roleNames));
        $st = $pdo->prepare($sql);
        $st->execute($args);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // Ignore and fall through to final false.
    }

    // Some installs store a boolean flag directly on the users table.
    try {
        if ($tableHasColumns('users', ['is_staff'])) {
            $st = $pdo->prepare("SELECT is_staff FROM users WHERE user_id = :uid LIMIT 1");
            $st->execute([':uid' => $userId]);
            $flag = $st->fetchColumn();
            if ($flag !== false && (int)$flag === 1) {
                return true;
            }
        }
        if ($tableHasColumns('users', ['is_admin'])) {
            $st = $pdo->prepare("SELECT is_admin FROM users WHERE user_id = :uid LIMIT 1");
            $st->execute([':uid' => $userId]);
            $flag = $st->fetchColumn();
            if ($flag !== false && (int)$flag === 1) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    return false;
}

/**
 * Try to compute an average handle time (in minutes) for a queue using several fallbacks.
 */
function queue_avg_handle_minutes(PDO $pdo, int $queueId): ?float
{
    static $strategies = null;
    static $availability = [];

    if ($strategies === null) {
        $strategies = [
            [
                'key' => 'queue_stats',
                'sql' => "SELECT avg_handle_minutes
                          FROM queue_stats
                          WHERE queue_id = :qid
                          ORDER BY updated_at DESC
                          LIMIT 1",
                'column' => 'avg_handle_minutes',
            ],
            [
                'key' => 'queue_metrics',
                'sql' => "SELECT AVG(handle_minutes) AS avg_handle_minutes
                          FROM queue_metrics
                          WHERE queue_id = :qid",
                'column' => 'avg_handle_minutes',
            ],
            [
                'key' => 'queue_sessions',
                'sql' => "SELECT AVG(TIMESTAMPDIFF(MINUTE, started_at, finished_at)) AS avg_handle_minutes
                          FROM queue_sessions
                          WHERE queue_id = :qid AND finished_at IS NOT NULL",
                'column' => 'avg_handle_minutes',
            ],
        ];
    }

    foreach ($strategies as $strategy) {
        $key = $strategy['key'];
        if (array_key_exists($key, $availability) && $availability[$key] === false) {
            continue;
        }

        try {
            $st = $pdo->prepare($strategy['sql']);
            if (!$st->execute([':qid' => $queueId])) {
                $availability[$key] = false;
                continue;
            }
            $value = null;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row[$strategy['column']])) {
                $value = $row[$strategy['column']];
            } elseif ($row === false) {
                $value = $st->fetchColumn();
            }

            if ($value !== null && $value !== false) {
                $availability[$key] = true;
                return (float)$value;
            }

            $availability[$key] = true; // query worked but returned null
        } catch (Throwable $e) {
            $availability[$key] = false; // table/columns missing – don't retry each time
        }
    }

    return null;
}
