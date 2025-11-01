<?php
declare(strict_types=1);

$host = getenv('SIGNOFF_WS_HOST') ?: '0.0.0.0';
$port = (int)(getenv('SIGNOFF_WS_PORT') ?: 8090);

require_once __DIR__.'/../api/bootstrap.php';
require_once __DIR__.'/../api/ta/common.php';

$pdo = db();
session_write_close();

$hasPayload = change_log_has_payload($pdo);
$taPrimaryKey = ta_assignment_primary_key($pdo);
$allowedChannels = ['rooms', 'progress', 'queue', 'ta_accept'];

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to bind WebSocket server on {$host}:{$port} - {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "WebSocket server listening on {$host}:{$port}\n");

$clients = [];

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        if (isset($client['stream']) && is_resource($client['stream'])) {
            $read[] = $client['stream'];
        }
    }
    $write = [];
    $except = [];

    $changed = @stream_select($read, $write, $except, 1, 0);
    if ($changed === false) {
        continue;
    }

    if (in_array($server, $read, true)) {
        $conn = @stream_socket_accept($server, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $clients[(int)$conn] = create_client_state($conn);
        }
        $read = array_filter($read, static fn($stream) => $stream !== $server);
    }

    foreach ($read as $stream) {
        $id = (int)$stream;
        if (!isset($clients[$id])) {
            @fclose($stream);
            continue;
        }
        $chunk = @fread($stream, 8192);
        if ($chunk === '' || $chunk === false) {
            remove_client($clients, $id);
            continue;
        }

        $client = &$clients[$id];
        $client['buffer'] .= $chunk;

        if (!$client['handshake']) {
            if (strpos($client['buffer'], "\r\n\r\n") !== false) {
                if (!perform_handshake($client, $allowedChannels, $pdo)) {
                    remove_client($clients, $id);
                    unset($client);
                    continue;
                }
            }
        } else {
            while (true) {
                $frame = ws_parse_frame($client['buffer']);
                if ($frame === null) {
                    break;
                }
                if (!handle_client_frame($client, $frame, $clients, $id)) {
                    unset($client);
                    continue 2;
                }
            }
        }
        unset($client);
    }

    $now = microtime(true);
    foreach ($clients as $id => &$client) {
        if (!$client['handshake']) {
            continue;
        }

        if ($client['change_channels'] && ($now - $client['last_change_check']) >= 0.3) {
            $client['last_change_check'] = $now;
            $events = fetch_change_log_events($pdo, $client, $hasPayload);
            foreach ($events as $event) {
                ws_send_json($client['stream'], [
                    'type'  => 'event',
                    'event' => $event['channel'],
                    'data'  => $event,
                ]);
            }
        }

        if ($client['ta_enabled'] && ($now - $client['last_ta_check']) >= 0.5) {
            $client['last_ta_check'] = $now;
            $events = fetch_ta_events($pdo, $client, $taPrimaryKey);
            foreach ($events as $event) {
                ws_send_json($client['stream'], [
                    'type'  => 'event',
                    'event' => 'ta_accept',
                    'data'  => $event,
                ]);
            }
        }
    }
    unset($client);
}

function create_client_state($stream): array
{
    return [
        'stream'             => $stream,
        'buffer'             => '',
        'handshake'          => false,
        'headers'            => [],
        'path'               => '/',
        'params'             => [],
        'channels'           => [],
        'change_channels'    => [],
        'queue_filters'      => [],
        'course_id'          => 0,
        'room_id'            => 0,
        'last_change_id'     => 0,
        'last_change_check'  => 0.0,
        'ta_enabled'         => false,
        'last_ta_id'         => 0,
        'last_ta_check'      => 0.0,
        'user'               => null,
    ];
}

