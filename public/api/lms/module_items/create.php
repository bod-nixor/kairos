<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';
require_once dirname(__DIR__) . '/lessons/_sanitize.php';

$user = lms_require_roles(['manager','admin']);
$in = lms_json_input();

$courseId = (int)($in['course_id'] ?? 0);
$sectionId = (int)($in['section_id'] ?? 0);
$itemType = strtolower(trim((string)($in['item_type'] ?? '')));
$title = trim((string)($in['title'] ?? ''));

if ($courseId <= 0 || $sectionId <= 0 || $title === '') {
    lms_error('validation_error', 'course_id, section_id, and title are required', 422);
}

$allowed = ['lesson','file','video','link','assignment','quiz'];
if (!in_array($itemType, $allowed, true)) {
    lms_error('validation_error', 'Unsupported item_type', 422);
}

$pdo = db();
$pdo->beginTransaction();

try {
    $entityId = 0;

    if ($itemType === 'lesson') {
        $stmt = $pdo->prepare('INSERT INTO lms_lessons (section_id,course_id,title,summary,html_content,position,requires_previous,created_by) VALUES (:s,:c,:t,:m,:h,0,0,:u)');
        $stmt->execute([
            ':s' => $sectionId,
            ':c' => $courseId,
            ':t' => $title,
            ':m' => $in['summary'] ?? null,
            ':h' => lms_sanitize_lesson_html((string)($in['html_content'] ?? '')),
            ':u' => (int)$user['user_id'],
        ]);
        $entityId = (int)$pdo->lastInsertId();
    } elseif (in_array($itemType, ['file','video','link'], true)) {
        $url = trim((string)($in['url'] ?? ''));
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            lms_error('validation_error', 'Valid url is required for file/video/link', 422);
        }
        $meta = json_encode(['url' => $url], JSON_THROW_ON_ERROR);
        $resourceType = $itemType === 'video' ? 'video' : ($itemType === 'link' ? 'link' : 'file');
        $stmt = $pdo->prepare('INSERT INTO lms_resources (course_id,title,resource_type,drive_preview_url,access_scope,metadata_json,created_by) VALUES (:c,:t,:rt,:url,\'course\',:meta,:u)');
        $stmt->execute([
            ':c' => $courseId,
            ':t' => $title,
            ':rt' => $resourceType,
            ':url' => $url,
            ':meta' => $meta,
            ':u' => (int)$user['user_id'],
        ]);
        $entityId = (int)$pdo->lastInsertId();
    } elseif ($itemType === 'assignment') {
        $existingId = (int)($in['assignment_id'] ?? 0);
        if ($existingId > 0) {
            $entityId = $existingId;
        } else {
            $stmt = $pdo->prepare('INSERT INTO lms_assignments (course_id,section_id,title,max_points,status,created_by) VALUES (:c,:s,:t,100,\'draft\',:u)');
            $stmt->execute([':c' => $courseId, ':s' => $sectionId, ':t' => $title, ':u' => (int)$user['user_id']]);
            $entityId = (int)$pdo->lastInsertId();
        }
    } elseif ($itemType === 'quiz') {
        $existingId = (int)($in['quiz_id'] ?? 0);
        if ($existingId > 0) {
            $entityId = $existingId;
        } else {
            $stmt = $pdo->prepare('INSERT INTO lms_assessments (course_id,section_id,title,assessment_type,status,max_attempts,created_by) VALUES (:c,:s,:t,\'quiz\',\'draft\',1,:u)');
            $stmt->execute([':c' => $courseId, ':s' => $sectionId, ':t' => $title, ':u' => (int)$user['user_id']]);
            $entityId = (int)$pdo->lastInsertId();
        }
    }

    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM lms_module_items WHERE section_id=:sid');
    $posStmt->execute([':sid' => $sectionId]);
    $position = (int)$posStmt->fetchColumn();

    $stmt = $pdo->prepare('INSERT INTO lms_module_items (course_id,section_id,item_type,entity_id,title,position,published_flag,created_by) VALUES (:c,:s,:it,:eid,:t,:p,1,:u)');
    $stmt->execute([
        ':c' => $courseId,
        ':s' => $sectionId,
        ':it' => $itemType,
        ':eid' => $entityId,
        ':t' => $title,
        ':p' => $position,
        ':u' => (int)$user['user_id'],
    ]);

    $moduleItemId = (int)$pdo->lastInsertId();
    $pdo->commit();

    lms_ok([
        'module_item_id' => $moduleItemId,
        'item_type' => $itemType,
        'entity_id' => $entityId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lms_error('server_error', 'Failed to create module item', 500);
}
