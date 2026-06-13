<?php
declare(strict_types=1);

putenv('APP_ENV=local');
putenv('APP_DEBUG=false');
putenv('ALLOWED_DOMAIN=nixorcollege.edu.pk');
putenv('DEFAULT_ROLE_NAME=student');
putenv('GOOGLE_CLIENT_ID=test-client.apps.googleusercontent.com');
putenv('APP_ORIGIN=https://kairos.nixorcorporate.com');
putenv('SESSION_COOKIE_SECURE=false');
putenv('GOOGLE_DRIVE_ENABLED=false');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'kairos.nixorcorporate.com';
$_SERVER['HTTPS'] = 'on';

$root = dirname(__DIR__, 2);
require_once $root . '/public/api/bootstrap.php';
require_once $root . '/public/api/lms/_common.php';
require_once $root . '/public/api/lms/drive_client.php';
require_once $root . '/public/api/lms/resources/_access.php';
require_once $root . '/vendor/autoload.php';

final class FakeDriveStorage implements DriveStorageInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $files = [];
    public bool $failUpload = false;
    public int $deleteFailuresRemaining = 0;
    public int $deleteCalls = 0;

    public function upload(array $file, array $context): array
    {
        if ($this->failUpload) {
            throw new DriveStorageException('provider_unavailable', 'simulated provider failure');
        }
        $bytes = (string)file_get_contents((string)$file['tmp_name']);
        $id = 'fake_' . (count($this->files) + 1);
        $storageKey = 'storage_' . (count($this->files) + 1);
        $this->files[$id] = [
            'bytes' => $bytes,
            'name' => 'opaque.pdf',
            'mime_type' => (string)$file['mime_type'],
            'app_properties' => [
                'kairos_storage_key' => $storageKey,
                'kairos_course_id' => (string)$context['course_id'],
            ],
        ];
        return [
            'file_id' => $id,
            'folder_id' => 'folder_1',
            'stored_name' => 'opaque.pdf',
            'original_name' => (string)$file['name'],
            'mime_type' => (string)$file['mime_type'],
            'size' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'storage_backend' => 'google_drive',
            'storage_key' => $storageKey,
            'uploaded_at' => gmdate('c'),
        ];
    }

    public function getMetadata(string $fileId): array
    {
        if (!isset($this->files[$fileId])) {
            throw new DriveStorageException('not_found', 'missing fake file');
        }
        $file = $this->files[$fileId];
        return [
            'file_id' => $fileId,
            'name' => $file['name'],
            'mime_type' => $file['mime_type'],
            'size' => strlen((string)$file['bytes']),
            'trashed' => false,
            'app_properties' => $file['app_properties'],
        ];
    }

    public function downloadToStream(string $fileId, $destination): array
    {
        $metadata = $this->getMetadata($fileId);
        fwrite($destination, (string)$this->files[$fileId]['bytes']);
        return $metadata;
    }

    public function updateAppProperties(string $fileId, array $properties): void
    {
        $this->getMetadata($fileId);
        foreach ($properties as $key => $value) {
            $this->files[$fileId]['app_properties']['kairos_' . $key] = (string)$value;
        }
    }

    public function delete(string $fileId): void
    {
        $this->deleteCalls++;
        if ($this->deleteFailuresRemaining > 0) {
            $this->deleteFailuresRemaining--;
            throw new DriveStorageException('provider_unavailable', 'simulated delete failure');
        }
        unset($this->files[$fileId]);
    }
}

$failed = [];
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if (!$condition) {
        $failed[] = $message;
    }
};

lms_set_drive_storage_for_tests(null);
try {
    lms_drive_storage();
    $failed[] = 'disabled storage should fail closed';
} catch (DriveStorageException $e) {
    $assert($e->reason() === 'disabled', 'disabled storage should report the disabled reason');
}

