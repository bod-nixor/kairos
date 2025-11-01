<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = require_login();
$pdo  = db();
ensure_manager_role($pdo, $user);
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
if ($userId <= 0) {
    json_out(['error' => 'forbidden', 'message' => 'missing user id'], 403);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method_not_allowed'], 405);
}

if (!table_exists($pdo, 'rooms') || !table_has_columns($pdo, 'rooms', ['room_id', 'course_id', 'name'])) {
    json_out(['error' => 'unsupported', 'message' => 'rooms table not available'], 500);
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = strtolower((string)($payload['action'] ?? ''));
$courseId = isset($payload['course_id']) ? (int)$payload['course_id'] : 0;
$roomId   = isset($payload['room_id']) ? (int)$payload['room_id'] : 0;
$name     = trim((string)($payload['name'] ?? ''));

if (!in_array($action, ['create', 'rename', 'delete'], true)) {
    json_out(['error' => 'invalid_action'], 400);
}

try {
    if ($action === 'create') {
        if ($courseId <= 0) {
            json_out(['error' => 'invalid_course'], 400);
        }
        assert_manager_controls_course($pdo, $userId, $courseId);
        if ($name === '') {
            json_out(['error' => 'invalid_name'], 400);
        }
        $stmt = $pdo->prepare('INSERT INTO rooms (course_id, name) VALUES (:cid, :name)');
        $stmt->execute([':cid' => $courseId, ':name' => $name]);
        $id = (int)$pdo->lastInsertId();
        json_out(['success' => true, 'room_id' => $id]);
    }

    if ($action === 'rename') {
        if ($roomId <= 0) {
            json_out(['error' => 'invalid_room'], 400);
        }
        if ($name === '') {
            json_out(['error' => 'invalid_name'], 400);
        }
        $courseFromRoom = room_course_id($pdo, $roomId);
        if ($courseFromRoom === null) {
            json_out(['error' => 'not_found'], 404);
        }
        assert_manager_controls_course($pdo, $userId, $courseFromRoom);
        if ($courseId && $courseId !== $courseFromRoom) {
            json_out(['error' => 'course_mismatch'], 400);
        }
        $stmt = $pdo->prepare('UPDATE rooms SET name = :name WHERE room_id = :rid');
        $stmt->execute([':name' => $name, ':rid' => $roomId]);
        json_out(['success' => true]);
    }

    if ($action === 'delete') {
        if ($roomId <= 0) {
            json_out(['error' => 'invalid_room'], 400);
        }
        $courseFromRoom = room_course_id($pdo, $roomId);
        if ($courseFromRoom === null) {
            json_out(['error' => 'not_found'], 404);
        }
        assert_manager_controls_course($pdo, $userId, $courseFromRoom);
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE room_id = :rid LIMIT 1');
        $stmt->execute([':rid' => $roomId]);
        json_out(['success' => true, 'deleted' => $stmt->rowCount() > 0]);
    }
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

json_out(['error' => 'unknown'], 400);
