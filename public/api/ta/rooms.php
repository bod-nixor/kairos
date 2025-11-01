<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($courseId <= 0) {
    json_out(['error' => 'course_id required'], 400);
}

if (!ta_has_course($pdo, (int)$user['user_id'], $courseId)) {
    json_out(['error' => 'forbidden', 'message' => 'Course not assigned'], 403);
}

$st = $pdo->prepare('SELECT room_id, course_id, name FROM rooms WHERE course_id = :cid ORDER BY name');
$st->execute([':cid' => $courseId]);
$rooms = $st->fetchAll();
json_out($rooms);
