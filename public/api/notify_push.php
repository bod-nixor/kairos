<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/ta/common.php';

$user = require_login();
$pdo  = db();

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$pk = ta_assignment_primary_key($pdo);
$lastId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
}
if (isset($_GET['since'])) {
    $lastId = max($lastId, (int)$_GET['since']);
}

$endAt = time() + 25;
while (time() < $endAt) {
    if (!table_exists($pdo, 'ta_assignments')) {
        usleep(300000);
        continue;
    }

    if ($pk) {
        $sql = "SELECT CAST(ta.$pk AS UNSIGNED) AS event_id,
                       ta.queue_id,
                       ta.student_user_id,
                       ta.ta_user_id,
                       ta.started_at,
                       tu.name AS ta_name
                FROM ta_assignments ta
                JOIN users tu ON tu.user_id = ta.ta_user_id
                WHERE ta.student_user_id = :uid AND ta.$pk > :last
                ORDER BY ta.$pk ASC
                LIMIT 20";
        $args = [':uid' => $user['user_id'], ':last' => $lastId];
    } else {
        $expr = '(UNIX_TIMESTAMP(ta.started_at) * 1000) + ta.queue_id';
        $sql = "SELECT CAST($expr AS UNSIGNED) AS event_id,
                       ta.queue_id,
                       ta.student_user_id,
                       ta.ta_user_id,
                       ta.started_at,
                       tu.name AS ta_name
                FROM ta_assignments ta
                JOIN users tu ON tu.user_id = ta.ta_user_id
                WHERE ta.student_user_id = :uid AND $expr > :last
                ORDER BY ta.started_at ASC
                LIMIT 20";
        $args = [':uid' => $user['user_id'], ':last' => $lastId];
    }

    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    if ($rows) {
        foreach ($rows as $row) {
            $eventId = isset($row['event_id']) ? (int)$row['event_id'] : 0;
            if ($eventId <= $lastId) {
                continue;
            }
            $lastId = $eventId;
            $assignmentId = null;
            if ($pk && isset($row['event_id'])) {
                $assignmentId = is_numeric($row['event_id']) ? (int)$row['event_id'] : $row['event_id'];
            }
            $payload = [
                'queue_id'      => isset($row['queue_id']) ? (int)$row['queue_id'] : null,
                'user_id'       => isset($row['student_user_id']) ? (int)$row['student_user_id'] : null,
                'ta_user_id'    => isset($row['ta_user_id']) ? (int)$row['ta_user_id'] : null,
                'ta_name'       => $row['ta_name'] ?? '',
                'started_at'    => $row['started_at'] ?? null,
                'assignment_id' => $assignmentId,
            ];
            echo 'id: '.$lastId."\n";
            echo "event: ta_accept\n";
            echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
        }
        @ob_flush();
        @flush();
    }

    usleep(300000);
}

echo ": keep-alive\n\n";
@ob_flush();
@flush();
