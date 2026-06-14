<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

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

$enrolled = [];
$available = [];
foreach ($courseRows as $row) {
    $courseId = (int)$row['course_id'];
    $access = rbac_course_access_context($pdo, $user, $courseId);
    $item = [
        'course_id' => $courseId,
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'visibility' => (string)$row['visibility'],
        'enrolled' => (bool)$access['view_course_enrolled'],
        'assigned_staff' => (bool)($access['assigned_ta'] || $access['assigned_manager']),
        'access_context' => (string)($access['course_role'] ?? 'public'),
        'can_self_enroll' => (bool)$access['can_self_enroll'],
        'capabilities' => [
            'view_course_public' => (bool)$access['view_course_public'],
            'view_course' => (bool)$access['view_course'],
            'participate_as_student' => (bool)$access['participate_as_student'],
            'grade_course' => (bool)$access['grade_course'],
            'manage_course' => (bool)$access['manage_course'],
            'admin_course' => (bool)$access['admin_course'],
        ],
    ];

    if ($access['view_course']) {
        $enrolled[] = $item;
        continue;
    }

    if (!$access['view_course_home']) {
        continue;
    }

    $item['allowlisted'] = (bool)$access['allowlisted'];
    $item['pre_enrolled'] = (bool)$access['pre_enrolled'];
    $available[] = $item;
}

lms_ok([
    'enrolled' => $enrolled,
    'available' => $available,
]);
