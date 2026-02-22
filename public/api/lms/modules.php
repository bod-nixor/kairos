<?php
/**
 * GET /api/lms/modules.php?course_id=<id>
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$user = require_login();
$courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course_id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();
$userId = (int) $user['user_id'];
$role = lms_user_role($user);
$isStaff = in_array($role, ['admin', 'manager', 'ta'], true) ? 1 : 0;

$stmt = $pdo->prepare('SELECT s.section_id, s.title AS name, s.description, s.position FROM lms_course_sections s WHERE s.course_id = :cid AND s.deleted_at IS NULL ORDER BY s.position ASC, s.section_id ASC');
$stmt->execute([':cid' => $courseId]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$moduleItemsEnabled = lms_feature_enabled('module_items', $courseId);
if (!$moduleItemsEnabled) {
    foreach ($modules as &$module) {
        $module['items'] = [];
        $module['total_items'] = 0;
        $module['completed_items'] = 0;
    }
    unset($module);
    lms_ok($modules);
}

$itemsStmt = $pdo->prepare(
    'SELECT mi.module_item_id, mi.section_id, mi.item_type, mi.entity_id, mi.title, mi.position,
            -- Completion tracking is intentionally lesson-only for now.
            CASE WHEN mi.item_type = \'lesson\' AND lc.completion_id IS NOT NULL THEN 1 ELSE 0 END AS completed
     FROM lms_module_items mi
     LEFT JOIN lms_lesson_completions lc ON mi.item_type = \'lesson\' AND lc.lesson_id = mi.entity_id AND lc.user_id = :uid
     WHERE mi.course_id = :cid
       AND (mi.published_flag = 1 OR :is_staff = 1)
     ORDER BY mi.section_id, mi.position, mi.module_item_id'
);
$itemsStmt->execute([':cid' => $courseId, ':uid' => $userId, ':is_staff' => $isStaff]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$bySection = [];
foreach ($items as $item) {
    $sid = (int)$item['section_id'];
    if (!isset($bySection[$sid])) {
        $bySection[$sid] = [];
    }
    $bySection[$sid][] = $item;
}

foreach ($modules as &$module) {
    $sid = (int)$module['section_id'];
    $module['items'] = $bySection[$sid] ?? [];
    $module['total_items'] = count($module['items']);
    $module['completed_items'] = count(array_filter($module['items'], static fn($it) => (int)($it['completed'] ?? 0) === 1));
}
unset($module);

lms_ok($modules);
