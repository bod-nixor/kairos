<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/queue_helpers.php';
require_once __DIR__ . '/_ws_notify.php';
require_once dirname(__DIR__, 2) . '/src/rbac.php';
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
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Accept: ?room_id=2, ?room_id="2", or omit for all
        $raw = $_GET['room_id'] ?? null;
        if (is_string($raw)) {
            // trim whitespace + quotes
            $raw = trim($raw, " \t\n\r\0\x0B\"'");
        }

        $roomId = null;
        if ($raw !== null && $raw !== '' && ctype_digit((string)$raw)) {
            $roomId = (int)$raw;
        }

        $sql = "SELECT
                  CAST(q.queue_id AS UNSIGNED) AS queue_id,
                  CAST(q.room_id  AS UNSIGNED) AS room_id,
                  CAST(r.course_id AS UNSIGNED) AS course_id,
                  q.name,
                  q.description,
                  COUNT(qe.user_id) AS occupant_count,
                  CASE WHEN COUNT(qe.user_id) = 0 THEN JSON_ARRAY()
                       ELSE JSON_ARRAYAGG(
                            JSON_OBJECT(
                              'user_id', CAST(u.user_id AS UNSIGNED),
                              'name', u.name
                            )
                            ORDER BY qe.`timestamp`
                       )
                  END AS occupants_json
                FROM queues_info q
                JOIN rooms r ON r.room_id = q.room_id
                LEFT JOIN queue_entries qe ON qe.queue_id = q.queue_id
                LEFT JOIN users u ON u.user_id = qe.user_id";

        $where = [];
        $args  = [];

        if ($roomId) {
            $roomScope = rbac_room_scope($pdo, $roomId);
            if (!$roomScope) {
                json_out(['error' => 'not_found', 'message' => 'room not found'], 404);
            }
            if (!rbac_can_view_room($pdo, $user, $roomId)) {
                rbac_debug_deny('queues.room.forbidden', [
                    'user_id' => rbac_user_id($user),
                    'room_id' => $roomId,
                ]);
                json_out(['error' => 'forbidden', 'message' => 'room access denied'], 403);
            }
            $where[] = 'q.room_id = :rid';
            $args[':rid'] = $roomId;
        } else {
            $courseScope = rbac_accessible_course_ids($pdo, $user);
            if ($courseScope !== null) {
                if (!$courseScope) {
                    json_out([]);
                }
                $coursePlaceholders = [];
                foreach ($courseScope as $idx => $cid) {
                    $param = ':course' . $idx;
                    $coursePlaceholders[] = $param;
                    $args[$param] = (int)$cid;
                }
                $where[] = 'r.course_id IN (' . implode(',', $coursePlaceholders) . ')';
            }
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' GROUP BY q.queue_id, q.room_id, r.course_id, q.name, q.description ORDER BY q.name';

        qlog('GET queues room_id=' . json_encode($roomId) . ' SQL=' . preg_replace('/\\s+/', ' ', $sql) . ' ARGS=' . json_encode($args));

        $st = $pdo->prepare($sql);
        $ok = $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = array_values(array_filter($rows, static function(array $row) use ($pdo, $user): bool {
            $queueId = isset($row['queue_id']) ? (int)$row['queue_id'] : 0;
            if ($queueId <= 0) {
                return false;
            }
            $scope = [
                'queue_id'  => $queueId,
                'room_id'   => isset($row['room_id']) ? (int)$row['room_id'] : null,
                'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
            ];
            return rbac_can_view_queue($pdo, $user, $queueId, $scope);
        }));

        if (is_array($rows)) {
            $rows = array_map(function(array $row) use ($pdo): array {
                $queueId = isset($row['queue_id']) ? (int)$row['queue_id'] : 0;
                $roomId = isset($row['room_id']) ? (int)$row['room_id'] : null;
                $occupantCount = isset($row['occupant_count']) ? (int)$row['occupant_count'] : 0;

                $decoded = [];
                if (!empty($row['occupants_json']) && is_string($row['occupants_json'])) {
                    $decoded = json_decode($row['occupants_json'], true);
                    if (!is_array($decoded)) {
                        $decoded = [];
                    }
                }

                $occupants = array_values(array_filter(array_map(static function($entry) {
                    if (!is_array($entry)) {
                        return null;
                    }

                    return [
                        'user_id' => isset($entry['user_id']) ? (int)$entry['user_id'] : null,
                        'name'    => isset($entry['name']) ? (string)$entry['name'] : '',
                    ];
                }, $decoded), static function($entry) {
                    return $entry !== null;
                }));

                $snapshot = $queueId > 0 ? queue_snapshot_data($pdo, $queueId) : null;
                $students = [];
                $waitingStudents = [];
                $waitingCount = $occupantCount;
                $serving = null;
                $updatedAt = time();

                if ($snapshot) {
                    $serving = $snapshot['serving'] ?? null;
                    $updatedAt = $snapshot['updated_at'] ?? $updatedAt;

                    foreach ($snapshot['students'] ?? [] as $student) {
                        if (!is_array($student) || !isset($student['id'])) {
                            continue;
                        }
                        $status = $student['status'] ?? 'waiting';
                        if (!in_array($status, ['waiting', 'serving', 'done'], true)) {
                            $status = 'waiting';
                        }
                        $studentRow = [
                            'id'        => (int)$student['id'],
                            'name'      => isset($student['name']) ? (string)$student['name'] : '',
                            'status'    => $status,
                            'joined_at' => $student['joined_at'] ?? null,
                        ];
                        $students[] = $studentRow;
                        if ($status === 'waiting') {
                            $waitingStudents[] = [
                                'user_id'   => $studentRow['id'],
                                'name'      => $studentRow['name'],
                                'joined_at' => $studentRow['joined_at'],
                            ];
                        }
                    }
                    if (array_key_exists('waiting_count', $snapshot)) {
                        $waitingCount = (int)$snapshot['waiting_count'];
                    } else {
                        $waitingCount = count($waitingStudents);
                    }
                }

                if (!$students && $occupants) {
                    foreach ($occupants as $occ) {
                        if (!isset($occ['user_id'])) {
                            continue;
                        }
                        $students[] = [
                            'id'        => (int)$occ['user_id'],
                            'name'      => $occ['name'] ?? '',
                            'status'    => 'waiting',
                            'joined_at' => null,
                        ];
                    }
                    $waitingStudents = array_map(static function(array $occ): array {
                        return [
                            'user_id'   => $occ['user_id'],
                            'name'      => $occ['name'] ?? '',
                            'joined_at' => null,
                        ];
                    }, $occupants);
                }

                if (!$waitingStudents) {
                    $waitingStudents = $occupants;
                }
                if ($waitingCount < 0) {
                    $waitingCount = 0;
                }

                return [
                    'queue_id'       => $queueId,
                    'room_id'        => $roomId,
                    'name'           => isset($row['name']) ? (string)$row['name'] : '',
                    'description'    => isset($row['description']) ? (string)$row['description'] : '',
                    'occupant_count' => $waitingCount,
                    'occupants'      => $waitingStudents,
                    'students'       => $students,
                    'serving'        => $serving,
                    'updated_at'     => $updatedAt,
                ];
            }, $rows);
        }

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

        $scope = rbac_queue_scope($pdo, $queue_id);
        if (!$scope) {
            qlog("POST error: queue not found qid=$queue_id");
            json_out(['error' => 'not_found', 'message' => 'queue not found'], 404);
        }

        if (!rbac_can_view_queue($pdo, $user, $queue_id, $scope)) {
            rbac_debug_deny('queues.post.forbidden', [
                'user_id' => rbac_user_id($user),
                'queue_id' => $queue_id,
                'action'  => $action,
            ]);
            json_out(['error' => 'forbidden', 'message' => 'queue access denied'], 403);
        }

        if ($action === 'join') {
            if (!rbac_can_student_join_queue($pdo, $user, $queue_id, $scope)) {
                rbac_debug_deny('queues.join.forbidden', [
                    'user_id' => rbac_user_id($user),
                    'queue_id' => $queue_id,
                ]);
                json_out(['error' => 'forbidden', 'message' => 'queue join not permitted'], 403);
            }
            $joined = false;
            $already = false;
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO queue_entries (`queue_id`, `user_id`, `timestamp`)
                     VALUES (:qid, :uid, NOW())'
                );
                $ins->execute([':qid' => $queue_id, ':uid' => $user['user_id']]);
                $joined = $ins->rowCount() > 0;
                $already = !$joined;
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if ($joined) {
                $meta = queue_meta($pdo, $queue_id);
                emit_change($pdo, 'queue', $queue_id, $meta['course_id'] ?? null, [
                    'action'  => 'join',
                    'user_id' => (int)$user['user_id'],
                ]);
                queue_ws_notify($pdo, $queue_id, 'join', [
                    'student_id'   => (int)$user['user_id'],
                    'student_name' => isset($user['name']) ? (string)$user['name'] : null,
                ]);
                qlog("POST join success qid=$queue_id");
            } else {
                qlog("POST join skipped (already in queue) qid=$queue_id");
            }

            json_out(['success' => true, 'joined' => $joined, 'already' => $already]);
        }

        if ($action === 'leave') {
            if (!rbac_can_student_view_queue($pdo, $user, $queue_id, $scope)) {
                rbac_debug_deny('queues.leave.forbidden', [
                    'user_id' => rbac_user_id($user),
                    'queue_id' => $queue_id,
                ]);
                json_out(['error' => 'forbidden', 'message' => 'queue leave not permitted'], 403);
            }
            $left = false;
            $already = false;
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare(
                    'DELETE FROM queue_entries
                     WHERE `queue_id` = :qid AND `user_id` = :uid'
                );
                $del->execute([':qid' => $queue_id, ':uid' => $user['user_id']]);
                $left = $del->rowCount() > 0;
                $already = !$left;
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if ($left) {
                $meta = queue_meta($pdo, $queue_id);
                emit_change($pdo, 'queue', $queue_id, $meta['course_id'] ?? null, [
                    'action'  => 'leave',
                    'user_id' => (int)$user['user_id'],
                ]);
                queue_ws_notify($pdo, $queue_id, 'leave', [
                    'student_id'   => (int)$user['user_id'],
                    'student_name' => isset($user['name']) ? (string)$user['name'] : null,
                ]);
                qlog("POST leave success qid=$queue_id");
            } else {
                qlog("POST leave skipped (not in queue) qid=$queue_id");
            }

            json_out(['success' => true, 'left' => $left, 'already' => $already]);
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