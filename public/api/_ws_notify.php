<?php
declare(strict_types=1);

/**
 * Notify the local WebSocket bridge about a data change.
 */
function ws_notify(array $event): void {
    $secret = getenv('WS_SHARED_SECRET') ?: '';
    if ($secret === '') {
        return; // disabled when not configured
    }

    $script = getenv('WS_EMIT_BIN');
    if ($script === false || $script === null || $script === '') {
        $script = dirname(__DIR__, 2) . '/ws_emit.py';
    }
    if (!is_file($script)) {
        return;
    }

    $python = getenv('WS_PYTHON_BIN') ?: 'python3';

    $eventName = isset($event['event']) ? (string)$event['event'] : '';
    if ($eventName === '') {
        return;
    }

    $payload = array_key_exists('payload', $event) ? $event['payload'] : null;
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = 'null';
    }

    $parts = [
        escapeshellarg($python),
        escapeshellarg($script),
        '--event=' . escapeshellarg($eventName),
        '--secret=' . escapeshellarg($secret),
    ];

    foreach ([
        'course_id' => '--course-id=',
        'room_id'   => '--room-id=',
        'ref_id'    => '--ref-id=',
    ] as $key => $flag) {
        if (isset($event[$key]) && $event[$key] !== null && $event[$key] !== '') {
            $parts[] = $flag . escapeshellarg((string)(int)$event[$key]);
        }
    }

    $parts[] = '--payload=' . escapeshellarg($payloadJson);

    $cmd = implode(' ', $parts) . ' > /dev/null 2>&1 &';
    exec($cmd);
}
