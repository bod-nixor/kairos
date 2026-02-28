<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$pdo = db();
$userId = (int)($user['user_id'] ?? 0);
$role = lms_user_role($user);

$enrolledIds = [];
$enrollStmt = $pdo->prepare('SELECT course_id FROM student_courses WHERE user_id = :uid');
$enrollStmt->execute([':uid' => $userId]);
foreach ($enrollStmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $enrolledIds[(int)$cid] = true;
}

$courseRows = $pdo->query('SELECT course_id, name, COALESCE(code, "") AS code, COALESCE(visibility, "public") AS visibility FROM courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$eligibleRestricted = [];
if ($role === 'student') {
    $allowStmt = $pdo->prepare('SELECT course_id FROM course_allowlist WHERE LOWER(email) = LOWER(:email)');
    $allowStmt->execute([':email' => (string)($user['email'] ?? '')]);
    foreach ($allowStmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $eligibleRestricted[(int)$cid] = true;
    }
}

$enrolled = [];
$available = [];

foreach ($courseRows as $row) {
    $cid = (int)$row['course_id'];
    $item = [
        'course_id' => $cid,
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'visibility' => (string)$row['visibility'],
        'enrolled' => isset($enrolledIds[$cid]),
    ];

    if ($item['enrolled']) {
        $enrolled[] = $item;
        continue;
    }

    $canSelfEnroll = false;
    if ($role === 'student') {
        $canSelfEnroll = $item['visibility'] === 'public' || isset($eligibleRestricted[$cid]);
        if ($canSelfEnroll === false) {
            continue;
        }
    }

    $item['can_self_enroll'] = $canSelfEnroll;
    $available[] = $item;
}

lms_ok([
    'enrolled' => $enrolled,
    'available' => $available,
]);
