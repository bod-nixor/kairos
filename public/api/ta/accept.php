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
$queueId   = isset($input['queue_id']) ? (int)$input['queue_id'] : 0;
$studentId = isset($input['user_id']) ? (int)$input['user_id'] : 0;

if ($queueId <= 0 || $studentId <= 0) {
    json_out(['error' => 'queue_id and user_id required'], 400);
}

$queueStmt = $pdo->prepare(
    'SELECT q.queue_id, q.room_id, r.course_id, q.name
     FROM queues_info q
     JOIN rooms r ON r.room_id = q.room_id
     WHERE q.queue_id = :qid
     LIMIT 1'
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

$waiting = $pdo->prepare('SELECT 1 FROM queue_entries WHERE queue_id = :qid AND user_id = :uid LIMIT 1');
$waiting->execute([':qid' => $queueId, ':uid' => $studentId]);
if (!$waiting->fetchColumn()) {
    json_out(['error' => 'student not waiting in queue'], 409);
}

$current = ta_active_assignment($pdo, $queueId);
if ($current && $current['student_user_id'] !== $studentId) {
    json_out(['error' => 'queue busy', 'serving' => $current], 409);
}

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM queue_entries WHERE queue_id = :qid AND user_id = :uid');
    $del->execute([':qid' => $queueId, ':uid' => $studentId]);

    $ins = $pdo->prepare(
        'INSERT INTO ta_assignments (ta_user_id, student_user_id, queue_id, started_at)
         VALUES (:ta, :stu, :qid, NOW())'
    );
    $ins->execute([
        ':ta'  => $ta['user_id'],
        ':stu' => $studentId,
        ':qid' => $queueId,
    ]);

    $assignmentId = null;
    $pk = ta_assignment_primary_key($pdo);
    if ($pk) {
        $assignmentId = $pdo->lastInsertId();
        if (!$assignmentId) {
            $idStmt = $pdo->prepare(
                'SELECT ' . $pk . ' FROM ta_assignments
                 WHERE ta_user_id = :ta AND student_user_id = :stu AND queue_id = :qid
                 ORDER BY started_at DESC LIMIT 1'
            );
            $idStmt->execute([
                ':ta'  => $ta['user_id'],
                ':stu' => $studentId,
                ':qid' => $queueId,
            ]);
            $assignmentId = $idStmt->fetchColumn();
        }
        if (is_numeric($assignmentId)) {
            $assignmentId = (int)$assignmentId;
        }
    }

    log_change($pdo, 'rooms', $queueId, $courseId);
    log_change($pdo, 'ta_accept', $queueId, $courseId);

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
    'user_id' => $studentId,
    'ta_id'   => $ta['user_id'] ?? null,
]);
emit_change($pdo, 'ta_accept', $queueId, $meta['course_id'] ?? null, [
    'user_id' => $studentId,
    'ta_id'   => $ta['user_id'] ?? null,
]);

$wsEvent = [
    'event'   => 'ta_accept',
    'ref_id'  => $assignmentId,
    'payload' => [
        'queue_id'          => $queueId,
        'student_user_id'   => $studentId,
        'ta_user_id'        => isset($ta['user_id']) ? (int)$ta['user_id'] : null,
    ],
];
if (!empty($meta['course_id'])) {
    $wsEvent['course_id'] = (int)$meta['course_id'];
}
if (!empty($queue['room_id'])) {
    $wsEvent['room_id'] = (int)$queue['room_id'];
}

$taNameStmt = $pdo->prepare('SELECT name FROM users WHERE user_id = :uid');
$taNameStmt->execute([':uid' => $ta['user_id']]);
$taName = $taNameStmt->fetchColumn() ?: '';

$wsEvent['payload']['ta_name'] = $taName;
ws_notify($wsEvent);

$studentName = queue_student_name($pdo, $studentId);
queue_ws_notify($pdo, $queueId, 'serve', [
    'student_id'           => $studentId,
    'student_name'         => $studentName,
    'serving_ta_id'        => $ta['user_id'] ?? null,
    'serving_ta_name'      => $taName,
    'serving_student_id'   => $studentId,
    'serving_student_name' => $studentName,
    'assignment_id'        => $assignmentId,
]);

json_out([
    'success'         => true,
    'assignment_id'   => $assignmentId,
    'queue_id'        => $queueId,
    'student_user_id' => $studentId,
    'ta_name'         => $taName,
]);
