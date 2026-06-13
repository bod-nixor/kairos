<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/drive_client.php';

function lms_assignment_file_type_presets(): array
{
    return [
        'documents' => ['label' => 'Documents', 'extensions' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt']],
        'images' => ['label' => 'Images', 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp']],
        'video' => ['label' => 'Video', 'extensions' => ['mp4', 'mov', 'webm', 'm4v']],
        'audio' => ['label' => 'Audio', 'extensions' => ['mp3', 'wav', 'm4a', 'ogg']],
        'archives' => ['label' => 'Archives', 'extensions' => ['zip', 'rar', '7z', 'tar', 'gz']],
        'code' => ['label' => 'Code', 'extensions' => ['json', 'py', 'java', 'c', 'cpp', 'h', 'sql', 'md']],
        'pdf' => ['label' => 'PDF only', 'extensions' => ['pdf']],
        'spreadsheets' => ['label' => 'Spreadsheets', 'extensions' => ['xls', 'xlsx', 'csv', 'ods']],
        'presentations' => ['label' => 'Presentations', 'extensions' => ['ppt', 'pptx', 'odp']],
        'custom' => ['label' => 'Custom', 'extensions' => []],
    ];
}

function lms_assignment_dangerous_extensions(): array
{
    return [
        'bat', 'cmd', 'com', 'csh', 'dll', 'exe', 'htm', 'html', 'jar', 'js', 'jsx', 'mjs',
        'phtml', 'phar', 'php', 'ps1', 'scr', 'sh', 'svg', 'ts', 'tsx', 'xhtml', 'xml',
    ];
}

function lms_assignment_supported_extensions(): array
{
    return array_keys(lms_upload_policy());
}

/**
 * @return array{extensions:array<int,string>,errors:array<int,string>}
 */
function lms_assignment_normalize_extension_values($value): array
{
    $rawValues = is_array($value) ? $value : preg_split('/[\s,;]+/', strtolower((string)($value ?? '')));
    $supported = array_flip(lms_assignment_supported_extensions());
    $dangerous = array_flip(lms_assignment_dangerous_extensions());
    $extensions = [];
    $errors = [];

    foreach ($rawValues ?: [] as $rawValue) {
        $ext = ltrim(strtolower(trim((string)$rawValue)), '.');
        if ($ext === '') {
            continue;
        }
        if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            $errors[] = '.' . $ext . ' is not a valid extension';
            continue;
        }
        if (isset($dangerous[$ext])) {
            $errors[] = '.' . $ext . ' is blocked because it can contain active or executable content';
            continue;
        }
        if (!isset($supported[$ext])) {
            $errors[] = '.' . $ext . ' is not supported by Kairos storage';
            continue;
        }
        if (!in_array($ext, $extensions, true)) {
            $extensions[] = $ext;
        }
    }

    return ['extensions' => $extensions, 'errors' => $errors];
}

function lms_normalize_allowed_file_extensions($value): string
{
    $normalized = lms_assignment_normalize_extension_values($value);
    if ($normalized['errors'] !== []) {
        lms_error('validation_error', implode('. ', $normalized['errors']) . '.', 422);
    }
    return implode(',', $normalized['extensions']);
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

function lms_assignment_effective_max_bytes(int $maxFileMb): int
{
    $assignmentBytes = max(1, $maxFileMb) * 1024 * 1024;
    $endpointLimit = (int)env('LMS_UPLOAD_MAX_BYTES', $assignmentBytes);
    if ($endpointLimit <= 0) {
        $endpointLimit = $assignmentBytes;
    }
    return lms_drive_upload_limit(min($assignmentBytes, $endpointLimit));
}

function lms_assignment_upload_policy_payload(): array
{
    return [
        'presets' => lms_assignment_file_type_presets(),
        'supported_extensions' => lms_assignment_supported_extensions(),
        'dangerous_extensions' => lms_assignment_dangerous_extensions(),
        'svg_allowed' => false,
    ];
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
