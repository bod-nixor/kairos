<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once dirname(__DIR__, 2) . '/../src/rbac.php';
[$pdo, $user] = require_ta_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($courseId <= 0) {
    json_out(['error' => 'course_id required'], 400);
}

if (!rbac_can_act_as_ta($pdo, $user, $courseId)) {
    json_out(['error' => 'forbidden', 'message' => 'Course not assigned'], 403);
}

$st = $pdo->prepare('SELECT room_id, course_id, name FROM rooms WHERE course_id = :cid ORDER BY name');
$st->execute([':cid' => $courseId]);
$rooms = $st->fetchAll();
json_out($rooms);