function perform_handshake(array &$client, array $allowedChannels, PDO $pdo): bool
{
    $request = $client['buffer'];
    [$headerPart] = explode("\r\n\r\n", $request, 2);
    $lines = explode("\r\n", $headerPart);
    $requestLine = array_shift($lines);
    if (!$requestLine || stripos($requestLine, 'GET') !== 0) {
        send_http_response($client['stream'], 400, 'Bad Request', 'Invalid request.');
        return false;
    }

    $parts = explode(' ', $requestLine);
    $target = $parts[1] ?? '/';
    $uri = parse_url($target) ?: [];
    $client['path'] = $uri['path'] ?? '/';
    parse_str($uri['query'] ?? '', $params);
    $client['params'] = $params;

    $headers = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    $client['headers'] = $headers;

    $secKey = $headers['sec-websocket-key'] ?? '';
    if ($secKey === '') {
        send_http_response($client['stream'], 400, 'Bad Request', 'Missing Sec-WebSocket-Key header.');
        return false;
    }

    $user = authenticate_client($headers);
    if (!$user) {
        send_http_response($client['stream'], 401, 'Unauthorized', 'Authentication required.');
        return false;
    }
    $client['user'] = $user;

    $channels = [];
    if (!empty($params['channels'])) {
        $pieces = is_array($params['channels']) ? $params['channels'] : explode(',', (string)$params['channels']);
        foreach ($pieces as $piece) {
            $piece = trim(strtolower((string)$piece));
            if ($piece === '') {
                continue;
            }
            if (in_array($piece, $allowedChannels, true) && !in_array($piece, $channels, true)) {
                $channels[] = $piece;
            }
        }
    }
    if (!$channels) {
        $channels = ['rooms', 'progress'];
    }
    $client['channels'] = $channels;
    $client['change_channels'] = array_values(array_intersect($channels, ['rooms', 'progress', 'queue']));
    $client['ta_enabled'] = in_array('ta_accept', $channels, true);

    $client['course_id'] = isset($params['course_id']) ? (int)$params['course_id'] : 0;
    $client['room_id'] = isset($params['room_id']) ? (int)$params['room_id'] : 0;

    $queueFilters = [];
    if (isset($params['queue_id'])) {
        $raw = is_array($params['queue_id']) ? $params['queue_id'] : explode(',', (string)$params['queue_id']);
        foreach ($raw as $piece) {
            $piece = trim((string)$piece);
            if ($piece === '' || !ctype_digit($piece)) {
                continue;
            }
            $val = (int)$piece;
            if ($val > 0) {
                $queueFilters[] = $val;
            }
        }
    }
    $client['queue_filters'] = array_values(array_unique($queueFilters));

    $since = isset($params['since']) ? (int)$params['since'] : 0;
    if ($since > 0) {
        $client['last_change_id'] = $since;
    }
    $taSince = isset($params['ta_since']) ? (int)$params['ta_since'] : $since;
    if ($taSince > 0) {
        $client['last_ta_id'] = $taSince;
    }

    $acceptKey = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: {$acceptKey}\r\n" .
        "\r\n";
    @fwrite($client['stream'], $response);

    $client['handshake'] = true;
    $client['buffer'] = '';
    $client['last_change_check'] = microtime(true);
    $client['last_ta_check'] = microtime(true);

    return true;
}

function authenticate_client(array $headers): ?array
{
    $cookieHeader = $headers['cookie'] ?? '';
    if ($cookieHeader === '') {
        return null;
    }
    $sessionId = extract_session_id($cookieHeader);
    if (!$sessionId) {
        return null;
    }
    session_write_close();
    session_id($sessionId);
    session_start();
    $user = $_SESSION['user'] ?? null;
    session_write_close();
    return is_array($user) ? $user : null;
}

function extract_session_id(string $cookieHeader): ?string
{
    $sessionName = session_name();
    foreach (explode(';', $cookieHeader) as $piece) {
        $segment = trim($piece);
        if ($segment === '') {
            continue;
        }
        if (stripos($segment, $sessionName.'=') === 0) {
            $parts = explode('=', $segment, 2);
            if (count($parts) === 2) {
                return urldecode($parts[1]);
            }
        }
    }
    return null;
}

function handle_client_frame(array &$client, array $frame, array &$clients, int $id): bool
{
    $opcode = $frame['opcode'];
    if ($opcode === 0x8) {
        @fwrite($client['stream'], ws_encode_close());
        remove_client($clients, $id);
        return false;
    }
    if ($opcode === 0x9) {
        @fwrite($client['stream'], ws_encode_frame($frame['payload'], 0xA));
        return true;
    }
    // Ignore other opcodes (text/binary frames) – clients do not need to send data.
    return true;
}

