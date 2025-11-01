<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$user = require_login();
$pdo  = db();

if (!is_admin($pdo, $user)) {
    json_out(['error' => 'forbidden'], 403);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $hasDescription = table_has_column($pdo, 'courses', 'description');

    if ($method === 'GET') {
        $sql = $hasDescription
            ? "SELECT CAST(course_id AS UNSIGNED) AS course_id, name, COALESCE(description, '') AS description FROM courses ORDER BY course_id"
            : "SELECT CAST(course_id AS UNSIGNED) AS course_id, name, '' AS description FROM courses ORDER BY course_id";
        $courses = $pdo->query($sql)->fetchAll();
        json_out(['courses' => $courses]);
    }

    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = strtolower((string)($input['action'] ?? ''));

        if (!in_array($action, ['create', 'rename', 'delete'], true)) {
            json_out(['error' => 'unknown action'], 400);
        }

        if ($action === 'create') {
            $name = trim((string)($input['name'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            if ($name === '') {
                json_out(['error' => 'name is required'], 400);
            }
            if (strlen($name) > 255) {
                $name = substr($name, 0, 255);
            }
            if (strlen($description) > 2000) {
                $description = substr($description, 0, 2000);
            }

            if ($hasDescription) {
                $stmt = $pdo->prepare('INSERT INTO courses (name, description) VALUES (:name, :description)');
                $stmt->execute([':name' => $name, ':description' => $description]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO courses (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
            }

            $courseId = (int)$pdo->lastInsertId();
            $course   = fetch_course($pdo, $courseId, $hasDescription);
            json_out(['success' => true, 'course' => $course]);
        }

        $courseId = (int)($input['course_id'] ?? 0);
        if ($courseId <= 0) {
            json_out(['error' => 'course_id is required'], 400);
        }

        if ($action === 'rename') {
            $name = trim((string)($input['name'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            if ($name === '') {
                json_out(['error' => 'name is required'], 400);
            }
            if (strlen($name) > 255) {
                $name = substr($name, 0, 255);
            }
            if (strlen($description) > 2000) {
                $description = substr($description, 0, 2000);
            }

            if ($hasDescription) {
                $sql = 'UPDATE courses SET name = :name, description = :description WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':name' => $name, ':description' => $description, ':cid' => $courseId]);
            } else {
                $sql = 'UPDATE courses SET name = :name WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':name' => $name, ':cid' => $courseId]);
            }

            $course = fetch_course($pdo, $courseId, $hasDescription);
            json_out(['success' => true, 'course' => $course]);
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1');
            $stmt->execute([':cid' => $courseId]);
            json_out(['success' => true, 'deleted' => $courseId]);
        }
    }

    json_out(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

function is_admin(PDO $pdo, array $user): bool
{
    $roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;
    if ($roleId <= 0) {
        return false;
    }
    static $cache = [];
    if (!array_key_exists($roleId, $cache)) {
        try {
            $stmt = $pdo->prepare('SELECT LOWER(name) FROM roles WHERE role_id = :rid LIMIT 1');
            $stmt->execute([':rid' => $roleId]);
            $cache[$roleId] = strtolower((string)$stmt->fetchColumn());
        } catch (Throwable $e) {
            $cache[$roleId] = '';
        }
    }
    return $cache[$roleId] === 'admin';
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $sql = 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (bool)$stmt->fetchColumn();
}

function fetch_course(PDO $pdo, int $courseId, bool $hasDescription): array
{
    if ($courseId <= 0) {
        return [];
    }
    if ($hasDescription) {
        $sql = "SELECT CAST(course_id AS UNSIGNED) AS course_id, name, COALESCE(description, '') AS description FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1";
    } else {
        $sql = "SELECT CAST(course_id AS UNSIGNED) AS course_id, name, '' AS description FROM courses WHERE course_id = CAST(:cid AS UNSIGNED) LIMIT 1";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $courseId]);
    $row = $stmt->fetch();
    return $row ? $row : [];
}
