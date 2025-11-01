<?php
declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../queue_helpers.php';

$ta = require_login();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$queueId = isset($data['queue_id']) ? (int)$data['queue_id'] : 0;
$userId  = isset($data['user_id']) ? (int)$data['user_id'] : 0;

if ($queueId <= 0 || $userId <= 0) {
    json_out(['error' => 'queue_id and user_id required'], 400);
}

try {
    $pdo->beginTransaction();

    $chk = $pdo->prepare("SELECT 1 FROM queue_entries WHERE queue_id = :qid AND user_id = :uid LIMIT 1");
    $chk->execute([':qid' => $queueId, ':uid' => $userId]);
    if (!$chk->fetchColumn()) {
        $pdo->rollBack();
        json_out(['error' => 'user not in queue'], 404);
    }

    $del = $pdo->prepare("DELETE FROM queue_entries WHERE queue_id = :qid AND user_id = :uid");
    $del->execute([':qid' => $queueId, ':uid' => $userId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

$meta = queue_meta($pdo, $queueId);

emit_change($pdo, 'queue', $queueId, $meta['course_id'] ?? null, [
    'action'  => 'accept',
    'user_id' => $userId,
    'ta_id'   => $ta['user_id'] ?? null,
]);
emit_change($pdo, 'ta_accept', $queueId, $meta['course_id'] ?? null, [
    'user_id' => $userId,
    'ta_id'   => $ta['user_id'] ?? null,
]);

json_out([
    'success'  => true,
    'ta_name'  => $ta['name'] ?? ($ta['email'] ?? ''),
    'user_id'  => $userId,
    'queue_id' => $queueId,
]);
