<?php
declare(strict_types=1);

/**
 * Attempt to insert a row into change_log to notify SSE listeners.
 * Silently ignores errors (e.g. table missing) because the API should still work.
 */
function emit_change(PDO $pdo, string $channel, ?int $refId = null, ?int $courseId = null, ?array $payload = null): void
{
    static $capabilities = null;
    if ($capabilities === null) {
        $capabilities = [
            'payload' => false,
        ];
        try {
            $check = $pdo->prepare(
                "SELECT 1 FROM information_schema.COLUMNS".
                " WHERE TABLE_SCHEMA = DATABASE()".
                "   AND TABLE_NAME = 'change_log'".
                "   AND COLUMN_NAME = 'payload_json' LIMIT 1"
            );
            if ($check->execute() && $check->fetchColumn()) {
                $capabilities['payload'] = true;
            }
        } catch (Throwable $e) {
            // leave defaults – table may not exist or be inaccessible
        }
    }

    $columns = ['channel', 'ref_id', 'course_id'];
    $placeholders = [':channel', ':ref_id', ':course_id'];
    $params = [
        ':channel'   => $channel,
        ':ref_id'    => $refId,
        ':course_id' => $courseId,
    ];

    if ($payload !== null && $capabilities['payload']) {
        $columns[] = 'payload_json';
        $placeholders[] = ':payload_json';
        $params[':payload_json'] = json_encode($payload);
    }

    $sql = 'INSERT INTO change_log (' . implode(',', $columns) . ')
            VALUES (' . implode(',', $placeholders) . ')';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Throwable $e) {
        // Swallow – missing table/columns should not break primary request flow.
    }
}

/**
 * Retrieve cached metadata about a queue (room/course relationship).
 */
function queue_meta(PDO $pdo, int $queueId): array
{
    static $cache = [];
    if (isset($cache[$queueId])) {
        return $cache[$queueId];
    }

    $meta = [
        'queue_id'  => $queueId,
        'room_id'   => null,
        'course_id' => null,
    ];

    $queries = [
        "SELECT CAST(queue_id AS UNSIGNED) AS queue_id,
                CAST(room_id AS UNSIGNED)  AS room_id,
                CAST(course_id AS UNSIGNED) AS course_id
         FROM queues_info
         WHERE queue_id = :qid
         LIMIT 1",
        "SELECT q.queue_id,
                q.room_id,
                r.course_id
         FROM queues q
         LEFT JOIN rooms r ON r.room_id = q.room_id
         WHERE q.queue_id = :qid
         LIMIT 1",
    ];

    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':qid' => $queueId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['queue_id'])) {
                    $meta['queue_id'] = (int)$row['queue_id'];
                }
                if (array_key_exists('room_id', $row) && $row['room_id'] !== null) {
                    $meta['room_id'] = (int)$row['room_id'];
                }
                if (array_key_exists('course_id', $row) && $row['course_id'] !== null) {
                    $meta['course_id'] = (int)$row['course_id'];
                }
                break;
            }
        } catch (Throwable $e) {
            // try next strategy
        }
    }

    return $cache[$queueId] = $meta;
}

/**
 * Try to compute an average handle time (in minutes) for a queue using several fallbacks.
 */
function queue_avg_handle_minutes(PDO $pdo, int $queueId): ?float
{
    static $strategies = null;
    static $availability = [];

    if ($strategies === null) {
        $strategies = [
            [
                'key' => 'queue_stats',
                'sql' => "SELECT avg_handle_minutes
                          FROM queue_stats
                          WHERE queue_id = :qid
                          ORDER BY updated_at DESC
                          LIMIT 1",
                'column' => 'avg_handle_minutes',
            ],
            [
                'key' => 'queue_metrics',
                'sql' => "SELECT AVG(handle_minutes) AS avg_handle_minutes
                          FROM queue_metrics
                          WHERE queue_id = :qid",
                'column' => 'avg_handle_minutes',
            ],
            [
                'key' => 'queue_sessions',
                'sql' => "SELECT AVG(TIMESTAMPDIFF(MINUTE, started_at, finished_at)) AS avg_handle_minutes
                          FROM queue_sessions
                          WHERE queue_id = :qid AND finished_at IS NOT NULL",
                'column' => 'avg_handle_minutes',
            ],
        ];
    }

    foreach ($strategies as $strategy) {
        $key = $strategy['key'];
        if (array_key_exists($key, $availability) && $availability[$key] === false) {
            continue;
        }

        try {
            $st = $pdo->prepare($strategy['sql']);
            if (!$st->execute([':qid' => $queueId])) {
                $availability[$key] = false;
                continue;
            }
            $value = null;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row[$strategy['column']])) {
                $value = $row[$strategy['column']];
            } elseif ($row === false) {
                $value = $st->fetchColumn();
            }

            if ($value !== null && $value !== false) {
                $availability[$key] = true;
                return (float)$value;
            }

            $availability[$key] = true; // query worked but returned null
        } catch (Throwable $e) {
            $availability[$key] = false; // table/columns missing – don't retry each time
        }
    }

    return null;
}
