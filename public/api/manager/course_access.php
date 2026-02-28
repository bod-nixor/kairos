<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$user = require_login();
$pdo = db();
require_role_or_higher($pdo, $user, 'manager');
$userId = (int)($user['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $courseId = (int)($in['course_id'] ?? 0);
    assert_manager_controls_course($pdo, $userId, $courseId);

    if (isset($in['visibility'])) {
        $visibility = strtolower((string)$in['visibility']) === 'restricted' ? 'restricted' : 'public';
        $st = $pdo->prepare('UPDATE courses SET visibility = :v WHERE course_id = :cid');
        $st->execute([':v' => $visibility, ':cid' => $courseId]);
    }

    if (!empty($in['allowlist_add']) && is_array($in['allowlist_add'])) {
        $st = $pdo->prepare('INSERT INTO course_allowlist (course_id, email, created_by) VALUES (:cid, :email, :uid) ON DUPLICATE KEY UPDATE created_by = VALUES(created_by)');
        foreach ($in['allowlist_add'] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $st->execute([':cid' => $courseId, ':email' => $email, ':uid' => $userId]);
        }
    }

    if (!empty($in['allowlist_remove']) && is_array($in['allowlist_remove'])) {
        $st = $pdo->prepare('DELETE FROM course_allowlist WHERE course_id = :cid AND LOWER(email) = LOWER(:email)');
        foreach ($in['allowlist_remove'] as $email) {
            $email = trim((string)$email);
            if ($email === '') continue;
            $st->execute([':cid' => $courseId, ':email' => $email]);
        }
    }

    if (!empty($in['pre_enroll_add']) && is_array($in['pre_enroll_add'])) {
        $pre = $pdo->prepare('INSERT INTO course_pre_enroll (course_id, email, created_by) VALUES (:cid, :email, :uid) ON DUPLICATE KEY UPDATE created_by = VALUES(created_by)');
        foreach ($in['pre_enroll_add'] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $pre->execute([':cid' => $courseId, ':email' => $email, ':uid' => $userId]);
            $enrollNow = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email)=LOWER(:email) LIMIT 1');
            $enrollNow->execute([':email' => $email]);
            $targetId = (int)($enrollNow->fetchColumn() ?: 0);
            if ($targetId > 0) {
                enroll_user_in_course($pdo, $targetId, $courseId);
            }
        }
    }

    json_out(['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'method_not_allowed'], 405);
}

$courseId = (int)($_GET['course_id'] ?? 0);
assert_manager_controls_course($pdo, $userId, $courseId);

$courseSt = $pdo->prepare('SELECT course_id, name, COALESCE(visibility, "public") AS visibility FROM courses WHERE course_id=:cid LIMIT 1');
$courseSt->execute([':cid' => $courseId]);
$course = $courseSt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    json_out(['error' => 'not_found'], 404);
}

$allow = $pdo->prepare('SELECT email FROM course_allowlist WHERE course_id=:cid ORDER BY email');
$allow->execute([':cid' => $courseId]);
$pre = $pdo->prepare('SELECT email FROM course_pre_enroll WHERE course_id=:cid ORDER BY email');
$pre->execute([':cid' => $courseId]);

json_out([
    'course_id' => (int)$course['course_id'],
    'name' => (string)$course['name'],
    'visibility' => (string)$course['visibility'],
    'allowlist' => $allow->fetchAll(PDO::FETCH_COLUMN),
    'pre_enroll' => $pre->fetchAll(PDO::FETCH_COLUMN),
]);
