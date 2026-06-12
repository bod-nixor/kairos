<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/_queue_helpers.php';
require_once dirname(__DIR__, 2) . '/src/rbac.php';

$user = require_login();
$pdo  = db();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$queueId = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
if ($queueId <= 0) {
    json_out(['error' => 'queue_id required'], 400);
}

$scope = rbac_queue_scope($pdo, $queueId);
if (!$scope) {
    json_out(['error' => 'not_found', 'message' => 'Queue not found'], 404);
}
if (!rbac_can_view_queue($pdo, $user, $queueId, $scope)) {
    rbac_debug_deny('queue_eta_out_of_scope', [
        'user_id' => (int)($user['user_id'] ?? 0),
        'queue_id' => $queueId,
        'course_id' => (int)($scope['course_id'] ?? 0),
    ]);
    json_out(['error' => 'forbidden', 'message' => 'You cannot access this queue'], 403);
}

try {
    $snapshot = get_queue_snapshot($pdo, $queueId, isset($user['user_id']) ? (int)$user['user_id'] : null);
} catch (RuntimeException $e) {
    json_out(['error' => 'not_found', 'message' => $e->getMessage()], 404);
}

$avgForBasis = isset($snapshot['avg_used']) ? (float)$snapshot['avg_used'] : 7.0;
$bFactor      = $snapshot['basis_factor'];

json_out([
    'queue_id'    => $snapshot['queue_id'],
    'eta_minutes' => $snapshot['eta_minutes'],
    'basis'       => sprintf('%s*%d', rtrim(rtrim(number_format((float)$avgForBasis, 2, '.', ''), '0'), '.'), (int)$bFactor),
]);
