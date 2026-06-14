<?php
declare(strict_types=1);

require_once __DIR__ . '/lms/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$pdo = db();

$domains = [ALLOWED_DOMAIN];
$cfg = $pdo->query('SELECT allowed_domains_json FROM lms_branding_config ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($cfg) {
    $parsed = json_decode((string)$cfg, true);
    if (is_array($parsed) && $parsed) {
        $domains = array_values(array_filter(array_map('strval', $parsed)));
    }
}

$courseIds = rbac_accessible_course_ids($pdo, $user);
if ($courseIds === null) {
    $flagsStmt = $pdo->query('SELECT course_id, flag_key, enabled FROM lms_feature_flags');
} elseif ($courseIds) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $flagsStmt = $pdo->prepare(
        'SELECT course_id, flag_key, enabled'
        . ' FROM lms_feature_flags'
        . " WHERE course_id IS NULL OR course_id IN ($placeholders)"
    );
    $flagsStmt->execute($courseIds);
} else {
    $flagsStmt = $pdo->query('SELECT course_id, flag_key, enabled FROM lms_feature_flags WHERE course_id IS NULL');
}
$flags = $flagsStmt->fetchAll();

lms_ok([
    'user' => [
        'user_id' => (int)$user['user_id'],
        'email' => $user['email'] ?? null,
        'name' => $user['name'] ?? null,
        'role' => $user['role_name'],
        'role_updated_at' => $user['updated_at'] ?? null,
    ],
    'allowed_domains' => $domains,
    'feature_flags' => $flags,
    'capabilities' => [
        'access_model' => 'course-context-v2',
        'create_course' => rbac_can($pdo, $user, 'create_course'),
        'assign_course_staff' => rbac_can($pdo, $user, 'assign_course_staff'),
    ],
]);
