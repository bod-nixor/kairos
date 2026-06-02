<?php
declare(strict_types=1);

function lms_drive_enabled(): bool
{
    return (bool)env('GOOGLE_DRIVE_ENABLED', false);
}

function lms_upload_policy(): array
{
    return [
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'mp4'  => ['video/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'application/octet-stream'],
    ];
}

function lms_validate_uploaded_file(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        lms_error('upload_failed', 'File upload failed', 422);
    }
    $tmpPath = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmpPath) && PHP_SAPI !== 'cli') {
        lms_error('upload_failed', 'Invalid upload source', 422);
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || ($maxBytes > 0 && $size > $maxBytes)) {
        lms_error('validation_error', 'file exceeds maximum size', 422);
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $safeName = basename(str_replace('\\', '/', $originalName));
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        $safeName = 'upload';
    }
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $safeName) ?: 'upload';
    $safeName = trim($safeName, ". \t\n\r\0\x0B");
    if ($safeName === '') {
        $safeName = 'upload';
    }
    $safeName = function_exists('mb_substr') ? mb_substr($safeName, 0, 180) : substr($safeName, 0, 180);

    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $policy = lms_upload_policy();
    if ($ext === '' || !array_key_exists($ext, $policy)) {
        lms_error('validation_error', 'file type is not allowed', 422);
    }

    $detectedMime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpPath);
    }
    $allowedMimes = $policy[$ext];
    if (!in_array($detectedMime, $allowedMimes, true)) {
        lms_error('validation_error', 'unsupported file content type', 422);
    }

    return [
        'name' => $safeName,
        'tmp_name' => $tmpPath,
        'mime_type' => $detectedMime,
        'size' => $size,
        'extension' => $ext,
    ];
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
