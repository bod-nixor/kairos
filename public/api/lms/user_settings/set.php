<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);
$in = lms_json_input();

$allowedGradients = ['ocean', 'sunset', 'forest', 'violet'];
$allowedThemes = ['light', 'dark'];

$pdo = db();
$existingStmt = $pdo->prepare('SELECT theme, gradient_theme, compact_mode, reduce_motion
    FROM lms_user_ui_settings
    WHERE user_id = :user_id
    LIMIT 1');
$existingStmt->execute([':user_id' => (int)$user['user_id']]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$theme = $existing['theme'] ?? null;
if (array_key_exists('theme', $in)) {
    $nextTheme = trim((string)$in['theme']);
    if ($nextTheme === '') {
        $theme = null;
    } else {
        $nextTheme = strtolower($nextTheme);
        if (!in_array($nextTheme, $allowedThemes, true)) {
            lms_error('validation_error', 'theme must be light or dark', 422);
        }
        $theme = $nextTheme;
    }
}

$gradient = strtolower(trim((string)($existing['gradient_theme'] ?? 'ocean')));
if (!in_array($gradient, $allowedGradients, true)) {
    $gradient = 'ocean';
}
if (array_key_exists('gradient', $in)) {
    $nextGradient = strtolower(trim((string)$in['gradient']));
    $gradient = in_array($nextGradient, $allowedGradients, true) ? $nextGradient : 'ocean';
}

$compactMode = isset($existing['compact_mode']) ? (int)$existing['compact_mode'] : 0;
if (array_key_exists('compact_mode', $in)) {
    $compactMode = filter_var($in['compact_mode'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

$reduceMotion = isset($existing['reduce_motion']) ? (int)$existing['reduce_motion'] : 0;
if (array_key_exists('reduce_motion', $in)) {
    $reduceMotion = filter_var($in['reduce_motion'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

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
