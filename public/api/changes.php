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

$channels = isset($_GET['channels']) ? explode(',', $_GET['channels']) : ['rooms','progress'];
$channels = array_values(array_intersect($channels, ['rooms','progress'])); // sanitize
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

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
  $sql = "SELECT id, channel, ref_id, course_id, UNIX_TIMESTAMP(created_at) ts
          FROM change_log
          WHERE id > ?
            AND channel IN ($in)";
  $args = array_merge([$lastId], $args);

  if ($courseId > 0) {
    $sql .= " AND (course_id = ? OR course_id IS NULL)";
    $args[] = $courseId;
  }
  $sql .= " ORDER BY id ASC LIMIT 100";

  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll();

  if ($rows) {
    foreach ($rows as $row) {
      $lastId = (int)$row['id'];
      // name the event by channel: 'rooms' or 'progress'
      echo "id: {$lastId}\n";
      echo "event: {$row['channel']}\n";
      echo 'data: '.json_encode($row, JSON_UNESCAPED_SLASHES)."\n\n";
    }
    @ob_flush(); @flush();
  }

  usleep(300000); // 300ms
}

echo ": keep-alive\n\n";
@ob_flush(); @flush();