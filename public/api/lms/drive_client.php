<?php
declare(strict_types=1);

require_once __DIR__ . '/integrations/drive/DriveStorageInterface.php';
require_once __DIR__ . '/integrations/drive/DriveStorageException.php';

function lms_drive_enabled(): bool
{
    return (bool)env('GOOGLE_DRIVE_ENABLED', false);
}

function lms_drive_writes_enabled(): bool
{
    return lms_drive_enabled() && (bool)env('GOOGLE_DRIVE_WRITES_ENABLED', false);
}

/**
 * @return array<string,mixed>
 */
function lms_drive_config(): array
{
    return [
        'auth_mode' => strtolower(trim((string)env('GOOGLE_DRIVE_AUTH_MODE', 'service_account'))),
        'credentials_path' => trim((string)env('GOOGLE_DRIVE_CREDENTIALS_PATH', '')),
        'shared_drive_id' => trim((string)env('GOOGLE_DRIVE_SHARED_DRIVE_ID', '')),
        'root_folder_id' => trim((string)env('GOOGLE_DRIVE_ROOT_FOLDER_ID', '')),
        'max_upload_bytes' => max(1, (int)env('GOOGLE_DRIVE_MAX_UPLOAD_BYTES', 25 * 1024 * 1024)),
    ];
}

function lms_set_drive_storage_for_tests(?DriveStorageInterface $storage): void
{
    $GLOBALS['kairos_drive_storage_override'] = $storage;
}

function lms_drive_storage(): DriveStorageInterface
{
    $override = $GLOBALS['kairos_drive_storage_override'] ?? null;
    if ($override instanceof DriveStorageInterface) {
        return $override;
    }
    if (!lms_drive_enabled()) {
        throw new DriveStorageException('disabled', 'Google Drive storage is disabled.');
    }

    $autoload = app_base_path('vendor', 'autoload.php');
    if (!is_file($autoload)) {
        throw new DriveStorageException('dependency_missing', 'Google API dependencies are not installed.');
    }
    require_once $autoload;
    require_once __DIR__ . '/integrations/drive/GoogleDriveStorage.php';

    static $storage = null;
    if (!$storage instanceof DriveStorageInterface) {
        $storage = new GoogleDriveStorage(lms_drive_config());
    }
    return $storage;
}

function lms_drive_upload_limit(int $endpointLimit): int
{
    $driveLimit = (int)(lms_drive_config()['max_upload_bytes'] ?? 25 * 1024 * 1024);
    if ($endpointLimit <= 0) {
        return $driveLimit;
    }
    return min($endpointLimit, $driveLimit);
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

function lms_is_legacy_office_extension(string $extension): bool
{
    return in_array($extension, ['doc', 'ppt', 'xls'], true);
}

function lms_validate_ole_container(string $tmpPath): bool
{
    $handle = @fopen($tmpPath, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    $signature = fread($handle, 4);
    fclose($handle);

    return $signature === "\xD0\xCF\x11\xE0";
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
    if (!lms_upload_size_allowed($size, $maxBytes)) {
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
    if (lms_is_legacy_office_extension($ext) && in_array($detectedMime, $allowedMimes, true)) {
        if (!lms_validate_ole_container($tmpPath)) {
            lms_error('validation_error', 'unsupported file content type', 422);
        }
    } elseif (lms_ooxml_required_prefix($ext) !== null && $genericOfficeMime) {
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
        'checksum_sha256' => hash_file('sha256', $tmpPath),
    ];
}

function lms_upload_size_allowed(int $size, int $maxBytes): bool
{
    return $size > 0 && ($maxBytes <= 0 || $size <= $maxBytes);
}

function lms_drive_internal_url(int $resourceId, string $disposition = 'attachment'): string
{
    $mode = $disposition === 'inline' ? 'inline' : 'attachment';
    return './api/lms/resources/download.php?resource_id=' . $resourceId . '&disposition=' . $mode;
}

function lms_drive_inline_allowed(string $mimeType): bool
{
    $mime = strtolower(trim(explode(';', $mimeType, 2)[0]));
    if (in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true)) {
        return true;
    }
    return str_starts_with($mime, 'image/')
        && !in_array($mime, ['image/svg+xml'], true);
}

/**
 * @param array<string,mixed> $remote
 */
function lms_drive_download_integrity_ok(
    string $fileId,
    array $remote,
    int $actualSize,
    string $actualChecksum,
    int $expectedSize,
    string $expectedChecksum,
    string $expectedStorageKey
): bool {
    $remoteProperties = is_array($remote['app_properties'] ?? null) ? $remote['app_properties'] : [];
    return ($remote['file_id'] ?? '') === $fileId
        && empty($remote['trashed'])
        && ($expectedSize <= 0 || $actualSize === $expectedSize)
        && ($expectedChecksum === '' || hash_equals(strtolower($expectedChecksum), strtolower($actualChecksum)))
        && ($expectedStorageKey === '' || hash_equals(
            $expectedStorageKey,
            (string)($remoteProperties['kairos_storage_key'] ?? '')
        ));
}

function lms_drive_log(string $action, string $status, array $context = []): void
{
    $allowed = array_intersect_key($context, array_flip([
        'reason',
        'course_id',
        'resource_id',
        'assignment_id',
        'submission_id',
        'user_id',
    ]));
    $entry = array_merge([
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
        'action' => $action,
        'status' => $status,
    ], $allowed);
    error_log('[kairos] ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
}

function lms_drive_fail(DriveStorageException $e, string $operation, array $context = []): never
{
    lms_drive_log($operation, 'failed', $context + ['reason' => $e->reason()]);
    lms_error(
        'storage_unavailable',
        'File uploads are temporarily unavailable. Use a text submission or contact an administrator.',
        503
    );
}

function lms_drive_try_cleanup(
    DriveStorageInterface $storage,
    string $fileId,
    array $context = []
): bool {
    if ($fileId === '') {
        return true;
    }
    $lastReason = 'delete_failed';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $storage->delete($fileId);
            lms_drive_log('drive_cleanup', 'completed', $context);
            return true;
        } catch (DriveStorageException $e) {
            $lastReason = $e->reason();
            if ($attempt < 3) {
                usleep(100000 * $attempt);
            }
        }
    }
    lms_drive_log('drive_cleanup', 'pending', $context + ['reason' => $lastReason]);
    return false;
}

/**
 * @param array<string,mixed> $storageMeta
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function lms_drive_resource_metadata(array $storageMeta, array $context): array
{
    return [
        'storage_backend' => 'google_drive',
        'storage_key' => (string)($storageMeta['storage_key'] ?? ''),
        'drive_folder_id' => (string)($storageMeta['folder_id'] ?? ''),
        'stored_name' => (string)($storageMeta['stored_name'] ?? ''),
        'original_name' => (string)($storageMeta['original_name'] ?? ''),
        'uploaded_at' => (string)($storageMeta['uploaded_at'] ?? gmdate('c')),
        'uploader_user_id' => (int)($context['uploader_user_id'] ?? 0),
        'course_id' => (int)($context['course_id'] ?? 0),
        'assignment_id' => isset($context['assignment_id']) ? (int)$context['assignment_id'] : null,
        'submission_id' => isset($context['submission_id']) ? (int)$context['submission_id'] : null,
        'storage_cleanup_pending' => false,
    ];
}
