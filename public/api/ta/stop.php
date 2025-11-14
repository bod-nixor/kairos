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
    json_out(['success' => true, 'already' => true]);
}

$taRank = ta_user_rank($pdo, (int)$ta['user_id']);
if (($assignment['ta_user_id'] ?? null) !== (int)$ta['user_id'] && $taRank < role_rank('manager')) {
    json_out(['error' => 'forbidden', 'message' => 'Only assigned TA or manager can stop serving'], 403);
}

$columns = ta_assignment_columns($pdo);
$updates = [];
if ($columns['ended_at']) {
    $updates[] = 'ended_at = NOW()';
}
if ($columns['completed_at']) {
    $updates[] = 'completed_at = NOW()';
}
if ($columns['finished_at']) {
    $updates[] = 'finished_at = NOW()';
}
if (!$updates) {
    // fall back to a no-op update so the query executes without altering data
    $updates[] = 'started_at = started_at';
}

$conditions = ['queue_id = :qid', 'student_user_id = :sid'];
$params = [
    ':qid' => $queueId,
    ':sid' => $assignment['student_user_id'] ?? null,
];

if (!empty($assignment['ta_assignment_id']) && $columns['ta_assignment_id']) {
    $conditions[] = 'ta_assignment_id = :aid';
    $params[':aid'] = $assignment['ta_assignment_id'];
} else {
    if (!empty($assignment['ta_user_id'])) {
        $conditions[] = 'ta_user_id = :tid';
        $params[':tid'] = $assignment['ta_user_id'];
    }
    if (!empty($assignment['started_at'])) {
        $conditions[] = 'started_at = :started';
        $params[':started'] = $assignment['started_at'];
    }
}

$pdo->beginTransaction();
$rowsUpdated = 0;
try {
    $sql = 'UPDATE ta_assignments SET ' . implode(', ', $updates)
         . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rowsUpdated = $st->rowCount();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}

$meta = queue_meta($pdo, $queueId);
emit_change($pdo, 'queue', $queueId, $meta['course_id'] ?? null, [
    'action'  => 'stop_serve',
    'user_id' => isset($assignment['student_user_id']) ? (int)$assignment['student_user_id'] : null,
    'ta_id'   => $ta['user_id'] ?? null,
]);

ta_log_audit_event($pdo, [
    'action'           => 'ta_stop_serving',
    'actor_user_id'    => (int)$ta['user_id'],
    'queue_id'         => $queueId,
    'student_user_id'  => isset($assignment['student_user_id']) ? (int)$assignment['student_user_id'] : null,
    'meta'             => [
        'assignment_id' => $assignment['ta_assignment_id'] ?? null,
        'started_at'    => $assignment['started_at'] ?? null,
    ],
]);

$studentId = isset($assignment['student_user_id']) ? (int)$assignment['student_user_id'] : null;
$studentName = $studentId ? queue_student_name($pdo, $studentId) : '';
queue_ws_notify($pdo, $queueId, 'stop_serve', [
    'student_id'           => $studentId,
    'student_name'         => $studentName,
    'serving_ta_id'        => null,
    'serving_ta_name'      => null,
    'serving_student_id'   => null,
    'serving_student_name' => null,
    'assignment_id'        => $assignment['ta_assignment_id'] ?? null,
    'extra_students'       => $studentId ? [[
        'id'    => $studentId,
        'name'  => $studentName,
        'status'=> 'done',
    ]] : [],
]);

json_out([
    'success' => true,
    'already' => $rowsUpdated === 0,
]);
