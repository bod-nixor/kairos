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

$flagsStmt = $pdo->prepare('SELECT course_id, flag_key, enabled FROM lms_feature_flags WHERE course_id IS NULL OR course_id IN (SELECT course_id FROM student_courses WHERE user_id = :uid)');
$flagsStmt->execute([':uid' => (int)$user['user_id']]);
$flags = $flagsStmt->fetchAll();

lms_ok([
    'user' => [
        'user_id' => (int)$user['user_id'],
        'email' => $user['email'] ?? null,
        'name' => $user['name'] ?? null,
        'role' => $user['role_name'],
    ],
    'allowed_domains' => $domains,
    'feature_flags' => $flags,
]);
