<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

$user = require_login();
$pdo  = db();

require_role_or_higher($pdo, $user, 'admin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $schema = detect_assignment_schema($pdo);

    if ($method === 'GET') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if ($courseId <= 0) {
            json_out(['assignments' => []]);
        }
        $assignments = list_assignments($pdo, $schema, $courseId);
        json_out(['assignments' => $assignments]);
    }

    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = strtolower((string)($input['action'] ?? 'assign'));
        $courseId = (int)($input['course_id'] ?? 0);
        if ($courseId <= 0) {
            json_out(['error' => 'course_id is required'], 400);
        }

        if (!course_exists($pdo, $courseId)) {
            json_out(['error' => 'course not found'], 404);
        }

        if ($action === 'assign') {
            $roleName = strtolower(trim((string)($input['role'] ?? '')));
            if (!in_array($roleName, ['manager', 'ta'], true)) {
                json_out(['error' => 'role must be manager or ta'], 400);
            }

            $userId = (int)($input['user_id'] ?? 0);
            $email  = strtolower(trim((string)($input['email'] ?? '')));
            if ($userId <= 0 && $email !== '') {
                $userId = lookup_user_id_by_email($pdo, $email);
            }
            if ($userId <= 0) {
                json_out(['error' => 'user not found'], 404);
            }
            if (!user_exists($pdo, $userId)) {
                json_out(['error' => 'user not found'], 404);
            }

            $pdo->beginTransaction();
            try {
                delete_assignment($pdo, $schema, $courseId, $userId, null);
                insert_assignment($pdo, $schema, $courseId, $userId, $roleName);
                if ($roleName === 'ta') {
                    // Keep global role aligned with course-level TA assignments.
                    ensure_user_role_at_least($pdo, $userId, 'ta');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            json_out(['success' => true]);
        }

        if ($action === 'remove') {
            $roleName = strtolower(trim((string)($input['role'] ?? '')));
            $userId = (int)($input['user_id'] ?? 0);
            if ($userId <= 0) {
                json_out(['error' => 'user_id is required'], 400);
            }
            delete_assignment($pdo, $schema, $courseId, $userId, $roleName ?: null);
            json_out(['success' => true]);
        }

        json_out(['error' => 'unknown action'], 400);
    }

    json_out(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

function detect_assignment_schema(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $candidates = [
        ['table' => 'course_staff',      'course_col' => 'course_id', 'user_col' => 'user_id', 'role_col' => 'role',    'type' => 'string'],
        ['table' => 'course_roles',      'course_col' => 'course_id', 'user_col' => 'user_id', 'role_col' => 'role',    'type' => 'string'],
        ['table' => 'course_roles',      'course_col' => 'course_id', 'user_col' => 'user_id', 'role_col' => 'role_id', 'type' => 'role_id'],
        ['table' => 'course_user_roles', 'course_col' => 'course_id', 'user_col' => 'user_id', 'role_col' => 'role',    'type' => 'string'],
        ['table' => 'course_assignments','course_col' => 'course_id', 'user_col' => 'user_id', 'role_col' => 'role',    'type' => 'string'],
    ];

    foreach ($candidates as $candidate) {
        if (table_has_columns($pdo, $candidate['table'], [$candidate['course_col'], $candidate['user_col'], $candidate['role_col']])) {
            $cache = $candidate;
            return $cache;
        }
    }

    throw new RuntimeException('course role table not found');
}

function table_has_columns(PDO $pdo, string $table, array $columns): bool
{
    if (!$columns) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $params = array_merge([$table], $columns);
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() === count($columns);
}

function list_assignments(PDO $pdo, array $schema, int $courseId): array
{
    $table = db_quote_identifier($schema['table']);
    $courseCol = db_quote_identifier($schema['course_col']);
    $userCol = db_quote_identifier($schema['user_col']);
    $roleCol = db_quote_identifier($schema['role_col']);

    if ($schema['type'] === 'role_id') {
        $roleSelect = 'LOWER(r.name) AS role';
        $joinRole   = "LEFT JOIN roles r ON r.role_id = t.$roleCol";
    } else {
        $roleSelect = "LOWER(t.$roleCol) AS role";
        $joinRole   = '';
    }

    $sql = "SELECT CAST(t.$courseCol AS UNSIGNED) AS course_id,
                   CAST(t.$userCol AS UNSIGNED) AS user_id,
                   $roleSelect,
                   u.name,
                   u.email
            FROM $table t
            JOIN users u ON u.user_id = t.$userCol
            $joinRole
            WHERE t.$courseCol = CAST(:cid AS UNSIGNED)
            ORDER BY role, u.name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $courseId]);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
            'user_id'   => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'role'      => isset($row['role']) ? (string)$row['role'] : '',
            'name'      => $row['name'] ?? '',
            'email'     => $row['email'] ?? '',
        ];
    }, $rows ?: []);
}

