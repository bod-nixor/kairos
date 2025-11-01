<?php
declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

/**
 * Ensure the current session belongs to a TA user and return [$pdo, $user].
 */
function require_ta_user(): array {
    $user = require_login();
    $pdo  = db();

    if (!user_is_ta($pdo, $user)) {
        json_out(['error' => 'forbidden', 'message' => 'TA access required'], 403);
    }

    return [$pdo, $user];
}

/**
 * Determine whether the provided session user has the TA role.
 */
function user_is_ta(PDO $pdo, array $user): bool {
    static $cache = [];
    $roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;
    if (!$roleId) return false;

    if (array_key_exists($roleId, $cache)) {
        return $cache[$roleId];
    }

    $st = $pdo->prepare('SELECT LOWER(name) FROM roles WHERE role_id = :rid LIMIT 1');
    $st->execute([':rid' => $roleId]);
    $role = $st->fetchColumn();
    $cache[$roleId] = is_string($role) && trim($role) === 'ta';
    return $cache[$roleId];
}

/**
 * Check whether the TA is linked to the provided course id.
 */
function ta_has_course(PDO $pdo, int $taUserId, int $courseId): bool {
    if ($courseId <= 0) return false;

    static $tableCache = null;
    if ($tableCache === null) {
        $tableCache = [];
        $tables = ['ta_courses', 'course_tas', 'ta_enrollments', 'staff_courses'];
        $st = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).')');
        $st->execute($tables);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $tableCache[strtolower($t)] = true;
        }
    }

    $mappings = [
        ['table' => 'ta_courses',     'ta_col' => 'ta_user_id', 'course_col' => 'course_id'],
        ['table' => 'course_tas',     'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'ta_enrollments', 'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'staff_courses',  'ta_col' => 'user_id',    'course_col' => 'course_id'],
    ];

    foreach ($mappings as $map) {
        if (empty($tableCache[strtolower($map['table'])])) continue;
        $sql = "SELECT 1 FROM `{$map['table']}` WHERE `{$map['ta_col']}` = :uid AND `{$map['course_col']}` = :cid LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $taUserId, ':cid' => $courseId]);
        if ($st->fetchColumn()) return true;
    }

    // If no mapping matched, fall back to false.
    return false;
}

/**
 * Helper to fetch all courses a TA is linked with.
 */
function ta_courses(PDO $pdo, int $taUserId): array {
    $mappings = [
        ['table' => 'ta_courses',     'ta_col' => 'ta_user_id', 'course_col' => 'course_id'],
        ['table' => 'course_tas',     'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'ta_enrollments', 'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'staff_courses',  'ta_col' => 'user_id',    'course_col' => 'course_id'],
    ];

    $courses = [];
    foreach ($mappings as $map) {
        if (!table_exists($pdo, $map['table'])) continue;
        $sql = "SELECT c.course_id, c.name
                FROM courses c
                JOIN `{$map['table']}` l ON l.`{$map['course_col']}` = c.course_id
                WHERE l.`{$map['ta_col']}` = :uid
                GROUP BY c.course_id, c.name
                ORDER BY c.name";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $taUserId]);
        $rows = $st->fetchAll();
        if ($rows) { $courses = $rows; break; }
    }
    return $courses;
}

function table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1');
    $st->execute([':t' => $table]);
    $cache[$key] = (bool)$st->fetchColumn();
    return $cache[$key];
}

/**
 * Fetch the currently active assignment for a queue, if any.
 */
