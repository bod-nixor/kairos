<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
if ($roomId <= 0) {
    json_out(['error' => 'room_id required'], 400);
}

$room = $pdo->prepare('SELECT room_id, course_id, name FROM rooms WHERE room_id = :rid LIMIT 1');
$room->execute([':rid' => $roomId]);
$roomRow = $room->fetch();
if (!$roomRow) {
    json_out(['error' => 'room not found'], 404);
}
$courseId = (int)$roomRow['course_id'];
if (!ta_has_course($pdo, (int)$user['user_id'], $courseId)) {
    json_out(['error' => 'forbidden', 'message' => 'Course not assigned'], 403);
}

$sql = "SELECT
            q.queue_id,
            q.room_id,
            q.name,
            q.description,
            COUNT(qe.user_id) AS occupant_count,
            CASE WHEN COUNT(qe.user_id) = 0 THEN JSON_ARRAY()
                 ELSE JSON_ARRAYAGG(
                      JSON_OBJECT(
                        'user_id', CAST(qe.user_id AS UNSIGNED),
                        'name', u.name,
                        'joined_at', DATE_FORMAT(qe.`timestamp`, '%Y-%m-%dT%H:%i:%sZ')
                      )
                      ORDER BY qe.`timestamp`
                 )
            END AS occupants_json
        FROM queues_info q
        LEFT JOIN queue_entries qe ON qe.queue_id = q.queue_id
        LEFT JOIN users u ON u.user_id = qe.user_id
        WHERE q.room_id = :rid
        GROUP BY q.queue_id, q.room_id, q.name, q.description
        ORDER BY q.name";
$st = $pdo->prepare($sql);
$st->execute([':rid' => $roomId]);
$rows = $st->fetchAll();

$result = [];
foreach ($rows as $row) {
    $occupants = [];
    if (!empty($row['occupants_json'])) {
        $decoded = json_decode($row['occupants_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $occupants[] = [
                    'user_id'   => isset($entry['user_id']) ? (int)$entry['user_id'] : null,
                    'name'      => $entry['name'] ?? '',
                    'joined_at' => $entry['joined_at'] ?? null,
                ];
            }
        }
    }
    $queueId = (int)$row['queue_id'];
    $serving = ta_active_assignment($pdo, $queueId);
    $result[] = [
        'queue_id'       => $queueId,
        'room_id'        => (int)$row['room_id'],
        'name'           => $row['name'] ?? '',
        'description'    => $row['description'] ?? '',
        'occupant_count' => isset($row['occupant_count']) ? (int)$row['occupant_count'] : 0,
        'occupants'      => $occupants,
        'serving'        => $serving,
    ];
}

json_out(['room' => $roomRow, 'queues' => $result]);
