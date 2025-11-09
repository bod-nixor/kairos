<?php
declare(strict_types=1);

require_once __DIR__.'/../_helpers.php';

/**
 * Ensure the current session belongs to a TA user and return [$pdo, $user].
 */
function require_ta_user(): array {
    $user = require_login();
    $pdo  = db();

    require_role_or_higher($pdo, $user, 'ta');

    return [$pdo, $user];
}

function ta_user_rank(PDO $pdo, int $userId): int {
    if ($userId <= 0) {
        return 0;
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $st = $pdo->prepare('SELECT role_id FROM users WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $roleId = $st->fetchColumn();
        if ($roleId === false) {
            return $cache[$userId] = 0;
        }
        return $cache[$userId] = user_role_rank($pdo, ['role_id' => (int)$roleId]);
    } catch (Throwable $e) {
        return $cache[$userId] = 0;
    }
}

/**
 * Determine whether the provided session user has the TA role.
 */
function user_is_ta(PDO $pdo, array $user): bool {
    return user_role_at_least($pdo, $user, 'ta');
}

/**
 * Check whether the TA is linked to the provided course id.
 */
function ta_has_course(PDO $pdo, int $taUserId, int $courseId): bool {
    if ($courseId <= 0 || $taUserId <= 0) return false;

    $rank = ta_user_rank($pdo, $taUserId);
    if ($rank >= role_rank('admin')) {
        return true;
    }

    $managerCourseIds = [];
    if ($rank >= role_rank('manager')) {
        $managerCourseIds = ta_manager_course_ids($pdo, $taUserId);
        if (in_array($courseId, $managerCourseIds, true)) {
            return true;
        }
    }

    if ($rank < role_rank('ta')) {
        return false;
    }

    static $taTableCache = [];
    $mappings = [
        ['table' => 'ta_courses',     'ta_col' => 'ta_user_id', 'course_col' => 'course_id'],
        ['table' => 'course_tas',     'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'ta_enrollments', 'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'staff_courses',  'ta_col' => 'user_id',    'course_col' => 'course_id'],
    ];

    foreach ($mappings as $map) {
        $cacheKey = strtolower($map['table']).'|'.$map['ta_col'].'|'.$map['course_col'];
        if (!array_key_exists($cacheKey, $taTableCache)) {
            $taTableCache[$cacheKey] = ta_table_has_columns($pdo, $map['table'], [$map['ta_col'], $map['course_col']]);
        }
        if (!$taTableCache[$cacheKey]) {
            continue;
        }

        $sql = "SELECT 1 FROM `{$map['table']}` WHERE `{$map['ta_col']}` = :uid AND `{$map['course_col']}` = :cid LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $taUserId, ':cid' => $courseId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }

    return false;
}

/**
 * Helper to fetch all courses a TA (or elevated role) is linked with.
 */
function ta_courses(PDO $pdo, int $taUserId): array {
    $rank = ta_user_rank($pdo, $taUserId);

    if ($rank >= role_rank('admin')) {
        if (!ta_table_has_columns($pdo, 'courses', ['course_id', 'name'])) {
            return [];
        }
        $st = $pdo->prepare('SELECT CAST(course_id AS UNSIGNED) AS course_id, name FROM courses ORDER BY name');
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $coursesById = [];

    $appendCourses = static function(array $rows) use (&$coursesById): void {
        foreach ($rows as $row) {
            if (!isset($row['course_id'])) {
                continue;
            }
            $courseId = (int)$row['course_id'];
            if ($courseId <= 0) {
                continue;
            }
            $coursesById[$courseId] = [
                'course_id' => $courseId,
                'name'      => isset($row['name']) ? (string)$row['name'] : '',
            ];
        }
    };

    $managerCourses = [];
    if ($rank >= role_rank('manager')) {
        $managerCourses = ta_manager_course_ids($pdo, $taUserId);
        if ($managerCourses) {
            $rows = ta_fetch_courses_by_ids($pdo, $managerCourses);
            if ($rows) {
                $appendCourses($rows);
            }
        }
    }

    if ($rank < role_rank('ta')) {
        if (!$coursesById) {
            return [];
        }
        $courses = array_values($coursesById);
        usort($courses, static fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        return $courses;
    }

    $mappings = [
        ['table' => 'ta_courses',     'ta_col' => 'ta_user_id', 'course_col' => 'course_id'],
        ['table' => 'course_tas',     'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'ta_enrollments', 'ta_col' => 'user_id',    'course_col' => 'course_id'],
        ['table' => 'staff_courses',  'ta_col' => 'user_id',    'course_col' => 'course_id'],
    ];

    foreach ($mappings as $map) {
        if (!ta_table_has_columns($pdo, $map['table'], [$map['ta_col'], $map['course_col']])) {
            continue;
        }
        $sql = "SELECT c.course_id, c.name
                FROM courses c
                JOIN `{$map['table']}` l ON l.`{$map['course_col']}` = c.course_id
                WHERE l.`{$map['ta_col']}` = :uid
                GROUP BY c.course_id, c.name
                ORDER BY c.name";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $taUserId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $appendCourses($rows);
            break;
        }
    }

    if (!$coursesById) {
        return [];
    }

    $courses = array_values($coursesById);
    usort($courses, static fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

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

function ta_table_has_columns(PDO $pdo, string $table, array $columns): bool {
    if (!table_exists($pdo, $table)) {
        return false;
    }

    static $cache = [];
    $normalized = array_map(static fn($col) => strtolower((string)$col), $columns);
    $key = strtolower($table).'|'.implode(',', $normalized);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'SELECT COUNT(*) FROM information_schema.COLUMNS'
             . ' WHERE TABLE_SCHEMA = DATABASE()'
             . '   AND TABLE_NAME = ?'
             . "   AND COLUMN_NAME IN ($placeholders)";
        $args = array_merge([$table], $columns);
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $count = (int)$st->fetchColumn();
        return $cache[$key] = ($count === count($columns));
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function ta_manager_course_mappings(): array {
    return [
        ['table' => 'manager_courses', 'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => null, 'role_value' => null],
        ['table' => 'course_staff',    'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'course_roles',    'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'enrollments',     'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
        ['table' => 'user_courses',    'user_col' => 'user_id', 'course_col' => 'course_id', 'role_col' => 'role', 'role_value' => 'manager'],
    ];
}

function ta_manager_course_ids(PDO $pdo, int $userId): array {
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $ids = [];
    foreach (ta_manager_course_mappings() as $map) {
        $columns = [$map['user_col'], $map['course_col']];
        if ($map['role_col']) {
            $columns[] = $map['role_col'];
        }
        if (!ta_table_has_columns($pdo, $map['table'], $columns)) {
            continue;
        }

        $sql = "SELECT DISTINCT CAST(`{$map['course_col']}` AS UNSIGNED)"
             . " FROM `{$map['table']}`"
             . " WHERE `{$map['user_col']}` = :uid";
        $args = [':uid' => $userId];
        if ($map['role_col'] && $map['role_value'] !== null) {
            $sql .= " AND LOWER(`{$map['role_col']}`) = LOWER(:role)";
            $args[':role'] = $map['role_value'];
        }

        $st = $pdo->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            if ($cid === null) {
                continue;
            }
            $ids[(int)$cid] = true;
        }
    }

    $cache[$userId] = array_keys($ids);
    sort($cache[$userId]);
    return $cache[$userId];
}

function ta_fetch_courses_by_ids(PDO $pdo, array $courseIds): array {
    $ids = array_values(array_unique(array_map('intval', $courseIds)));
    if (!$ids) {
        return [];
    }
    if (!ta_table_has_columns($pdo, 'courses', ['course_id', 'name'])) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT CAST(course_id AS UNSIGNED) AS course_id, name'
         . ' FROM courses'
         . " WHERE course_id IN ($placeholders)"
         . ' ORDER BY name';
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
