<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$user = require_login();
$pdo  = db();

/* ---------- Logging setup ---------- */
$logDir  = __DIR__ . '/../logs';
$logFile = $logDir . '/queues.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

/** Append a line to queues.log */
function qlog(string $msg): void {
    global $logFile, $user;
    $uid = isset($user['user_id']) ? ('uid='.(string)$user['user_id']) : 'uid=-';
    $ts  = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts][$uid] $msg\n", FILE_APPEND);
}

/* Log PHP errors/notices and uncaught exceptions too */
set_error_handler(function($errno, $errstr, $errfile, $errline){
    qlog("PHP[$errno] $errstr @ $errfile:$errline");
    return false; // allow normal handling as well
});
set_exception_handler(function(Throwable $e){
    qlog("UNCAUGHT ".$e::class.": ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
});

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Accept: ?room_id=2, ?room_id="2", or omit for all
        $raw = $_GET['room_id'] ?? null;
        if (is_string($raw)) {
            // trim whitespace + quotes
            $raw = trim($raw, " \t\n\r\0\x0B\"'");
        }

        $sql = "SELECT
                  CAST(queue_id AS UNSIGNED) AS queue_id,
                  CAST(room_id  AS UNSIGNED) AS room_id,
                  name,
                  description
                FROM queues_info";
        $args = [];

        if ($raw !== null && $raw !== '' && ctype_digit((string)$raw)) {
            $sql .= " WHERE room_id = CAST(:rid AS UNSIGNED)";
            $args[':rid'] = (int)$raw;
        }

        $sql .= " ORDER BY name";

        qlog("GET queues room_id_raw=".json_encode($_GET['room_id'] ?? null)." parsed=".json_encode($raw)." SQL=".preg_replace('/\s+/', ' ', $sql)." ARGS=".json_encode($args));

        $st = $pdo->prepare($sql);
        $ok = $st->execute($args);
        $rows = $st->fetchAll();

        qlog("GET result ok=".($ok?'1':'0')." rows=". (is_array($rows) ? count($rows) : -1));

        // Optional debug: /api/queues.php?room_id=2&debug=1
        if (!empty($_GET['debug'])) {
            header('Content-Type: application/json; charset=utf-8');
            $db = $pdo->query("SELECT DATABASE() AS db")->fetch()['db'] ?? null;
            echo json_encode([
                'database' => $db,
                'input'    => $_GET['room_id'] ?? null,
                'parsed'   => $raw,
                'sql'      => $sql,
                'params'   => $args,
                'ok'       => $ok,
                'rows'     => $rows
            ], JSON_PRETTY_PRINT);
            exit;
        }

        json_out($rows);
        exit;
    }

    if ($method === 'POST') {
        $json     = json_decode(file_get_contents('php://input'), true) ?? [];
        $action   = $json['action'] ?? '';
        $queue_id = (int)($json['queue_id'] ?? 0);

        qlog("POST action=".json_encode($action)." queue_id=".$queue_id);

        if (!$queue_id) {
            qlog("POST error: queue_id missing");
            json_out(['error' => 'queue_id required'], 400);
        }

        if ($action === 'join') {
          $ins = $pdo->prepare("
            INSERT INTO queue_entries (`queue_id`, `user_id`, `timestamp`)
            VALUES (:qid, :uid, NOW())
            ON DUPLICATE KEY UPDATE `timestamp` = `timestamp`
          ");
          $ins->execute([':qid' => $queue_id, ':uid' => $user['user_id']]);
          qlog("POST join success qid=$queue_id");
          json_out(['success' => true, 'joined' => true]);
          exit;
        }

        if ($action === 'leave') {
            $del = $pdo->prepare("
                DELETE FROM queue_entries
                WHERE `queue_id` = :qid AND `user_id` = :uid
            ");
            $del->execute([':qid' => $queue_id, ':uid' => $user['user_id']]);
            qlog("POST leave success qid=$queue_id");
            json_out(['success' => true, 'left' => true]);
            exit;
        }

        qlog("POST error: unknown action=".json_encode($action));
        json_out(['error' => 'unknown action'], 400);
        exit;
    }

    qlog("Method not allowed: ".$method);
    json_out(['error' => 'method not allowed'], 405);

} catch (Throwable $e) {
    // Log full error and return JSON
    qlog("Caught ".$e::class.": ".$e->getMessage()." TRACE=".$e->getTraceAsString());
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}