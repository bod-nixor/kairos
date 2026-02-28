<?php
declare(strict_types=1);

function lms_normalize_allowed_file_extensions($value): string
{
    $raw = trim(strtolower((string) ($value ?? '')));
    if ($raw === '') {
        return '';
    }

    $parts = array_filter(
        array_map(static fn($v): string => trim(strtolower((string) $v)), explode(',', $raw)),
        static fn($v): bool => $v !== ''
    );
    $parts = array_values(array_unique($parts));

    foreach ($parts as $ext) {
        if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            lms_error('validation_error', 'allowed_file_extensions must be comma-separated extensions', 422);
        }
    }

    return implode(',', $parts);
}

function lms_clamp_max_file_mb($value, int $default = 50): int
{
    $candidate = $value;
    if ($candidate === null || $candidate === '') {
        $candidate = $default;
    }
    $maxFileMb = (int) $candidate;
    if ($maxFileMb < 1 || $maxFileMb > 1024) {
        lms_error('validation_error', 'max_file_mb must be between 1 and 1024', 422);
    }
    return $maxFileMb;
}

function lms_is_valid_assignment_status_transition(string $current, string $target): bool
{
    $allowedStatus = ['draft', 'published', 'archived'];
    $allowedTransitions = [
        'draft' => ['published', 'archived'],
        'published' => ['archived'],
        'archived' => [],
    ];
    if (!in_array($target, $allowedStatus, true)) {
        return false;
    }
    if ($current !== $target && !in_array($target, $allowedTransitions[$current] ?? [], true)) {
        return false;
    }
    return true;
}

function lms_can_update_assignment(array $roles): bool
{
    $allowed = ['manager', 'admin'];
    foreach ($roles as $role) {
        if (in_array(strtolower($role), $allowed, true)) {
            return true;
        }
    }
    return false;
}
