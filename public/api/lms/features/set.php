<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$user = lms_require_roles(['manager','admin']);
$input = lms_json_input();
$courseId = (int)($input['course_id'] ?? 0);
$flagKey = trim((string)($input['flag_key'] ?? ''));
$enabled = !empty($input['enabled']) ? 1 : 0;

if ($courseId <= 0 || $flagKey === '') {
    lms_error('validation_error', 'course_id and flag_key are required', 422);
}

$pdo = db();
$stmt = $pdo->prepare('INSERT INTO lms_feature_flags (course_id, flag_key, enabled, rollout_json, updated_by) VALUES (:course_id,:flag_key,:enabled,:rollout,:updated_by) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), rollout_json=VALUES(rollout_json), updated_by=VALUES(updated_by)');
$stmt->execute([
    ':course_id' => $courseId,
    ':flag_key' => $flagKey,
    ':enabled' => $enabled,
    ':rollout' => isset($input['rollout']) ? json_encode($input['rollout']) : null,
    ':updated_by' => (int)$user['user_id'],
]);
lms_ok(['course_id' => $courseId, 'flag_key' => $flagKey, 'enabled' => (bool)$enabled]);
