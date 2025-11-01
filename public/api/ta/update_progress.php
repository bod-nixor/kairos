<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$studentId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$detailId  = isset($input['detail_id']) ? (int)$input['detail_id'] : 0;
$status    = isset($input['status']) ? trim((string)$input['status']) : '';

if ($studentId <= 0 || $detailId <= 0) {
    json_out(['error' => 'user_id and detail_id required'], 400);
}

$detailStmt = $pdo->prepare('SELECT d.detail_id, d.category_id, c.course_id
                              FROM progress_details d
                              JOIN progress_category c ON c.category_id = d.category_id
                              WHERE d.detail_id = :did
                              LIMIT 1');
$detailStmt->execute([':did' => $detailId]);
$detail = $detailStmt->fetch();
if (!$detail) {
    json_out(['error' => 'detail not found'], 404);
}
$courseId = (int)$detail['course_id'];
if (!ta_has_course($pdo, (int)$user['user_id'], $courseId)) {
    json_out(['error' => 'forbidden'], 403);
}

$statusName = $status !== '' ? strtolower($status) : 'none';
$validNames = ['none', 'pending', 'completed', 'review'];
if (!in_array($statusName, $validNames, true)) {
    json_out(['error' => 'invalid status'], 400);
}

if ($statusName === 'none') {
    $del = $pdo->prepare('DELETE FROM progress WHERE user_id = :uid AND detail_id = :did');
    $del->execute([':uid' => $studentId, ':did' => $detailId]);
    log_change($pdo, 'progress', $detailId, $courseId);
    json_out(['success' => true, 'status' => 'None']);
}

$statusStmt = $pdo->prepare('SELECT progress_status_id FROM progress_status WHERE LOWER(name) = :name LIMIT 1');
$statusStmt->execute([':name' => $statusName]);
$statusId = $statusStmt->fetchColumn();
if (!$statusId) {
    json_out(['error' => 'status not found'], 400);
}

$ins = $pdo->prepare('INSERT INTO progress (user_id, detail_id, status_id)
                      VALUES (:uid, :did, :sid)
                      ON DUPLICATE KEY UPDATE status_id = VALUES(status_id)');
$ins->execute([
    ':uid' => $studentId,
    ':did' => $detailId,
    ':sid' => $statusId,
]);

log_change($pdo, 'progress', $detailId, $courseId);
json_out(['success' => true, 'status' => ucfirst($statusName)]);
