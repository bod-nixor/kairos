<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once dirname(__DIR__, 2) . '/src/rbac.php';

$user = require_login();
$pdo  = db();

/* No-cache headers so proxies don’t serve stale JSON */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$courseFilter = null;

if ($courseId > 0) {
    if (!rbac_course_exists($pdo, $courseId)) {
        json_out(['error' => 'not_found', 'message' => 'course not found'], 404);
    }
    if (!rbac_can_access_course($pdo, $user, $courseId)) {
        rbac_debug_deny('rooms.course.forbidden', [
            'user_id'   => rbac_user_id($user),
            'course_id' => $courseId,
        ]);
        json_out(['error' => 'forbidden', 'message' => 'course access denied'], 403);
    }
    $courseFilter = [$courseId];
} else {
    $courseFilter = rbac_accessible_course_ids($pdo, $user);
    if ($courseFilter !== null && !$courseFilter) {
        json_out([]);
    }
}

$sql = 'SELECT CAST(room_id AS UNSIGNED) AS room_id,'
     . '       CAST(course_id AS UNSIGNED) AS course_id,'
     . '       name'
     . '  FROM rooms';
$args = [];

if ($courseFilter !== null) {
    $placeholders = implode(',', array_fill(0, count($courseFilter), '?'));
    $sql .= " WHERE course_id IN ($placeholders)";
    $args = $courseFilter;
}

$sql .= ' ORDER BY name';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_out($rooms);