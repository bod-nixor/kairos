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
        'doc'  => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'mp4'  => ['video/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'application/octet-stream'],
    ];
}

function lms_ooxml_required_prefix(string $extension): ?string
{
    return [
        'docx' => 'word/',
        'pptx' => 'ppt/',
        'xlsx' => 'xl/',
    ][$extension] ?? null;
}

function lms_validate_ooxml_container(string $tmpPath, string $extension): bool
{
    $requiredPrefix = lms_ooxml_required_prefix($extension);
    if ($requiredPrefix === null) {
        return false;
    }

    $handle = @fopen($tmpPath, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    $signature = fread($handle, 4);
    fclose($handle);
    if ($signature !== "PK\x03\x04") {
        return false;
    }

    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        return false;
    }

    $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
    $hasRequiredDirectory = false;
    $prefixLength = strlen($requiredPrefix);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (is_string($name) && strncmp($name, $requiredPrefix, $prefixLength) === 0) {
            $hasRequiredDirectory = true;
            break;
        }
    }
    $zip->close();

    return $hasContentTypes && $hasRequiredDirectory;
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
    $genericOfficeMime = in_array($detectedMime, ['application/zip', 'application/octet-stream'], true);
    if (lms_ooxml_required_prefix($ext) !== null && $genericOfficeMime) {
        if (!lms_validate_ooxml_container($tmpPath, $ext)) {
            lms_error('validation_error', 'unsupported file content type', 422);
        }
        $detectedMime = $allowedMimes[0];
    } elseif (!in_array($detectedMime, $allowedMimes, true)) {
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
