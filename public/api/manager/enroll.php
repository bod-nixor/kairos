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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method_not_allowed'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$targetId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
$courseId = isset($payload['course_id']) ? (int)$payload['course_id'] : 0;

if ($targetId <= 0 || $courseId <= 0) {
    json_out(['error' => 'invalid_parameters'], 400);
}

assert_manager_controls_course($pdo, $userId, $courseId);

if (table_exists($pdo, 'users') && table_has_columns($pdo, 'users', ['user_id'])) {
    $check = $pdo->prepare('SELECT 1 FROM users WHERE user_id = :uid LIMIT 1');
    $check->execute([':uid' => $targetId]);
    if (!$check->fetchColumn()) {
        json_out(['error' => 'not_found', 'message' => 'user not found'], 404);
    }
}

try {
    enroll_user_in_course($pdo, $targetId, $courseId);
    json_out(['success' => true, 'enrolled' => true]);
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}
