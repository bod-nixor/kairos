<?php
declare(strict_types=1);

function lms_drive_enabled(): bool
{
    return (bool)env('GOOGLE_DRIVE_ENABLED', false);
}

function lms_drive_upload_stub(string $originalName, string $tmpPath, string $mimeType): array
{
    $size = filesize($tmpPath) ?: 0;
    $checksum = hash_file('sha256', $tmpPath) ?: null;
    $basePreview = rtrim((string)env('LMS_DRIVE_PREVIEW_BASE', 'https://drive.google.com/file/d/'), '/');
    $fileId = 'stub_' . bin2hex(random_bytes(8));

    return [
        'file_id' => $fileId,
        'preview_url' => $basePreview . '/' . $fileId . '/preview',
        'mime_type' => $mimeType,
        'size' => (int)$size,
        'checksum' => $checksum,
        'storage_mode' => lms_drive_enabled() ? 'drive_stub' : 'local_stub',
        'original_name' => $originalName,
    ];
}

function lms_drive_delete_stub(string $fileId): bool
{
    return $fileId !== '';
}