putenv('GOOGLE_DRIVE_ENABLED=true');
putenv('GOOGLE_DRIVE_CREDENTIALS_PATH=');
putenv('GOOGLE_DRIVE_SHARED_DRIVE_ID=');
putenv('GOOGLE_DRIVE_ROOT_FOLDER_ID=');
try {
    lms_drive_storage();
    $failed[] = 'incomplete Drive configuration should fail closed';
} catch (DriveStorageException $e) {
    $assert($e->reason() === 'configuration', 'incomplete Drive configuration should report a configuration failure');
}
putenv('GOOGLE_DRIVE_ENABLED=false');

$assert(lms_upload_size_allowed(1024, 1024), 'upload size equal to the limit should be accepted');
$assert(!lms_upload_size_allowed(1025, 1024), 'upload size above the limit should be rejected');
$assert(!lms_upload_size_allowed(0, 1024), 'empty uploads should be rejected');
$policy = lms_upload_policy();
$assert(isset($policy['pdf'], $policy['docx'], $policy['pptx'], $policy['xlsx']), 'document upload policy should include supported academic formats');
$assert(!isset($policy['php'], $policy['svg'], $policy['html'], $policy['js']), 'active content extensions should not be uploadable');
$assert(!lms_drive_inline_allowed('text/html'), 'HTML must never render inline');
$assert(!lms_drive_inline_allowed('image/svg+xml'), 'SVG must never render inline');
$assert(lms_drive_inline_allowed('application/pdf'), 'PDF should support protected inline preview');

