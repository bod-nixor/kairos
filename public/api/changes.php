<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_login();

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$pdo = db();

$allowedChannels = ['rooms','progress','queue','ta_accept'];
$channels = isset($_GET['channels']) ? explode(',', $_GET['channels']) : ['rooms','progress'];
$channels = array_values(array_intersect($channels, $allowedChannels));
if (!$channels) {
  // Fallback so the SQL placeholders list never ends up empty (array_fill would throw)
  $channels = ['rooms','progress'];
}
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$queueId  = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;

$hasPayload = false;
try {
  $check = $pdo->prepare(
    "SELECT 1 FROM information_schema.COLUMNS".
    " WHERE TABLE_SCHEMA = DATABASE()".
    "   AND TABLE_NAME = 'change_log'".
    "   AND COLUMN_NAME = 'payload_json' LIMIT 1"
  );
  if ($check->execute() && $check->fetchColumn()) {
    $hasPayload = true;
  }
} catch (Throwable $e) {
  $hasPayload = false;
}

// Start from the last seen id (Last-Event-ID header) or 0
$lastId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
  $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
}
if (isset($_GET['since'])) {
  $lastId = max($lastId, (int)$_GET['since']);
}

$endAt = time() + 25; // keep the request ~25s, client will reconnect
while (time() < $endAt) {
  // Build query
  $in = implode(',', array_fill(0, count($channels), '?'));
  $args = $channels;
  $payloadSelect = $hasPayload ? ', payload_json' : '';
  $sql = "SELECT id, channel, ref_id, course_id, UNIX_TIMESTAMP(created_at) ts{$payloadSelect}
          FROM change_log
          WHERE id > ?
            AND channel IN ($in)";
  $args = array_merge([$lastId], $args);

  if ($courseId > 0) {
    $sql .= " AND (course_id = ? OR course_id IS NULL)";
    $args[] = $courseId;
  }
  if ($queueId > 0) {
    $sql .= " AND (channel NOT IN ('queue','ta_accept') OR ref_id = ?)";
    $args[] = $queueId;
  }
  $sql .= " ORDER BY id ASC LIMIT 100";

  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll();

  if ($rows) {
    foreach ($rows as $row) {
      $lastId = (int)$row['id'];
      $data = [
        'id'        => $lastId,
        'channel'   => $row['channel'],
        'ref_id'    => isset($row['ref_id']) ? (int)$row['ref_id'] : null,
        'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
        'ts'        => isset($row['ts']) ? (int)$row['ts'] : null,
      ];
      if ($hasPayload && array_key_exists('payload_json', $row) && $row['payload_json'] !== null && $row['payload_json'] !== '') {
        $decoded = json_decode($row['payload_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $data['payload'] = $decoded;
        }
      }

      echo "id: {$lastId}\n";
      echo "event: {$row['channel']}\n";
      echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
    }
    @ob_flush(); @flush();
  }

  usleep(300000); // 300ms
}

echo ": keep-alive\n\n";
@ob_flush(); @flush();