function delete_assignment(PDO $pdo, array $schema, int $courseId, int $userId, ?string $roleName = null): void
{
    $table = db_quote_identifier($schema['table']);
    $courseCol = db_quote_identifier($schema['course_col']);
    $userCol = db_quote_identifier($schema['user_col']);
    $roleCol = db_quote_identifier($schema['role_col']);

    $sql = "DELETE FROM $table WHERE $courseCol = CAST(:cid AS UNSIGNED) AND $userCol = CAST(:uid AS UNSIGNED)";
    $params = [':cid' => $courseId, ':uid' => $userId];

    if ($roleName !== null) {
        if ($schema['type'] === 'role_id') {
            $roleId = get_role_id($pdo, $roleName);
            if ($roleId === null) {
                // Nothing to delete if role does not exist
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return;
            }
            $sql .= " AND $roleCol = CAST(:rid AS UNSIGNED)";
            $params[':rid'] = $roleId;
        } else {
            $sql .= " AND LOWER($roleCol) = LOWER(:role)";
            $params[':role'] = $roleName;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function insert_assignment(PDO $pdo, array $schema, int $courseId, int $userId, string $roleName): void
{
    $table = db_quote_identifier($schema['table']);
    $courseCol = db_quote_identifier($schema['course_col']);
    $userCol = db_quote_identifier($schema['user_col']);
    $roleCol = db_quote_identifier($schema['role_col']);

    if ($schema['type'] === 'role_id') {
        $roleId = ensure_role_id($pdo, $roleName);
        $sql = "INSERT INTO $table ($courseCol, $userCol, $roleCol) VALUES (CAST(:cid AS UNSIGNED), CAST(:uid AS UNSIGNED), CAST(:rid AS UNSIGNED))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $courseId, ':uid' => $userId, ':rid' => $roleId]);
    } else {
        $sql = "INSERT INTO $table ($courseCol, $userCol, $roleCol) VALUES (CAST(:cid AS UNSIGNED), CAST(:uid AS UNSIGNED), :role)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $courseId, ':uid' => $userId, ':role' => $roleName]);
    }
}

function db_quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ensure_role_id(PDO $pdo, string $roleName): int
{
    $stmt = $pdo->prepare('INSERT INTO roles (name) VALUES (:name) ON DUPLICATE KEY UPDATE role_id = LAST_INSERT_ID(role_id)');
    $stmt->execute([':name' => $roleName]);
    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $roleName]);
    $row = $stmt->fetchColumn();
    if ($row === false) {
        throw new RuntimeException('failed to resolve role id');
    }
    return (int)$row;
}

function get_role_id(PDO $pdo, string $roleName): ?int
{
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute([':name' => $roleName]);
    $row = $stmt->fetchColumn();
    if ($row === false) {
        return null;
    }
    return (int)$row;
}

function course_exists(PDO $pdo, int $courseId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1');
    $stmt->execute([':cid' => $courseId]);
    return (bool)$stmt->fetchColumn();
}

function user_exists(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE user_id = CAST(:uid AS UNSIGNED) LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function lookup_user_id_by_email(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetchColumn();
    return $row === false ? 0 : (int)$row;
}

function ensure_user_role_at_least(PDO $pdo, int $userId, string $roleName): void
{
    if ($userId <= 0) {
        return;
    }
    if (!table_has_columns($pdo, 'users', ['user_id', 'role_id']) || !table_has_columns($pdo, 'roles', ['role_id', 'name'])) {
        throw new RuntimeException('roles table not available');
    }

    $stmt = $pdo->prepare('SELECT u.role_id, LOWER(r.name) AS role_name
                           FROM users u
                           LEFT JOIN roles r ON r.role_id = u.role_id
                           WHERE u.user_id = :uid
                           LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('user not found');
    }

    $currentRank = role_rank((string)($row['role_name'] ?? ''));
    $targetRank = role_rank($roleName);
    if ($currentRank >= $targetRank) {
        return;
    }

    $roleId = ensure_role_id($pdo, $roleName);
    $update = $pdo->prepare('UPDATE users SET role_id = :rid WHERE user_id = :uid');
    $update->execute([':rid' => $roleId, ':uid' => $userId]);
}
