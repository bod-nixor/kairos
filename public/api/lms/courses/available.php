<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';

$user = require_login();
$pdo = db();
$courseRows = $pdo->query(
    'SELECT CAST(course_id AS UNSIGNED) AS course_id, name,'
    . '       COALESCE(code, "") AS code,'
    . '       COALESCE(visibility, "public") AS visibility'
    . '  FROM courses'
    . ' WHERE is_active = 1'
    . ' ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$available = [];
foreach ($courseRows as $row) {
    $courseId = (int)$row['course_id'];
    $access = rbac_course_access_context($pdo, $user, $courseId);
    if ($access['view_course'] || !$access['view_course_home']) {
        continue;
    }

    $available[] = [
        'course_id' => $courseId,
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'visibility' => (string)$row['visibility'],
        'access_context' => (string)($access['course_role'] ?? 'public'),
        'enrolled' => false,
        'assigned_staff' => false,
        'can_self_enroll' => (bool)$access['can_self_enroll'],
        'restricted' => ((string)$row['visibility']) !== 'public',
        'allowlisted' => (bool)$access['allowlisted'],
        'pre_enrolled' => (bool)$access['pre_enrolled'],
    ];
}

lms_ok(['courses' => $available]);
