<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

lms_require_roles(['manager', 'admin']);
$in = lms_json_input();
$id = (int)($in['section_id'] ?? 0);
if ($id <= 0) {
    lms_error('validation_error', 'section_id required', 422);
}

$allowed = [
    'title' => 'title',
    'description' => 'description',
    'position' => 'position',
];

$set = [];
$params = [':id' => $id];
foreach ($allowed as $inputKey => $column) {
    if (!array_key_exists($inputKey, $in)) {
        continue;
    }
    $param = ':' . $inputKey;
    $set[] = $column . '=' . $param;
    if ($inputKey === 'position') {
        $params[$param] = (int)$in[$inputKey];
    } elseif ($inputKey === 'title') {
        $params[$param] = trim((string)$in[$inputKey]);
    } else {
        $params[$param] = $in[$inputKey];
    }
}

if ($set === []) {
    lms_error('validation_error', 'No updatable fields provided', 422);
}

$set[] = 'updated_at=CURRENT_TIMESTAMP';
$sql = 'UPDATE lms_course_sections SET ' . implode(', ', $set) . ' WHERE section_id=:id';
$pdo = db();
$st = $pdo->prepare($sql);
$st->execute($params);

lms_ok(['updated' => $st->rowCount() > 0]);
