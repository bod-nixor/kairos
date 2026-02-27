<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();

$theme = array_key_exists('theme', $in) ? trim((string)$in['theme']) : null;
if ($theme !== null && $theme !== '' && !in_array($theme, ['light', 'dark'], true)) {
    lms_error('validation_error', 'theme must be light or dark', 422);
}
if ($theme === '') {
    $theme = null;
}

$gradient = array_key_exists('gradient', $in) ? trim((string)$in['gradient']) : 'ocean';
$allowedGradients = ['ocean', 'sunset', 'forest', 'violet'];
if (!in_array($gradient, $allowedGradients, true)) {
    $gradient = 'ocean';
}

$compactMode = !empty($in['compact_mode']) ? 1 : 0;
$reduceMotion = !empty($in['reduce_motion']) ? 1 : 0;

$pdo = db();
$pdo->prepare('INSERT INTO lms_user_ui_settings (user_id, theme, gradient_theme, compact_mode, reduce_motion)
    VALUES (:user_id, :theme, :gradient_theme, :compact_mode, :reduce_motion)
    ON DUPLICATE KEY UPDATE
        theme = VALUES(theme),
        gradient_theme = VALUES(gradient_theme),
        compact_mode = VALUES(compact_mode),
        reduce_motion = VALUES(reduce_motion),
        updated_at = CURRENT_TIMESTAMP')
    ->execute([
        ':user_id' => (int)$user['user_id'],
        ':theme' => $theme,
        ':gradient_theme' => $gradient,
        ':compact_mode' => $compactMode,
        ':reduce_motion' => $reduceMotion,
    ]);

lms_ok([
    'theme' => $theme,
    'gradient' => $gradient,
    'compact_mode' => $compactMode,
    'reduce_motion' => $reduceMotion,
]);
