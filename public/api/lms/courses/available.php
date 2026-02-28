<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
$pdo = db();
$userId = (int)($user['user_id'] ?? 0);
$role = lms_user_role($user);
$email = strtolower((string)($user['email'] ?? ''));

$enrolledIds = [];
$enrollStmt = $pdo->prepare('SELECT course_id FROM student_courses WHERE user_id = :uid');
$enrollStmt->execute([':uid' => $userId]);
foreach ($enrollStmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $enrolledIds[(int)$cid] = true;
}

$courseRows = $pdo->query('SELECT CAST(course_id AS UNSIGNED) AS course_id, name, COALESCE(code, "") AS code, COALESCE(visibility, "public") AS visibility FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$allowlistedIds = [];
if ($email !== '') {
    $allowStmt = $pdo->prepare('SELECT course_id FROM course_allowlist WHERE LOWER(email) = :email');
    $allowStmt->execute([':email' => $email]);
    foreach ($allowStmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $allowlistedIds[(int)$cid] = true;
    }
}

$available = [];
foreach ($courseRows as $row) {
    $courseId = (int)$row['course_id'];
    if (isset($enrolledIds[$courseId])) {
        continue;
    }

    $isPublic = ((string)$row['visibility']) === 'public';
    $canJoin = $isPublic || isset($allowlistedIds[$courseId]);

    if ($role === 'admin') {
        $canJoin = true;
    }

    $available[] = [
        'course_id' => $courseId,
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'visibility' => (string)$row['visibility'],
        'can_self_enroll' => $canJoin,
        'restricted' => !$isPublic,
        'allowlisted' => isset($allowlistedIds[$courseId]),
    ];
}

lms_ok(['courses' => $available]);
