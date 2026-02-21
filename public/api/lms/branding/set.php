<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lms_error('method_not_allowed', 'POST required', 405);
}
$user = lms_require_roles(['admin']);
$input = lms_json_input();
$name = trim((string)($input['institution_name'] ?? ''));
if ($name === '') {
    lms_error('validation_error', 'institution_name is required', 422);
}
$domains = $input['allowed_domains'] ?? [ALLOWED_DOMAIN];
if (!is_array($domains) || $domains === []) {
    lms_error('validation_error', 'allowed_domains must be a non-empty array', 422);
}
$pdo = db();
$stmt = $pdo->prepare('INSERT INTO lms_branding_config (institution_name, logo_url, primary_color, secondary_color, allowed_domains_json, updated_by) VALUES (:institution_name,:logo_url,:primary_color,:secondary_color,:allowed_domains_json,:updated_by)');
$stmt->execute([
    ':institution_name' => $name,
    ':logo_url' => $input['logo_url'] ?? null,
    ':primary_color' => $input['primary_color'] ?? null,
    ':secondary_color' => $input['secondary_color'] ?? null,
    ':allowed_domains_json' => json_encode(array_values($domains)),
    ':updated_by' => (int)$user['user_id'],
]);
lms_ok(['updated' => true]);