function ta_active_assignment(PDO $pdo, int $queueId): ?array {
    if ($queueId <= 0) return null;
    if (!table_exists($pdo, 'ta_assignments')) return null;

    $columns = ta_assignment_columns($pdo);
    $selectCols = ['ta.ta_user_id', 'ta.student_user_id', 'ta.queue_id', 'ta.started_at'];
    if ($columns['ta_assignment_id']) {
        $selectCols[] = 'ta.ta_assignment_id';
    }
    if ($columns['ended_at']) {
        $selectCols[] = 'ta.ended_at';
    } elseif ($columns['completed_at']) {
        $selectCols[] = 'ta.completed_at';
    } elseif ($columns['finished_at']) {
        $selectCols[] = 'ta.finished_at';
    }

    $nullChecks = [];
    if ($columns['ended_at']) {
        $nullChecks[] = 'ta.ended_at IS NULL';
    }
    if ($columns['completed_at']) {
        $nullChecks[] = 'ta.completed_at IS NULL';
    }
    if ($columns['finished_at']) {
        $nullChecks[] = 'ta.finished_at IS NULL';
    }

    $where = 'ta.queue_id = :qid';
    if ($nullChecks) {
        $where .= ' AND ' . implode(' AND ', $nullChecks);
    }

    $sql = 'SELECT ' . implode(', ', $selectCols) . ', ' .
           'taUsers.name AS ta_name, stu.name AS student_name ' .
           'FROM ta_assignments ta ' .
           'JOIN users taUsers ON taUsers.user_id = ta.ta_user_id ' .
           'JOIN users stu ON stu.user_id = ta.student_user_id ' .
           'WHERE ' . $where . ' ' .
           'ORDER BY ta.started_at DESC LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([':qid' => $queueId]);
    $row = $st->fetch();
    if (!$row) return null;

    $finished = null;
    foreach (['ended_at', 'completed_at', 'finished_at'] as $col) {
        if (isset($row[$col])) { $finished = $row[$col]; break; }
    }
    if ($finished !== null && $finished !== '0000-00-00 00:00:00' && $finished !== '' ) {
        return null; // already completed
    }

    return [
        'ta_assignment_id' => isset($row['ta_assignment_id']) ? (int)$row['ta_assignment_id'] : null,
        'ta_user_id'       => (int)$row['ta_user_id'],
        'ta_name'          => $row['ta_name'] ?? '',
        'student_user_id'  => (int)$row['student_user_id'],
        'student_name'     => $row['student_name'] ?? '',
        'queue_id'         => (int)$row['queue_id'],
        'started_at'       => $row['started_at'] ?? null,
    ];
}

function ta_assignment_columns(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'ta_assignment_id' => false,
        'ended_at'         => false,
        'completed_at'     => false,
        'finished_at'      => false,
    ];
    if (!table_exists($pdo, 'ta_assignments')) return $cache;

    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $st->execute([':t' => 'ta_assignments']);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col) {
        $lc = strtolower($col);
        if (array_key_exists($lc, $cache)) {
            $cache[$lc] = true;
        }
    }
    return $cache;
}

function ta_assignment_primary_key(PDO $pdo): ?string {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!table_exists($pdo, 'ta_assignments')) { $cache = null; return $cache; }

    $sql = "SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'ta_assignments'
              AND COLUMN_KEY IN ('PRI','UNI')
            ORDER BY FIELD(COLUMN_KEY,'PRI','UNI'), ORDINAL_POSITION
            LIMIT 1";
    $st = $pdo->query($sql);
    $col = $st ? $st->fetchColumn() : false;
    if ($col) { $cache = $col; return $cache; }

    // Fallback common column name
    $columns = ta_assignment_columns($pdo);
    if ($columns['ta_assignment_id']) { $cache = 'ta_assignment_id'; return $cache; }

    $cache = null;
    return $cache;
}

function log_change(PDO $pdo, string $channel, int $refId, ?int $courseId = null): void {
    if (!table_exists($pdo, 'change_log')) return;
    $st = $pdo->prepare('INSERT INTO change_log (channel, ref_id, course_id, created_at) VALUES (:ch, :ref, :cid, NOW())');
    $st->execute([
        ':ch'  => $channel,
        ':ref' => $refId,
        ':cid' => $courseId,
    ]);
}
