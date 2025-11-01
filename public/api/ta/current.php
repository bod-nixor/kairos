<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$queueId = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
if ($queueId <= 0) {
    json_out(['error' => 'queue_id required'], 400);
}

$queueStmt = $pdo->prepare('SELECT q.queue_id, r.course_id
                             FROM queues_info q
                             JOIN rooms r ON r.room_id = q.room_id
                             WHERE q.queue_id = :qid
                             LIMIT 1');
$queueStmt->execute([':qid' => $queueId]);
$queue = $queueStmt->fetch();
if (!$queue) {
    json_out(['error' => 'queue not found'], 404);
}
if (!ta_has_course($pdo, (int)$user['user_id'], (int)$queue['course_id'])) {
    json_out(['error' => 'forbidden'], 403);
}

$assignment = ta_active_assignment($pdo, $queueId);
json_out(['assignment' => $assignment]);