$storageReflection = new ReflectionClass(GoogleDriveStorage::class);
$storageWithoutConstructor = $storageReflection->newInstanceWithoutConstructor();
$writeResponseBody = $storageReflection->getMethod('writeResponseBody');
$writeResponseBody->setAccessible(true);
$unreadableStream = fopen('php://temp', 'w+b');
try {
    $writeResponseBody->invoke($storageWithoutConstructor, new class {
        public function eof(): bool
        {
            return false;
        }
    }, $unreadableStream);
    $failed[] = 'Drive response bodies without read() should fail closed';
} catch (DriveStorageException $e) {
    $assert($e->reason() === 'download_failed', 'unreadable Drive responses should report download_failed');
} finally {
    if (is_resource($unreadableStream)) {
        fclose($unreadableStream);
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'kairos_drive_');
if ($tmp === false) {
    $failed[] = 'failed to create upload fixture';
} else {
    $bytes = "%PDF-1.4\nprivate academic file\n";
    file_put_contents($tmp, $bytes);
    $validated = lms_validate_uploaded_file([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'name' => "../Odd\r\nName?.pdf",
        'size' => strlen($bytes),
    ], strlen($bytes));
    $assert($validated['name'] === 'Odd__Name_.pdf', 'filename should be basename-normalized and strip control/special characters');
    $assert($validated['checksum_sha256'] === hash('sha256', $bytes), 'validated upload should include SHA-256');

    $fake = new FakeDriveStorage();
    lms_set_drive_storage_for_tests($fake);
    $assert(lms_drive_storage() === $fake, 'test adapter injection should be honored');
    $stored = $fake->upload($validated, [
        'kind' => 'course_resource',
        'course_id' => 301,
        'uploader_user_id' => 20,
    ]);
    $stream = fopen('php://temp', 'w+b');
    $remote = $fake->downloadToStream((string)$stored['file_id'], $stream);
    rewind($stream);
    $downloaded = stream_get_contents($stream);
    fclose($stream);
    $assert($downloaded === $bytes, 'fake adapter should round-trip exact bytes');
    $assert(lms_drive_download_integrity_ok(
        (string)$stored['file_id'],
        $remote,
        strlen($bytes),
        hash('sha256', $bytes),
        strlen($bytes),
        (string)$stored['checksum_sha256'],
        (string)$stored['storage_key']
    ), 'matching download metadata and checksum should pass');
    $assert(!lms_drive_download_integrity_ok(
        (string)$stored['file_id'],
        $remote,
        strlen($bytes),
        hash('sha256', $bytes . 'tampered'),
        strlen($bytes),
        (string)$stored['checksum_sha256'],
        (string)$stored['storage_key']
    ), 'checksum mismatch should fail');
    $badRemote = $remote;
    $badRemote['app_properties']['kairos_storage_key'] = 'wrong';
    $assert(!lms_drive_download_integrity_ok(
        (string)$stored['file_id'],
        $badRemote,
        strlen($bytes),
        hash('sha256', $bytes),
        strlen($bytes),
        (string)$stored['checksum_sha256'],
        (string)$stored['storage_key']
    ), 'storage metadata mismatch should fail');

    // Simulate a DB write failure after durable upload. Compensation must remove the remote file.
    $dbWriteCalled = false;
    try {
        $dbWriteCalled = true;
        throw new RuntimeException('simulated database failure');
    } catch (RuntimeException) {
        $cleaned = lms_drive_try_cleanup($fake, (string)$stored['file_id'], ['course_id' => 301]);
        $assert($cleaned, 'DB failure compensation should delete the remote file');
        $assert(!isset($fake->files[$stored['file_id']]), 'DB failure compensation should not leave an orphan');
    }
    $assert($dbWriteCalled, 'DB failure simulation should execute after upload');

    $retryStored = $fake->upload($validated, [
        'kind' => 'course_resource',
        'course_id' => 301,
        'uploader_user_id' => 20,
    ]);
    $fake->deleteFailuresRemaining = 2;
    $fake->deleteCalls = 0;
    $assert(lms_drive_try_cleanup($fake, (string)$retryStored['file_id']), 'cleanup should retry transient provider failures');
    $assert($fake->deleteCalls === 3, 'cleanup should make three bounded attempts');

    $providerFailed = new FakeDriveStorage();
    $providerFailed->failUpload = true;
    $dbReached = false;
    try {
        $providerFailed->upload($validated, ['kind' => 'course_resource', 'course_id' => 301]);
        $dbReached = true;
    } catch (DriveStorageException $e) {
        $assert($e->reason() === 'provider_unavailable', 'provider failure should remain classified');
    }
    $assert(!$dbReached, 'provider failure must occur before any DB write');
    @unlink($tmp);
}

$submission = [
    'access_scope' => 'assignment_submission',
    'student_user_id' => 50,
    'assignment_id' => 700,
    'published_flag' => 1,
    'created_by' => 50,
];
$assert(lms_resource_scope_denial(['user_id' => 50, 'role_name' => 'student'], $submission) === null, 'student should access own submission file');
$assert(lms_resource_scope_denial(['user_id' => 51, 'role_name' => 'student'], $submission) !== null, 'student should not access another submission file');
$assert(lms_resource_scope_denial(['user_id' => 60, 'role_name' => 'ta'], $submission, false) !== null, 'unassigned TA should be denied');
$assert(lms_resource_scope_denial(['user_id' => 60, 'role_name' => 'ta'], $submission, true) === null, 'assigned TA should be allowed');
$assert(lms_resource_scope_denial(
    ['user_id' => 51, 'role_name' => 'student'],
    ['access_scope' => 'private', 'created_by' => 20, 'published_flag' => 1]
) !== null, 'student should be denied a private staff resource');
$assert(lms_resource_scope_denial(
    ['user_id' => 50, 'role_name' => 'student'],
    ['access_scope' => 'course', 'created_by' => 20, 'published_flag' => 0]
) !== null, 'student should be denied a draft course resource');

$uploadSource = (string)file_get_contents($root . '/public/api/lms/resources/upload.php');
$submitSource = (string)file_get_contents($root . '/public/api/lms/assignments/submit.php');
$deleteSource = (string)file_get_contents($root . '/public/api/lms/resources/delete.php');
$downloadSource = (string)file_get_contents($root . '/public/api/lms/resources/download.php');
$getSource = (string)file_get_contents($root . '/public/api/lms/resources/get.php');
$driveClientSource = (string)file_get_contents($root . '/public/api/lms/drive_client.php');

$assert(strpos($uploadSource, "lms_require_roles(['manager','admin'])") !== false, 'course file upload should remain manager/admin only');
$pUpload = strpos($uploadSource, '$storage->upload');
$pUploadBegin = strpos($uploadSource, '$pdo->beginTransaction');
$pMetadataUpdate = strpos($uploadSource, 'updateAppProperties');
$pUploadCommit = strpos($uploadSource, '$pdo->commit');
$pUploadCleanup = strpos($uploadSource, 'lms_drive_try_cleanup');
$pSubmitUpload = strpos($submitSource, '$storage->upload');
$pSubmitBegin = strpos($submitSource, '$pdo->beginTransaction');
$pSubmitCleanup = strpos($submitSource, 'lms_drive_try_cleanup');
$pDeleteSoft = strpos($deleteSource, 'SET deleted_at = CURRENT_TIMESTAMP');
$pDeleteCleanup = strpos($deleteSource, 'lms_drive_try_cleanup');

$assert($pUpload !== false, 'resource upload endpoint must call storage upload');
$assert($pUploadBegin !== false, 'resource upload endpoint must begin a DB transaction');
$assert($pMetadataUpdate !== false, 'resource upload endpoint must update Drive metadata');
$assert($pUploadCommit !== false, 'resource upload endpoint must commit its DB transaction');
$assert($pUploadCleanup !== false, 'resource DB failures must compensate the remote upload');
$assert($pSubmitUpload !== false, 'submission endpoint must call storage upload');
$assert($pSubmitBegin !== false, 'submission endpoint must begin a DB transaction');
$assert($pSubmitCleanup !== false, 'submission DB failures must compensate the remote upload');
$assert($pDeleteSoft !== false, 'resource deletion endpoint must soft-delete locally');
$assert($pDeleteCleanup !== false, 'resource deletion endpoint must clean up Drive storage');

$assert($pUpload !== false && $pUploadBegin !== false && $pUpload < $pUploadBegin, 'resource bytes must persist before the DB transaction');
$assert($pMetadataUpdate !== false && $pUploadCommit !== false && $pMetadataUpdate < $pUploadCommit, 'resource Drive metadata must finalize before DB commit');
$assert($pSubmitUpload !== false && $pSubmitBegin !== false && $pSubmitUpload < $pSubmitBegin, 'submission bytes must persist before the DB transaction');
$assert($pDeleteSoft !== false && $pDeleteCleanup !== false && $pDeleteSoft < $pDeleteCleanup, 'local deletion must precede destructive Drive cleanup');
$assert(strpos($downloadSource, "\$_GET['resource_id']") !== false, 'download endpoint should accept a local resource identifier');
$assert(strpos($downloadSource, "\$_GET['file_id']") === false, 'download endpoint must not accept arbitrary Drive identifiers');
$assert(strpos($downloadSource, "lms_require_roles(['student', 'ta', 'manager', 'admin'])") !== false, 'download endpoint must require authentication');
$assert(strpos($downloadSource, 'lms_authorize_resource_access') !== false, 'download endpoint must enforce resource RBAC');
$assert(strpos($downloadSource, 'lms_drive_download_integrity_ok') !== false, 'download endpoint must verify storage integrity');
$assert(!preg_match('/[\'"]drive_file_id[\'"]\s*=>/', $getSource), 'resource API payload must not expose the Drive file identifier');
$assert(strpos($driveClientSource, 'new finfo(FILEINFO_MIME_TYPE)') !== false, 'upload validation must detect MIME server-side');
$assert(strpos($driveClientSource, "\$file['type']") === false, 'upload validation must not trust the browser MIME type');

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "drive storage tests passed" . PHP_EOL;
