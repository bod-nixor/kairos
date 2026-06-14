<?php
/**
 * GET /api/lms/modules.php?course_id=<id>
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/drive_client.php';

$user = require_login();
$courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

if ($courseId <= 0) {
    lms_error('bad_request', 'Missing or invalid course_id.', 400);
}

lms_course_access($user, $courseId);

$pdo = db();
$userId = (int) $user['user_id'];
$canManage = lms_can_view_unpublished($user, $courseId) ? 1 : 0;

$stmt = $pdo->prepare(
    'SELECT s.section_id, s.title AS name, s.description, s.position'
    . ' FROM lms_course_sections s'
    . ' WHERE s.course_id = :cid'
    . ' AND s.deleted_at IS NULL'
    . ' AND (:can_manage = 1 OR s.is_published = 1)'
    . ' ORDER BY s.position ASC, s.section_id ASC'
);
$stmt->execute([':cid' => $courseId, ':can_manage' => $canManage]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsStmt = $pdo->prepare(
    'SELECT mi.module_item_id, mi.section_id, mi.item_type, mi.entity_id, mi.title, mi.position,
            mi.required_flag, mi.published_flag,
            CASE
                WHEN mi.item_type = \'assignment\' THEN a.status
                WHEN mi.item_type = \'quiz\' THEN q.status
                WHEN mi.published_flag = 1 THEN \'published\'
                ELSE \'draft\'
            END AS status,
            a.due_at AS assignment_due_at, a.max_points,
            q.due_at AS quiz_due_at, q.time_limit_minutes,
            r.drive_file_id, r.drive_preview_url AS resource_url, r.metadata_json AS resource_metadata_json,
            CASE WHEN mi.item_type = \'lesson\' AND lc.completion_id IS NOT NULL THEN 1 ELSE 0 END AS completed
     FROM lms_module_items mi
     LEFT JOIN lms_lessons l ON mi.item_type = \'lesson\' AND l.lesson_id = mi.entity_id AND l.course_id = mi.course_id AND l.deleted_at IS NULL
     LEFT JOIN lms_assignments a ON mi.item_type = \'assignment\' AND a.assignment_id = mi.entity_id AND a.course_id = mi.course_id AND a.deleted_at IS NULL
     LEFT JOIN lms_assessments q ON mi.item_type = \'quiz\' AND q.assessment_id = mi.entity_id AND q.course_id = mi.course_id AND q.deleted_at IS NULL
     LEFT JOIN lms_lesson_completions lc ON mi.item_type = \'lesson\' AND lc.lesson_id = mi.entity_id AND lc.user_id = :uid
     LEFT JOIN lms_resources r ON mi.item_type IN (\'file\',\'video\',\'link\',\'resource\') AND r.resource_id = mi.entity_id AND r.course_id = mi.course_id AND r.deleted_at IS NULL
     WHERE mi.course_id = :cid
       AND (mi.published_flag = 1 OR :can_manage_item = 1)
       AND (
            (mi.item_type = \'lesson\' AND l.lesson_id IS NOT NULL)
         OR (mi.item_type = \'assignment\' AND a.assignment_id IS NOT NULL AND (:can_manage_assignment = 1 OR a.status = \'published\'))
         OR (mi.item_type = \'quiz\' AND q.assessment_id IS NOT NULL AND (:can_manage_quiz = 1 OR q.status = \'published\'))
         OR (mi.item_type IN (\'file\',\'video\',\'link\',\'resource\') AND r.resource_id IS NOT NULL)
       )
     ORDER BY mi.section_id, mi.position, mi.module_item_id'
);
$itemsStmt->execute([
    ':cid' => $courseId,
    ':uid' => $userId,
    ':can_manage_item' => $canManage,
    ':can_manage_assignment' => $canManage,
    ':can_manage_quiz' => $canManage,
]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$bySection = [];
foreach ($items as $item) {
    $sid = (int)$item['section_id'];
    if (!isset($bySection[$sid])) {
        $bySection[$sid] = [];
    }

    $meta = [];
    if (!empty($item['resource_metadata_json'])) {
        $decoded = json_decode((string)$item['resource_metadata_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    if (!empty($meta['url'])) {
        $item['resource_url'] = (string)$meta['url'];
    }
    if (!empty($item['drive_file_id'])) {
        $item['resource_url'] = lms_drive_internal_url((int)$item['entity_id']);
    }
    $item['mandatory'] = (int)$item['required_flag'];
    $item['published'] = (int)$item['published_flag'];
    $item['due_date'] = $item['assignment_due_at'] ?? $item['quiz_due_at'];
    $item['points'] = $item['max_points'];
    $item['duration_min'] = $item['time_limit_minutes'];
    unset($item['resource_metadata_json'], $item['drive_file_id']);
    unset($item['assignment_due_at'], $item['quiz_due_at'], $item['max_points'], $item['time_limit_minutes']);

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
