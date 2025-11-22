<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

if (empty($_SESSION['user'])) {
  json_out([]);
}

$user = $_SESSION['user'];
$wsInfo = [
  'ws_url' => null,
  'token' => null,
  'user_id' => isset($user['user_id']) ? (int)$user['user_id'] : null,
  'ts' => null,
];

$publicUrl = env('WS_PUBLIC_URL', '');
if ($publicUrl !== '') {
  $publicUrl = rtrim($publicUrl, '/');
}

$socketPath = env('WS_SOCKET_PATH', '/websocket/socket.io');
if (!is_string($socketPath) || $socketPath === '') {
  $socketPath = '/websocket/socket.io';
}

$secret = env('WS_SHARED_SECRET', '');
if ($secret !== '' && $publicUrl !== '' && !empty($user['user_id'])) {
  $ts = time();
  $uid = (int)$user['user_id'];
  $raw = $uid . '|' . $ts;
  $token = hash_hmac('sha256', $raw, $secret);
  $wsInfo['ws_url'] = $publicUrl;
  $wsInfo['token'] = $token . '.' . $ts . '.' . $uid;
  $wsInfo['ts'] = $ts;
  $wsInfo['socket_path'] = '/' . ltrim($socketPath, '/');
} else {
  $wsInfo['ws_url'] = $publicUrl !== '' ? $publicUrl : null;
}

$user['ws'] = $wsInfo;

json_out($user);
