<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

$pdo = db();

$allowedChannels = ['rooms', 'queue', 'progress'];
$channelsParam = isset($_GET['channels']) ? (string)$_GET['channels'] : '';
$channels = array_values(array_filter(array_map('trim', explode(',', $channelsParam))));
$channels = array_values(array_intersect($channels, $allowedChannels));
if (!$channels) {
    $channels = ['rooms', 'progress'];
}

$courseId = null;
if (isset($_GET['course_id']) && $_GET['course_id'] !== '') {
    $courseId = (int)$_GET['course_id'];
    if ($courseId <= 0) {
        $courseId = null;
    }
}

$queueFilters = [];
$queueParam = $_GET['queue_id'] ?? '';
if (is_string($queueParam)) {
    foreach (explode(',', $queueParam) as $piece) {
        $piece = trim($piece);
        if ($piece === '' || !ctype_digit($piece)) {
            continue;
        }
        $queueId = (int)$piece;
        if ($queueId > 0) {
            $queueFilters[$queueId] = $queueId;
        }
    }
} elseif (is_numeric($queueParam)) {
    $queueId = (int)$queueParam;
    if ($queueId > 0) {
        $queueFilters[$queueId] = $queueId;
    }
}
$queueFilters = array_values($queueFilters);

$hasPayload = false;
try {
    $check = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS" .
        " WHERE TABLE_SCHEMA = DATABASE()" .
        "   AND TABLE_NAME = 'change_log'" .
        "   AND COLUMN_NAME = 'payload_json' LIMIT 1"
    );
    if ($check->execute() && $check->fetchColumn()) {
        $hasPayload = true;
    }
} catch (Throwable $e) {
    $hasPayload = false;
}

$lastId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = max($lastId, (int)$_SERVER['HTTP_LAST_EVENT_ID']);
}
if (isset($_GET['since']) && $_GET['since'] !== '') {
    $lastId = max($lastId, (int)$_GET['since']);
}

$sleepMs = 300;
$maxSleepMs = 2000;
$minSleepMs = 50;
$heartbeatInterval = 15;
$nextHeartbeatAt = microtime(true) + $heartbeatInterval;
$endAt = microtime(true) + 90;

$payloadSelect = $hasPayload ? ', payload_json' : '';

while (microtime(true) < $endAt) {
    if (connection_aborted()) {
        break;
    }

    $params = [':lastId' => $lastId];
    $channelPlaceholders = [];
    foreach ($channels as $idx => $channel) {
        $placeholder = ':ch' . $idx;
        $channelPlaceholders[] = $placeholder;
        $params[$placeholder] = $channel;
    }

    $sql = "SELECT id, channel, ref_id, course_id, UNIX_TIMESTAMP(created_at) AS ts{$payloadSelect}" .
           " FROM change_log" .
           " WHERE id > :lastId" .
           "   AND channel IN (" . implode(',', $channelPlaceholders) . ')';

    if ($courseId !== null) {
        $sql .= ' AND (course_id = :courseId OR course_id IS NULL)';
        $params[':courseId'] = $courseId;
    }

    if ($queueFilters) {
        $queuePlaceholders = [];
        foreach ($queueFilters as $idx => $queueId) {
            $placeholder = ':qid' . $idx;
            $queuePlaceholders[] = $placeholder;
            $params[$placeholder] = $queueId;
        }
        $sql .= ' AND ref_id IN (' . implode(',', $queuePlaceholders) . ')';
    }

    $sql .= ' ORDER BY id ASC LIMIT 100';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        foreach ($rows as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $eventId = (int)$row['id'];
            if ($eventId <= $lastId) {
                continue;
            }
            $lastId = $eventId;
            $event = [
                'id'        => $eventId,
                'channel'   => $row['channel'] ?? null,
                'ref_id'    => isset($row['ref_id']) ? (int)$row['ref_id'] : null,
                'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
                'ts'        => isset($row['ts']) ? (int)$row['ts'] : null,
            ];
            if ($hasPayload && array_key_exists('payload_json', $row) && $row['payload_json'] !== null && $row['payload_json'] !== '') {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $event['payload'] = $decoded;
                }
            }

            echo 'id: ' . $eventId . "\n";
            echo 'event: ' . ($row['channel'] ?? 'message') . "\n";
            echo 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        $sleepMs = $minSleepMs;
        $nextHeartbeatAt = microtime(true) + $heartbeatInterval;
        flush();
    } else {
        $now = microtime(true);
        if ($now >= $nextHeartbeatAt) {
            echo ": hb\n\n";
            flush();
            $nextHeartbeatAt = $now + $heartbeatInterval;
        }
        $sleepMs = (int)min($maxSleepMs, max($sleepMs * 1.5, 300));
    }

    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

echo ": closing\n\n";
flush();
