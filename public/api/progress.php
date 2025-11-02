<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/_ws_notify.php';
$user = require_login();
$pdo  = db();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Lookup a status_id by its name (cached per request). */
function get_status_id(PDO $pdo, string $name): ?int {
  static $cache = [];
  $key = strtolower($name);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $st = $pdo->prepare("SELECT progress_status_id
                       FROM progress_status
                       WHERE LOWER(name) = LOWER(:n)
                       LIMIT 1");
  $st->execute([':n' => $name]);
  $id = $st->fetchColumn();
  $cache[$key] = $id !== false ? (int)$id : null;
  return $cache[$key];
}

try {
  if ($method === 'GET') {
    // Parse course_id (accepts 2 or "2")
    $raw = $_GET['course_id'] ?? null;
    if (is_string($raw)) $raw = trim($raw, " \t\n\r\0\x0B\"'");
    $hasCourse = ($raw !== null && $raw !== '' && ctype_digit((string)$raw));
    $cid = $hasCourse ? (int)$raw : null;

    // 1) Categories (filter by course if provided)
    if ($hasCourse) {
      $st = $pdo->prepare("
        SELECT CAST(category_id AS UNSIGNED) AS category_id, name
        FROM progress_category
        WHERE course_id = CAST(:cid AS UNSIGNED)
        ORDER BY name
      ");
      $st->execute([':cid' => $cid]);
      $cats = $st->fetchAll();
    } else {
      $cats = $pdo->query("
        SELECT CAST(category_id AS UNSIGNED) AS category_id, name
        FROM progress_category
        ORDER BY name
      ")->fetchAll();
    }

    // 2) Details (join through category → course)
    if ($hasCourse) {
      $st = $pdo->prepare("
        SELECT
          CAST(d.detail_id   AS UNSIGNED) AS detail_id,
          CAST(d.category_id AS UNSIGNED) AS category_id,
          d.name
        FROM progress_details d
        JOIN progress_category c ON c.category_id = d.category_id
        WHERE c.course_id = CAST(:cid AS UNSIGNED)
        ORDER BY d.name
      ");
      $st->execute([':cid' => $cid]);
      $rows = $st->fetchAll();
    } else {
      $rows = $pdo->query("
        SELECT
          CAST(detail_id AS UNSIGNED)   AS detail_id,
          CAST(category_id AS UNSIGNED) AS category_id,
          name
        FROM progress_details
        ORDER BY name
      ")->fetchAll();
    }

    // 3) Group details by category
    $byCat = [];
    foreach ($rows as $r) {
      $byCat[$r['category_id']][] = $r;
    }

    // 4) User statuses (map: detail_id => status_name), limited to course if provided
    if ($hasCourse) {
      $st = $pdo->prepare("
        SELECT p.detail_id,
               COALESCE(ps.name, 'None') AS status_name
        FROM progress p
        JOIN progress_details d  ON d.detail_id   = p.detail_id
        JOIN progress_category c ON c.category_id = d.category_id
        LEFT JOIN progress_status ps ON ps.progress_status_id = p.status_id
        WHERE p.user_id = :uid
          AND c.course_id = CAST(:cid AS UNSIGNED)
      ");
      $st->execute([':uid' => $user['user_id'], ':cid' => $cid]);
    } else {
      $st = $pdo->prepare("
        SELECT p.detail_id,
               COALESCE(ps.name, 'None') AS status_name
        FROM progress p
        LEFT JOIN progress_status ps ON ps.progress_status_id = p.status_id
        WHERE p.user_id = :uid
      ");
      $st->execute([':uid' => $user['user_id']]);
    }
    $rowsStatus = $st->fetchAll();
    $userStatuses = [];
    foreach ($rowsStatus as $r) {
      $userStatuses[(int)$r['detail_id']] = $r['status_name'] ?? 'None';
    }

    // Optional debug
    if (!empty($_GET['debug'])) {
      header('Content-Type: application/json; charset=utf-8');
      $db = $pdo->query("SELECT DATABASE() AS db")->fetch()['db'] ?? null;
      echo json_encode([
        'database'          => $db,
        'input_course_id'   => $_GET['course_id'] ?? null,
        'parsed_course_id'  => $cid,
        'categories_count'  => count($cats),
        'details_count'     => count($rows),
        'statuses_count'    => count($userStatuses),
        'categories'        => $cats,
        'detailsByCategory' => $byCat,
        'userStatuses'      => (object)$userStatuses
      ], JSON_PRETTY_PRINT);
      exit;
    }

    json_out([
      'categories'        => $cats,
      'detailsByCategory' => $byCat,
      'userStatuses'      => (object)$userStatuses
    ]);
    exit;
  }

  if ($method === 'POST') {
    $json       = json_decode(file_get_contents('php://input'), true) ?? [];
    $detail_id  = isset($json['detail_id']) ? (int)$json['detail_id'] : 0;
    $done       = !empty($json['done']);      // legacy checkbox mapping
    $statusName = $json['status'] ?? null;    // optional explicit status name
    $rawCid     = $json['course_id'] ?? null;

    if (is_string($rawCid)) $rawCid = trim($rawCid, " \t\n\r\0\x0B\"'");
    $cidPost = ($rawCid !== null && $rawCid !== '' && ctype_digit((string)$rawCid)) ? (int)$rawCid : null;

    if (!$detail_id) json_out(['error' => 'detail_id required'], 400);

    // Validate detail belongs to the selected course (if provided)
    if ($cidPost !== null) {
      $check = $pdo->prepare("
        SELECT 1
        FROM progress_details d
        JOIN progress_category c ON c.category_id = d.category_id
        WHERE d.detail_id = :did AND c.course_id = :cid
        LIMIT 1
      ");
      $check->execute([':did' => $detail_id, ':cid' => $cidPost]);
      if (!$check->fetchColumn()) {
        json_out(['error' => 'detail does not belong to selected course'], 400);
      }
    }

    if ($done || $statusName) {
      // Resolve status_id: explicit name wins; otherwise map done=true → Completed
      $target = $statusName ?: 'Completed';
      $sid = get_status_id($pdo, $target);
      if ($sid === null) json_out(['error' => "status '$target' not found"], 500);

      // Requires UNIQUE(user_id, detail_id)
      $ins = $pdo->prepare("
        INSERT INTO progress (user_id, detail_id, status_id, created_at, updated_at)
        VALUES (:uid, :did, :sid, NOW(), NOW())
        ON DUPLICATE KEY UPDATE status_id = VALUES(status_id), updated_at = NOW()
      ");
      $ins->execute([':uid' => $user['user_id'], ':did' => $detail_id, ':sid' => $sid]);
    } else {
      // Untick → remove row
      $del = $pdo->prepare("DELETE FROM progress WHERE user_id = :uid AND detail_id = :did");
      $del->execute([':uid' => $user['user_id'], ':did' => $detail_id]);
    }

    $notifyCourseId = $cidPost;
    if ($notifyCourseId === null) {
      try {
        $lookup = $pdo->prepare(
          'SELECT c.course_id FROM progress_details d JOIN progress_category c ON c.category_id = d.category_id WHERE d.detail_id = :did LIMIT 1'
        );
        $lookup->execute([':did' => $detail_id]);
        $courseVal = $lookup->fetchColumn();
        if ($courseVal !== false) {
          $notifyCourseId = (int)$courseVal;
        }
      } catch (Throwable $e) {
        // ignore lookup failures – course filter remains null
      }
    }

    $payload = ['user_id' => (int)$user['user_id']];
    $event = ['event' => 'progress', 'ref_id' => $detail_id, 'payload' => $payload];
    if ($notifyCourseId !== null) {
      $event['course_id'] = $notifyCourseId;
    }
    ws_notify($event);

    json_out(['success' => true]);
    exit;
  }

  json_out(['error' => 'method not allowed'], 405);

} catch (Throwable $e) {
  json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}