<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_feature(['sections', 'lms_content']);
$user = lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['section_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'section_id required', 422);
}

$pdo = db();
$sectionStmt = $pdo->prepare('SELECT section_id, course_id FROM lms_course_sections WHERE section_id=:id AND deleted_at IS NULL LIMIT 1');
$sectionStmt->execute([':id' => $id]);
$section = $sectionStmt->fetch();
if (!$section) {
    lms_error('not_found', 'Section not found', 404);
}
lms_course_access($user, (int)$section['course_id']);

$allowed = [
    'title' => 'title',
    'description' => 'description',
    'position' => 'position',
];

$set = [];
$params = [':id' => $id];
$changes = [];
foreach ($allowed as $inputKey => $column) {
    if (!array_key_exists($inputKey, $in)) {
        continue;
    }
    $param = ':' . $inputKey;
    $set[] = $column . '=' . $param;
    if ($inputKey === 'position') {
        $params[$param] = (int)$in[$inputKey];
    } elseif ($inputKey === 'title') {
        $trimmed = trim((string)$in[$inputKey]);
        if ($trimmed === '') {
            lms_error('validation_error', 'title cannot be blank', 400);
        }
        $params[$param] = $trimmed;
    } else {
        $params[$param] = trim((string)$in[$inputKey]);
    }
    $changes[$inputKey] = $params[$param];
}

if ($set === []) {
    lms_error('validation_error', 'No updatable fields provided', 422);
}

$set[] = 'updated_at=CURRENT_TIMESTAMP';
$sql = 'UPDATE lms_course_sections SET ' . implode(', ', $set) . ' WHERE section_id=:id AND deleted_at IS NULL';
$st = $pdo->prepare($sql);
$st->execute($params);

if ($st->rowCount() > 0) {
    lms_emit_event($pdo, 'section.updated', [
        'event_id' => lms_uuid_v4(),
        'occurred_at' => gmdate('c'),
        'actor_id' => (int)$user['user_id'],
        'entity_type' => 'section',
        'entity_id' => $id,
        'course_id' => (int)$section['course_id'],
        'changes' => $changes,
    ]);
}

lms_ok(['updated' => $st->rowCount() > 0]);
