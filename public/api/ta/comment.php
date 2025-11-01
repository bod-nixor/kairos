<?php
declare(strict_types=1);

require_once __DIR__.'/common.php';
[$pdo, $user] = require_ta_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

if (!table_exists($pdo, 'ta_comments')) {
    json_out(['error' => 'comments table missing'], 500);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$studentId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$courseId  = isset($input['course_id']) ? (int)$input['course_id'] : 0;
$text      = isset($input['text']) ? trim((string)$input['text']) : '';

if ($studentId <= 0 || $courseId <= 0 || $text === '') {
    json_out(['error' => 'user_id, course_id and text required'], 400);
}

if (!ta_has_course($pdo, (int)$user['user_id'], $courseId)) {
    json_out(['error' => 'forbidden'], 403);
}

$ins = $pdo->prepare('INSERT INTO ta_comments (user_id, course_id, ta_user_id, text, created_at)
                      VALUES (:uid, :cid, :ta, :text, NOW())');
$ins->execute([
    ':uid'  => $studentId,
    ':cid'  => $courseId,
    ':ta'   => $user['user_id'],
    ':text' => $text,
]);

json_out([
    'success' => true,
    'comment' => [
        'user_id'    => $studentId,
        'course_id'  => $courseId,
        'ta_user_id' => (int)$user['user_id'],
        'ta_name'    => $user['name'] ?? '',
        'text'       => $text,
        'created_at' => date('Y-m-d H:i:s'),
    ],
]);
