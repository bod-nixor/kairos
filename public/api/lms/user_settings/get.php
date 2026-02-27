<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';

$user = lms_require_roles(['student', 'ta', 'manager', 'admin']);

$pdo = db();
$stmt = $pdo->prepare('SELECT theme, gradient_theme, compact_mode, reduce_motion, updated_at
    FROM lms_user_ui_settings
    WHERE user_id = :user_id
    LIMIT 1');
$stmt->execute([':user_id' => (int)$user['user_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    lms_ok([
        'theme' => null,
        'gradient' => 'ocean',
        'compact_mode' => 0,
        'reduce_motion' => 0,
    ]);
}

lms_ok([
    'theme' => $row['theme'] === null ? null : (string)$row['theme'],
    'gradient' => (string)($row['gradient_theme'] ?? 'ocean'),
    'compact_mode' => (int)($row['compact_mode'] ?? 0),
    'reduce_motion' => (int)($row['reduce_motion'] ?? 0),
    'updated_at' => $row['updated_at'] ?? null,
]);