function remove_client(array &$clients, int $id): void
{
    if (!isset($clients[$id])) {
        return;
    }
    $client = $clients[$id];
    if (isset($client['stream']) && is_resource($client['stream'])) {
        @fclose($client['stream']);
    }
    unset($clients[$id]);
}

function ws_parse_frame(string &$buffer): ?array
{
    $length = strlen($buffer);
    if ($length < 2) {
        return null;
    }
    $b1 = ord($buffer[0]);
    $b2 = ord($buffer[1]);
    $fin = ($b1 & 0x80) !== 0;
    $opcode = $b1 & 0x0f;
    $masked = ($b2 & 0x80) !== 0;
    $payloadLen = $b2 & 0x7f;
    $offset = 2;

    if ($payloadLen === 126) {
        if ($length < 4) {
            return null;
        }
        $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
        $offset = 4;
    } elseif ($payloadLen === 127) {
        if ($length < 10) {
            return null;
        }
        $parts = unpack('N2', substr($buffer, 2, 8));
        $payloadLen = ($parts[1] << 32) | $parts[2];
        $offset = 10;
    }

    $mask = '';
    if ($masked) {
        if ($length < $offset + 4) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
    }

    if ($length < $offset + $payloadLen) {
        return null;
    }

    $payload = substr($buffer, $offset, $payloadLen);
    $buffer = substr($buffer, $offset + $payloadLen);

    if ($masked && $mask !== '') {
        $payload = ws_apply_mask($payload, $mask);
    }

    return [
        'fin'     => $fin,
        'opcode'  => $opcode,
        'payload' => $payload,
    ];
}

function ws_apply_mask(string $payload, string $mask): string
{
    $result = '';
    $len = strlen($payload);
    for ($i = 0; $i < $len; $i++) {
        $result .= $payload[$i] ^ $mask[$i % 4];
    }
    return $result;
}

function ws_encode_frame(string $payload, int $opcode = 0x1): string
{
    $frame = chr(0x80 | ($opcode & 0x0f));
    $length = strlen($payload);
    if ($length <= 125) {
        $frame .= chr($length);
    } elseif ($length <= 65535) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $high = intdiv($length, 0x100000000);
        $low = $length & 0xffffffff;
        $frame .= chr(127) . pack('N2', $high, $low);
    }
    return $frame . $payload;
}

function ws_encode_close(int $code = 1000, string $reason = ''): string
{
    $payload = pack('n', $code) . $reason;
    return ws_encode_frame($payload, 0x8);
}

function ws_send_json($stream, array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    @fwrite($stream, ws_encode_frame($payload, 0x1));
}

function send_http_response($stream, int $status, string $title, string $body): void
{
    $payload = (string)$body;
    $headers = [
        "HTTP/1.1 {$status} {$title}",
        'Content-Type: text/plain; charset=utf-8',
        'Connection: close',
        'Content-Length: ' . strlen($payload),
        '',
        $payload,
    ];
    @fwrite($stream, implode("\r\n", $headers));
}

function fetch_change_log_events(PDO $pdo, array &$client, bool $hasPayload): array
{
    $channels = $client['change_channels'];
    if (!$channels) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($channels), '?'));
    $sql = "SELECT id, channel, ref_id, course_id, UNIX_TIMESTAMP(created_at) AS ts" .
           ($hasPayload ? ', payload_json' : '') .
           " FROM change_log WHERE id > ? AND channel IN ({$placeholders})";
    $args = array_merge([$client['last_change_id']], $channels);

    if ($client['course_id'] > 0) {
        $sql .= " AND (course_id = ? OR course_id IS NULL)";
        $args[] = $client['course_id'];
    }
    if ($client['queue_filters']) {
        $queuePlaceholders = implode(',', array_fill(0, count($client['queue_filters']), '?'));
        $sql .= " AND ref_id IN ({$queuePlaceholders})";
        foreach ($client['queue_filters'] as $fid) {
            $args[] = $fid;
        }
    }
    if ($client['room_id'] > 0) {
        $sql .= " AND (channel NOT IN ('queue','ta_accept') OR ref_id = ?)";
        $args[] = $client['room_id'];
    }
    $sql .= ' ORDER BY id ASC LIMIT 100';

    $st = $pdo->prepare($sql);
    if (!$st->execute($args)) {
        return [];
    }
    $rows = $st->fetchAll();
    if (!$rows) {
        return [];
    }

    $events = [];
    foreach ($rows as $row) {
        $eventId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($eventId <= $client['last_change_id']) {
            continue;
        }
        $client['last_change_id'] = $eventId;
        $event = [
            'id'        => $eventId,
            'channel'   => $row['channel'],
            'ref_id'    => isset($row['ref_id']) ? (int)$row['ref_id'] : null,
            'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
            'ts'        => isset($row['ts']) ? (int)$row['ts'] : null,
        ];
        if ($hasPayload && array_key_exists('payload_json', $row) && $row['payload_json'] !== null && $row['payload_json'] !== '') {
            $decoded = json_decode($row['payload_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $event['payload'] = $decoded;
            }
        }
        $events[] = $event;
    }
    return $events;
}

