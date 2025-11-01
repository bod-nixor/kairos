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

if (!table_exists($pdo, 'queues') || !table_has_columns($pdo, 'queues', ['queue_id', 'room_id', 'name'])) {
    json_out(['error' => 'unsupported', 'message' => 'queues table not available'], 500);
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action = strtolower((string)($payload['action'] ?? ''));
$roomId = isset($payload['room_id']) ? (int)$payload['room_id'] : 0;
$queueId = isset($payload['queue_id']) ? (int)$payload['queue_id'] : 0;
$name = array_key_exists('name', $payload) ? trim((string)$payload['name']) : null;
$description = array_key_exists('description', $payload) ? trim((string)$payload['description']) : null;

if (!in_array($action, ['create', 'rename', 'delete'], true)) {
    json_out(['error' => 'invalid_action'], 400);
}

try {
    if ($action === 'create') {
        if ($roomId <= 0) {
            json_out(['error' => 'invalid_room'], 400);
        }
        $courseId = room_course_id($pdo, $roomId);
        if ($courseId === null) {
            json_out(['error' => 'not_found'], 404);
        }
        assert_manager_controls_course($pdo, $userId, $courseId);
        if ($name === null || $name === '') {
            json_out(['error' => 'invalid_name'], 400);
        }
        $stmt = $pdo->prepare('INSERT INTO queues (room_id, name, description) VALUES (:rid, :name, :description)');
        $stmt->execute([
            ':rid' => $roomId,
            ':name' => $name,
            ':description' => $description ?? '',
        ]);
        $id = (int)$pdo->lastInsertId();
        json_out(['success' => true, 'queue_id' => $id]);
    }

    if ($action === 'rename') {
        if ($queueId <= 0) {
            json_out(['error' => 'invalid_queue'], 400);
        }
        $info = queue_room_course($pdo, $queueId);
        if (!$info || empty($info['course_id'])) {
            json_out(['error' => 'not_found'], 404);
        }
        assert_manager_controls_course($pdo, $userId, (int)$info['course_id']);
        $fields = [];
        $params = [':qid' => $queueId];
        if ($name !== null) {
            if ($name === '') {
                json_out(['error' => 'invalid_name'], 400);
            }
            $fields[] = 'name = :name';
            $params[':name'] = $name;
        }
        if ($description !== null) {
            $fields[] = 'description = :description';
            $params[':description'] = $description;
        }
        if (!$fields) {
            json_out(['error' => 'nothing_to_update'], 400);
        }
        $sql = 'UPDATE queues SET ' . implode(', ', $fields) . ' WHERE queue_id = :qid';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_out(['success' => true]);
    }

    if ($action === 'delete') {
        if ($queueId <= 0) {
            json_out(['error' => 'invalid_queue'], 400);
        }
        $info = queue_room_course($pdo, $queueId);
        if (!$info || empty($info['course_id'])) {
            json_out(['error' => 'not_found'], 404);
        }
        assert_manager_controls_course($pdo, $userId, (int)$info['course_id']);
        $stmt = $pdo->prepare('DELETE FROM queues WHERE queue_id = :qid LIMIT 1');
        $stmt->execute([':qid' => $queueId]);
        json_out(['success' => true, 'deleted' => $stmt->rowCount() > 0]);
    }
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

json_out(['error' => 'unknown'], 400);
