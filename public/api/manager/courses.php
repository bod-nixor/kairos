<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = require_login();
$pdo  = db();
ensure_manager_role($pdo, $user);
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
if ($userId <= 0) {
    json_out(['error' => 'forbidden', 'message' => 'missing user id'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'method_not_allowed'], 405);
}

if (!table_exists($pdo, 'courses') || !table_has_columns($pdo, 'courses', ['course_id', 'name'])) {
    json_out(['error' => 'unsupported', 'message' => 'courses table not available'], 500);
}

$ids = fetch_manager_course_ids($pdo, $userId);
if (!$ids) {
    json_out([]);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = 'SELECT CAST(course_id AS UNSIGNED) AS course_id, name FROM courses WHERE course_id IN ('.$placeholders.') ORDER BY name';
$st = $pdo->prepare($sql);
$st->execute($ids);
$rows = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = [
        'course_id' => isset($row['course_id']) ? (int)$row['course_id'] : null,
        'name'      => $row['name'] ?? '',
    ];
}

json_out($rows);
