<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = require_login();
$pdo  = db();
require_role_or_higher($pdo, $user, 'manager');
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
if ($userId <= 0) {
    json_out(['error' => 'forbidden', 'message' => 'missing user id'], 403);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'method_not_allowed'], 405);
}

if (!table_exists($pdo, 'users') || !table_has_columns($pdo, 'users', ['user_id', 'name', 'email'])) {
    json_out(['error' => 'unsupported', 'message' => 'users table not available'], 500);
}

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$roster   = !empty($_GET['roster']);

if ($courseId > 0) {
    assert_manager_controls_course($pdo, $userId, $courseId);
}

if ($roster && $courseId > 0) {
    $rows = users_for_course($pdo, $courseId);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'name'     => $row['name'],
            'email'    => $row['email'],
            'enrolled' => true,
        ];
    }
    json_out($out);
}

if ($q === '' || strlen($q) < 2) {
    json_out([]);
}

$term = '%' . strtolower($q) . '%';
$sql = 'SELECT user_id, name, email FROM users WHERE LOWER(name) LIKE :term OR LOWER(email) LIKE :term ORDER BY name LIMIT 25';
$st  = $pdo->prepare($sql);
$st->execute([':term' => $term]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$enrolled = [];
if ($courseId > 0) {
    foreach (course_enrollment_user_ids($pdo, $courseId) as $uid) {
        $enrolled[$uid] = true;
    }
}

$out = [];
foreach ($rows as $row) {
    $uid = isset($row['user_id']) ? (int)$row['user_id'] : 0;
    $out[] = [
        'user_id'  => $uid,
        'name'     => $row['name'] ?? '',
        'email'    => $row['email'] ?? '',
        'enrolled' => $courseId > 0 ? !empty($enrolled[$uid]) : null,
    ];
}

json_out($out);
