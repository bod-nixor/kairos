<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/_queue_helpers.php';

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/queue_helpers.php';

$user = require_login();
$pdo  = db();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$queueId = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
if ($queueId <= 0) {
    json_out(['error' => 'queue_id required'], 400);
}

try {
    $snapshot = get_queue_snapshot($pdo, $queueId, isset($user['user_id']) ? (int)$user['user_id'] : null);
} catch (RuntimeException $e) {
    json_out(['error' => 'not_found', 'message' => $e->getMessage()], 404);
}

json_out([
    'queue_id'           => $snapshot['queue_id'],
    'count'              => $snapshot['count'],
    'position'           => $snapshot['position'],
    'eta_minutes'        => $snapshot['eta_minutes'],
    'participants'       => $snapshot['participants'],
    'avg_handle_minutes' => $snapshot['avg_handle_minutes'],
    $st = $pdo->prepare("SELECT qe.user_id,
                                qe.`timestamp`,
                                u.name
                         FROM queue_entries qe
                         LEFT JOIN users u ON u.user_id = qe.user_id
                         WHERE qe.queue_id = :qid
                         ORDER BY qe.`timestamp` ASC, qe.user_id ASC");
    $st->execute([':qid' => $queueId]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

$participants = [];
$position = null;
$count = 0;

foreach ($rows as $idx => $row) {
    $uid = isset($row['user_id']) ? (int)$row['user_id'] : null;
    $name = isset($row['name']) ? (string)$row['name'] : '';
    $participants[] = [
        'user_id' => $uid,
        'name'    => $name,
    ];
    $count++;
    if ($uid !== null && $position === null && $uid === (int)$user['user_id']) {
        $position = $idx + 1; // 1-indexed position in queue
    }
}

$avgHandle = queue_avg_handle_minutes($pdo, $queueId);
$eta = null;
if ($position !== null && $avgHandle !== null) {
    $eta = (int)max(0, ceil($avgHandle * max(0, $position - 1)));
}

json_out([
    'queue_id'           => $queueId,
    'count'              => $count,
    'position'           => $position,
    'eta_minutes'        => $eta,
    'participants'       => $participants,
    'avg_handle_minutes' => $avgHandle,
]);
