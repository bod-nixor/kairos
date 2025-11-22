<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../queue_helpers.php';
require_once __DIR__ . '/../_ws_notify.php';

[$pdo, $ta] = require_ta_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$queueId = isset($input['queue_id']) ? (int)$input['queue_id'] : 0;

if ($queueId <= 0) {
    json_out(['error' => 'queue_id required'], 400);
}

$queueStmt = $pdo->prepare(
    'SELECT q.queue_id, q.room_id, r.course_id, q.name '
    . 'FROM queues_info q '
    . 'JOIN rooms r ON r.room_id = q.room_id '
    . 'WHERE q.queue_id = :qid '
    . 'LIMIT 1'
);
$queueStmt->execute([':qid' => $queueId]);
$queue = $queueStmt->fetch();
if (!$queue) {
    json_out(['error' => 'queue not found'], 404);
}

$courseId = isset($queue['course_id']) ? (int)$queue['course_id'] : 0;
if (!ta_has_course($pdo, (int)$ta['user_id'], $courseId)) {
    json_out(['error' => 'forbidden', 'message' => 'Course not assigned'], 403);
}

$assignment = ta_active_assignment($pdo, $queueId);
if (!$assignment) {
    json_out(['error' => 'not_serving', 'message' => 'No active assignment'], 400);
}

$taRank = ta_user_rank($pdo, (int)$ta['user_id']);
if (($assignment['ta_user_id'] ?? null) !== (int)$ta['user_id'] && $taRank < role_rank('manager')) {
    json_out(['error' => 'forbidden', 'message' => 'Only the assigned TA can call again'], 403);
}

$studentId = isset($assignment['student_user_id']) ? (int)$assignment['student_user_id'] : 0;
$studentName = $assignment['student_name'] ?? queue_student_name($pdo, $studentId);
$taName = $assignment['ta_name'] ?? ($ta['name'] ?? '');

$payload = [
    'type'            => 'call_again',
    'queue_id'        => $queueId,
    'room_id'         => $queue['room_id'] ?? null,
    'course_id'       => $courseId ?: null,
    'student_user_id' => $studentId ?: null,
    'student_name'    => $studentName ?: '',
    'ta_user_id'      => $ta['user_id'] ?? null,
    'ta_name'         => $taName,
];

ws_notify([
    'event'     => 'projector_call_again',
    'course_id' => $courseId ?: null,
    'room_id'   => isset($queue['room_id']) ? (int)$queue['room_id'] : null,
    'ref_id'    => $assignment['ta_assignment_id'] ?? $queueId,
    'payload'   => $payload,
]);

json_out([
    'success' => true,
]);