function fetch_ta_events(PDO $pdo, array &$client, ?string $primaryKey): array
{
    if (!$client['ta_enabled']) {
        return [];
    }
    $userId = isset($client['user']['user_id']) ? (int)$client['user']['user_id'] : 0;
    if ($userId <= 0) {
        return [];
    }
    if (!table_exists($pdo, 'ta_assignments')) {
        return [];
    }

    if ($primaryKey) {
        $sql = "SELECT CAST(ta.{$primaryKey} AS UNSIGNED) AS event_id, ta.queue_id, ta.student_user_id, ta.ta_user_id, ta.started_at, tu.name AS ta_name" .
               " FROM ta_assignments ta JOIN users tu ON tu.user_id = ta.ta_user_id" .
               " WHERE ta.student_user_id = :uid AND ta.{$primaryKey} > :last" .
               " ORDER BY ta.{$primaryKey} ASC LIMIT 20";
        $args = [':uid' => $userId, ':last' => $client['last_ta_id']];
    } else {
        $expr = '(UNIX_TIMESTAMP(ta.started_at) * 1000) + ta.queue_id';
        $sql = "SELECT CAST({$expr} AS UNSIGNED) AS event_id, ta.queue_id, ta.student_user_id, ta.ta_user_id, ta.started_at, tu.name AS ta_name" .
               " FROM ta_assignments ta JOIN users tu ON tu.user_id = ta.ta_user_id" .
               " WHERE ta.student_user_id = :uid AND {$expr} > :last" .
               " ORDER BY ta.started_at ASC LIMIT 20";
        $args = [':uid' => $userId, ':last' => $client['last_ta_id']];
    }

    $st = $pdo->prepare($sql);
    if (!$st->execute($args)) {
        return [];
    }
    $rows = $st->fetchAll();
    if (!$rows) {
        return [];
    }

    $events = [];
    foreach ($rows as $row) {
        $eventId = isset($row['event_id']) ? (int)$row['event_id'] : 0;
        if ($eventId <= $client['last_ta_id']) {
            continue;
        }
        $client['last_ta_id'] = $eventId;
        $assignmentId = null;
        if ($primaryKey && isset($row['event_id'])) {
            $assignmentId = is_numeric($row['event_id']) ? (int)$row['event_id'] : $row['event_id'];
        }
        $events[] = [
            'queue_id'      => isset($row['queue_id']) ? (int)$row['queue_id'] : null,
            'user_id'       => isset($row['student_user_id']) ? (int)$row['student_user_id'] : null,
            'ta_user_id'    => isset($row['ta_user_id']) ? (int)$row['ta_user_id'] : null,
            'ta_name'       => $row['ta_name'] ?? '',
            'started_at'    => $row['started_at'] ?? null,
            'assignment_id' => $assignmentId,
        ];
    }
    return $events;
}

function change_log_has_payload(PDO $pdo): bool
{
    try {
        $sql = "SELECT 1 FROM information_schema.COLUMNS" .
               " WHERE TABLE_SCHEMA = DATABASE()" .
               "   AND TABLE_NAME = 'change_log'" .
               "   AND COLUMN_NAME = 'payload_json' LIMIT 1";
        $st = $pdo->query($sql);
        return $st && $st->fetchColumn() ? true : false;
    } catch (Throwable $e) {
        return false;
    }
}
