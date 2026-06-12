<?php
declare(strict_types=1);

use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;

final class GoogleDriveStorage implements DriveStorageInterface
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const CHUNK_BYTES = 1048576;

    private Drive $service;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {
        $this->service = $this->buildService();
    }

    public function upload(array $file, array $context): array
    {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $mimeType = (string)($file['mime_type'] ?? 'application/octet-stream');
        $size = (int)($file['size'] ?? 0);
        $checksum = strtolower((string)($file['checksum_sha256'] ?? ''));
        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath) || $size <= 0) {
            throw new DriveStorageException('invalid_source', 'The validated upload source is unavailable.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new DriveStorageException('invalid_source', 'The validated upload checksum is unavailable.');
        }

        $folderId = $this->ensureStorageFolder($context);
        $storedName = $this->opaqueStoredName((string)($file['extension'] ?? ''));
        $storageKey = bin2hex(random_bytes(16));
        $appProperties = $this->appProperties($context + [
            'storage_key' => $storageKey,
            'sha256' => $checksum,
            'byte_size' => $size,
        ]);

        $metadata = new DriveFile([
            'name' => $storedName,
            'mimeType' => $mimeType,
            'parents' => [$folderId],
            'appProperties' => $appProperties,
        ]);

        $createdId = '';
        try {
            $created = $this->resumableUpload($metadata, $tmpPath, $mimeType, $size);
            $createdId = (string)$created->getId();
            if ($createdId === '') {
                throw new DriveStorageException('verification_failed', 'Drive did not return a file identifier.');
            }

            $remote = $this->getMetadata($createdId);
            $this->assertRemoteMetadata($remote, $storedName, $mimeType, $size, $storageKey);
            $this->assertRemoteBytes($createdId, $checksum, $size);

            return [
                'file_id' => $createdId,
                'folder_id' => $folderId,
                'stored_name' => $storedName,
                'original_name' => (string)($file['name'] ?? 'upload'),
                'mime_type' => $mimeType,
                'size' => $size,
                'checksum_sha256' => $checksum,
                'storage_backend' => 'google_drive',
                'storage_key' => $storageKey,
                'uploaded_at' => gmdate('c'),
            ];
        } catch (Throwable $e) {
            if ($createdId !== '') {
                try {
                    $this->delete($createdId);
                } catch (Throwable) {
                    // The caller logs the primary failure; orphan reconciliation handles cleanup.
                }
            }
            if ($e instanceof DriveStorageException) {
                throw $e;
            }
            throw $this->wrapGoogleFailure('upload_failed', 'Drive upload failed.', $e);
        }
    }

    public function getMetadata(string $fileId): array
    {
        $this->assertFileId($fileId);
        try {
            $file = $this->service->files->get($fileId, [
                'supportsAllDrives' => true,
                'fields' => 'id,name,mimeType,size,parents,driveId,trashed,appProperties',
            ]);
        } catch (GoogleServiceException $e) {
            if ((int)$e->getCode() === 404) {
                throw new DriveStorageException('not_found', 'The stored Drive file was not found.', $e);
            }
            throw $this->wrapGoogleFailure('metadata_failed', 'Drive metadata lookup failed.', $e);
        } catch (Throwable $e) {
            throw $this->wrapGoogleFailure('metadata_failed', 'Drive metadata lookup failed.', $e);
        }

        return [
            'file_id' => (string)$file->getId(),
            'name' => (string)$file->getName(),
            'mime_type' => (string)$file->getMimeType(),
            'size' => $file->getSize() === null ? null : (int)$file->getSize(),
            'parents' => array_values((array)$file->getParents()),
            'drive_id' => (string)$file->getDriveId(),
            'trashed' => (bool)$file->getTrashed(),
            'app_properties' => (array)$file->getAppProperties(),
        ];
    }

    public function downloadToStream(string $fileId, $destination): array
    {
        if (!is_resource($destination)) {
            throw new DriveStorageException('invalid_destination', 'Download destination is invalid.');
        }
        $metadata = $this->getMetadata($fileId);
        if (!empty($metadata['trashed'])) {
            throw new DriveStorageException('not_found', 'The stored Drive file is unavailable.');
        }
        $maxBytes = (int)($this->config['max_upload_bytes'] ?? 25 * 1024 * 1024);
        if ((int)($metadata['size'] ?? 0) <= 0 || (int)$metadata['size'] > $maxBytes) {
            throw new DriveStorageException('verification_failed', 'The stored Drive file size is outside configured limits.');
        }

        try {
            $response = $this->service->files->get($fileId, [
                'alt' => 'media',
                'supportsAllDrives' => true,
            ]);
            $this->writeResponseBody($response, $destination);
        } catch (Throwable $e) {
            if ($e instanceof DriveStorageException) {
                throw $e;
            }
            throw $this->wrapGoogleFailure('download_failed', 'Drive download failed.', $e);
        }

        return $metadata;
    }

    public function updateAppProperties(string $fileId, array $properties): void
    {
        $this->assertFileId($fileId);
        $existing = $this->getMetadata($fileId);
        $merged = array_merge(
            is_array($existing['app_properties'] ?? null) ? $existing['app_properties'] : [],
            $this->appProperties($properties)
        );

        try {
            $this->service->files->update(
                $fileId,
                new DriveFile(['appProperties' => $merged]),
                ['supportsAllDrives' => true, 'fields' => 'id']
            );
        } catch (Throwable $e) {
            throw $this->wrapGoogleFailure('metadata_update_failed', 'Drive metadata update failed.', $e);
        }
    }

    public function delete(string $fileId): void
    {
        $this->assertFileId($fileId);
        try {
            $this->service->files->update(
                $fileId,
                new DriveFile(['trashed' => true]),
                ['supportsAllDrives' => true, 'fields' => 'id,trashed']
            );
        } catch (GoogleServiceException $e) {
            if ((int)$e->getCode() === 404) {
                return;
            }
            throw $this->wrapGoogleFailure('delete_failed', 'Drive deletion failed.', $e);
        } catch (Throwable $e) {
            throw $this->wrapGoogleFailure('delete_failed', 'Drive deletion failed.', $e);
        }
    }

    private function buildService(): Drive
    {
        if (($this->config['auth_mode'] ?? '') !== 'service_account') {
            throw new DriveStorageException('configuration', 'Unsupported Google Drive authentication mode.');
        }

        $credentialsPath = (string)($this->config['credentials_path'] ?? '');
        $sharedDriveId = (string)($this->config['shared_drive_id'] ?? '');
        $rootFolderId = (string)($this->config['root_folder_id'] ?? '');
        if ($credentialsPath === '' || $sharedDriveId === '' || $rootFolderId === '') {
            throw new DriveStorageException('configuration', 'Google Drive configuration is incomplete.');
        }
        if (
            !preg_match('/^[A-Za-z0-9_-]+$/', $sharedDriveId)
            || !preg_match('/^[A-Za-z0-9_-]+$/', $rootFolderId)
        ) {
            throw new DriveStorageException('configuration', 'Google Drive identifiers are invalid.');
        }

        $realCredentialsPath = realpath($credentialsPath);
        if ($realCredentialsPath === false || !is_file($realCredentialsPath) || !is_readable($realCredentialsPath)) {
            throw new DriveStorageException('configuration', 'Google Drive credentials are not readable.');
        }
        $projectRoot = realpath(app_base_path());
        if ($projectRoot !== false && $this->pathWithin($realCredentialsPath, $projectRoot)) {
            throw new DriveStorageException('configuration', 'Google Drive credentials must be stored outside the project web root.');
        }
        $permissions = fileperms($realCredentialsPath);
        if ($permissions !== false && ($permissions & 0077) !== 0) {
            throw new DriveStorageException('configuration', 'Google Drive credentials must not be group- or world-readable.');
        }

        $decoded = json_decode((string)file_get_contents($realCredentialsPath), true);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'service_account') {
            throw new DriveStorageException('configuration', 'Google Drive credentials must be a service account JSON key.');
        }

        try {
            $client = new GoogleClient();
            $client->setApplicationName('Kairos LMS');
            $client->setAuthConfig($realCredentialsPath);
            $client->setScopes([Drive::DRIVE]);
            return new Drive($client);
        } catch (Throwable $e) {
            throw $this->wrapGoogleFailure('authentication', 'Google Drive authentication initialization failed.', $e);
        }
    }

    private function ensureStorageFolder(array $context): string
    {
        $kind = (string)($context['kind'] ?? '');
        $courseId = (int)($context['course_id'] ?? 0);
        if ($courseId <= 0 || !in_array($kind, ['course_resource', 'assignment_submission'], true)) {
            throw new DriveStorageException('invalid_context', 'Drive storage context is invalid.');
        }

        $segments = $kind === 'course_resource'
            ? [
                ['name' => 'resources', 'key' => 'resources'],
                ['name' => 'course-' . $courseId, 'key' => 'resources-course-' . $courseId],
            ]
            : [
                ['name' => 'submissions', 'key' => 'submissions'],
                ['name' => 'course-' . $courseId, 'key' => 'submissions-course-' . $courseId],
                ['name' => 'assignment-' . (int)($context['assignment_id'] ?? 0), 'key' => 'assignment-' . (int)($context['assignment_id'] ?? 0)],
                ['name' => 'user-' . (int)($context['uploader_user_id'] ?? 0), 'key' => 'user-' . (int)($context['uploader_user_id'] ?? 0)],
            ];

        $parentId = (string)$this->config['root_folder_id'];
        foreach ($segments as $segment) {
            if (str_ends_with($segment['name'], '-0')) {
                throw new DriveStorageException('invalid_context', 'Drive storage context is incomplete.');
            }
            $parentId = $this->findOrCreateFolder($parentId, $segment['name'], $segment['key']);
        }
        return $parentId;
    }

    private function findOrCreateFolder(string $parentId, string $name, string $folderKey): string
    {
        $query = sprintf(
            "mimeType = '%s' and trashed = false and '%s' in parents and appProperties has { key='kairos_folder_key' and value='%s' }",
            self::FOLDER_MIME,
            $this->escapeQueryValue($parentId),
            $this->escapeQueryValue($folderKey)
        );

        try {
            $result = $this->service->files->listFiles([
                'q' => $query,
                'corpora' => 'drive',
                'driveId' => (string)$this->config['shared_drive_id'],
                'includeItemsFromAllDrives' => true,
                'supportsAllDrives' => true,
                'pageSize' => 2,
                'fields' => 'files(id,name,parents,appProperties,trashed)',
            ]);
            $files = $result->getFiles();
            if (count($files) > 1) {
                throw new DriveStorageException('folder_conflict', 'Multiple managed Drive folders match the same storage key.');
            }
            if (count($files) === 1) {
                return (string)$files[0]->getId();
            }

            $folder = $this->service->files->create(new DriveFile([
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
                'appProperties' => [
                    'kairos_managed' => '1',
                    'kairos_folder_key' => $folderKey,
                ],
            ]), [
                'supportsAllDrives' => true,
                'fields' => 'id',
            ]);
            $folderId = (string)$folder->getId();
            if ($folderId === '') {
                throw new DriveStorageException('folder_create_failed', 'Drive folder creation did not return an identifier.');
            }
            return $folderId;
        } catch (Throwable $e) {
            if ($e instanceof DriveStorageException) {
                throw $e;
            }
            throw $this->wrapGoogleFailure('folder_failed', 'Drive folder preparation failed.', $e);
        }
    }

    private function resumableUpload(DriveFile $metadata, string $path, string $mimeType, int $size): DriveFile
    {
        $client = $this->service->getClient();
        $client->setDefer(true);
        $handle = null;
        try {
            $request = $this->service->files->create($metadata, [
                'supportsAllDrives' => true,
                'fields' => 'id,name,mimeType,size,parents,driveId,trashed,appProperties',
            ]);
            $media = new MediaFileUpload(
                $client,
                $request,
                $mimeType,
                null,
                true,
                self::CHUNK_BYTES
            );
            $media->setFileSize($size);
            $handle = fopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new DriveStorageException('invalid_source', 'The upload source could not be opened.');
            }

            $status = false;
            while ($status === false && !feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new DriveStorageException('read_failed', 'The upload source could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                $status = $media->nextChunk($chunk);
            }
            if (!$status instanceof DriveFile) {
                throw new DriveStorageException('upload_incomplete', 'Drive did not confirm the completed upload.');
            }
            return $status;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $client->setDefer(false);
        }
    }

    private function assertRemoteMetadata(
        array $remote,
        string $storedName,
        string $mimeType,
        int $size,
        string $storageKey
    ): void {
        $properties = is_array($remote['app_properties'] ?? null) ? $remote['app_properties'] : [];
        if (
            ($remote['file_id'] ?? '') === ''
            || ($remote['name'] ?? '') !== $storedName
            || ($remote['mime_type'] ?? '') !== $mimeType
            || (int)($remote['size'] ?? -1) !== $size
            || ($properties['kairos_storage_key'] ?? '') !== $storageKey
            || !empty($remote['trashed'])
        ) {
            throw new DriveStorageException('verification_failed', 'Stored Drive metadata did not match the upload.');
        }
        $driveId = (string)($remote['drive_id'] ?? '');
        if ($driveId !== '' && !hash_equals((string)$this->config['shared_drive_id'], $driveId)) {
            throw new DriveStorageException('verification_failed', 'Stored Drive file is outside the configured shared drive.');
        }
    }

    private function assertRemoteBytes(string $fileId, string $checksum, int $size): void
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!is_resource($stream)) {
            throw new DriveStorageException('verification_failed', 'A verification stream could not be created.');
        }
        try {
            $this->downloadToStream($fileId, $stream);
            $actualSize = ftell($stream);
            rewind($stream);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualChecksum = hash_final($hash);
            if ($actualSize !== $size || !hash_equals($checksum, $actualChecksum)) {
                throw new DriveStorageException('verification_failed', 'Stored Drive bytes did not match the upload.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function writeResponseBody(mixed $response, $destination): void
    {
        if (is_string($response)) {
            $this->writeAll($destination, $response);
            return;
        }

        $body = is_object($response) && method_exists($response, 'getBody')
            ? $response->getBody()
            : $response;
        if (!is_object($body)) {
            throw new DriveStorageException('download_failed', 'Drive returned an unsupported download response.');
        }

        if (method_exists($body, 'rewind')) {
            $body->rewind();
        }
        while (method_exists($body, 'eof') && !$body->eof()) {
            $chunk = $body->read(self::CHUNK_BYTES);
            if (!is_string($chunk)) {
                throw new DriveStorageException('download_failed', 'Drive returned invalid download bytes.');
            }
            if ($chunk !== '') {
                $this->writeAll($destination, $chunk);
            }
        }
    }

    private function writeAll($destination, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($destination, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new DriveStorageException('download_failed', 'The download destination could not be written.');
            }
            $offset += $written;
        }
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function appProperties(array $values): array
    {
        $map = [
            'storage_key' => 'kairos_storage_key',
            'sha256' => 'kairos_sha256',
            'byte_size' => 'kairos_byte_size',
            'kind' => 'kairos_kind',
            'course_id' => 'kairos_course_id',
            'assignment_id' => 'kairos_assignment_id',
            'uploader_user_id' => 'kairos_uploader_id',
            'resource_id' => 'kairos_resource_id',
            'submission_id' => 'kairos_submission_id',
        ];
        $properties = ['kairos_managed' => '1'];
        foreach ($map as $input => $key) {
            if (!array_key_exists($input, $values) || $values[$input] === null || $values[$input] === '') {
                continue;
            }
            $value = (string)$values[$input];
            $properties[$key] = substr($value, 0, 120);
        }
        return $properties;
    }

    private function opaqueStoredName(string $extension): string
    {
        $suffix = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.' . $extension : '';
        return bin2hex(random_bytes(20)) . $suffix;
    }

    private function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function assertFileId(string $fileId): void
    {
        if ($fileId === '' || strlen($fileId) > 255 || !preg_match('/^[A-Za-z0-9_-]+$/', $fileId)) {
            throw new DriveStorageException('invalid_identifier', 'Stored Drive identifier is invalid.');
        }
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($path, $normalizedParent, strlen($normalizedParent)) === 0;
    }

    private function wrapGoogleFailure(string $reason, string $message, Throwable $previous): DriveStorageException
    {
        return new DriveStorageException($reason, $message, $previous);
    }
}
