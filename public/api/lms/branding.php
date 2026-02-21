<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

lms_require_roles(['student','ta','manager','admin']);
$pdo = db();
$row = $pdo->query('SELECT institution_name, logo_url, primary_color, secondary_color, allowed_domains_json, updated_at FROM lms_branding_config ORDER BY id DESC LIMIT 1')->fetch();
if (!$row) {
    lms_ok(['institution_name' => 'Nixor College', 'allowed_domains' => [ALLOWED_DOMAIN]]);
}
$row['allowed_domains'] = json_decode((string)$row['allowed_domains_json'], true) ?: [ALLOWED_DOMAIN];
unset($row['allowed_domains_json']);
lms_ok($row);
