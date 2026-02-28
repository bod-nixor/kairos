<?php
declare(strict_types=1);

require_once __DIR__ . '/_settings_common.php';

$user = require_login();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$courseId = (int)($_GET['course_id'] ?? 0);
if ($method !== 'GET') {
    $in = lms_json_input();
    $courseId = (int)($in['course_id'] ?? $courseId);
} else {
    $in = [];
}
if ($courseId <= 0) {
    lms_error('validation_error', 'course_id is required.', 422);
}
if (!lms_course_exists($pdo, $courseId)) {
    lms_error('not_found', 'Course not found.', 404);
}
lms_require_course_manager_or_admin($pdo, $user, $courseId);

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, email, created_at, created_by, claimed_user_id FROM course_pre_enroll WHERE course_id = :cid ORDER BY created_at DESC, id DESC');
    $stmt->execute([':cid' => $courseId]);
    $entries = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $entries[] = [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'created_at' => (string)$row['created_at'],
            'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
            'claimed_user_id' => isset($row['claimed_user_id']) && $row['claimed_user_id'] !== null ? (int)$row['claimed_user_id'] : null,
            'status' => isset($row['claimed_user_id']) && $row['claimed_user_id'] !== null ? 'claimed' : 'unclaimed',
        ];
    }
    lms_ok(['course_id' => $courseId, 'entries' => $entries]);
}

if ($method === 'POST') {
    $email = lms_normalize_email((string)($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        lms_error('validation_error', 'Valid email is required.', 422);
    }

    $stmt = $pdo->prepare('INSERT INTO course_pre_enroll (course_id, email, created_by, claimed_user_id) VALUES (:cid, :email, :created_by, NULL) ON DUPLICATE KEY UPDATE created_by = VALUES(created_by)');
    $stmt->execute([
        ':cid' => $courseId,
        ':email' => $email,
        ':created_by' => (int)($user['user_id'] ?? 0),
    ]);
    lms_ok(['course_id' => $courseId, 'email' => $email]);
}

if ($method === 'DELETE') {
    $entryId = (int)($in['id'] ?? 0);
    $email = lms_normalize_email((string)($in['email'] ?? ''));
    if ($entryId <= 0 && $email === '') {
        lms_error('validation_error', 'id or email is required.', 422);
    }

    if ($entryId > 0) {
        $stmt = $pdo->prepare('DELETE FROM course_pre_enroll WHERE course_id = :cid AND id = :id LIMIT 1');
        $stmt->execute([':cid' => $courseId, ':id' => $entryId]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM course_pre_enroll WHERE course_id = :cid AND LOWER(email) = :email LIMIT 1');
        $stmt->execute([':cid' => $courseId, ':email' => $email]);
    }

    lms_ok(['course_id' => $courseId, 'deleted' => ($stmt->rowCount() > 0)]);
}

lms_error('method_not_allowed', 'Method not allowed', 405);
