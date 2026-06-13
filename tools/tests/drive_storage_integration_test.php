<?php
declare(strict_types=1);

if ((string)getenv('KAIROS_DRIVE_TESTS') !== '1') {
    echo "drive storage integration test skipped (set KAIROS_DRIVE_TESTS=1)" . PHP_EOL;
    exit(0);
}

putenv('APP_ENV=local');
putenv('APP_DEBUG=false');
putenv('ALLOWED_DOMAIN=nixorcollege.edu.pk');
putenv('DEFAULT_ROLE_NAME=student');

$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/public/api/lms/integrations/drive/DriveStorageInterface.php';
require_once $root . '/public/api/lms/integrations/drive/DriveStorageException.php';
require_once $root . '/public/api/lms/integrations/drive/GoogleDriveStorage.php';

$credentialsPath = trim((string)env('GOOGLE_DRIVE_CREDENTIALS_PATH', ''));
$sharedDriveId = trim((string)env('GOOGLE_DRIVE_TEST_SHARED_DRIVE_ID', ''));
$testRootFolderId = trim((string)env('GOOGLE_DRIVE_TEST_ROOT_FOLDER_ID', ''));
$productionRootFolderId = trim((string)env('GOOGLE_DRIVE_ROOT_FOLDER_ID', ''));
if ($credentialsPath === '' || $sharedDriveId === '' || $testRootFolderId === '') {
    fwrite(STDERR, "integration test requires GOOGLE_DRIVE_CREDENTIALS_PATH, GOOGLE_DRIVE_TEST_SHARED_DRIVE_ID, and GOOGLE_DRIVE_TEST_ROOT_FOLDER_ID" . PHP_EOL);
    exit(2);
}
if ($productionRootFolderId !== '' && hash_equals($productionRootFolderId, $testRootFolderId)) {
    fwrite(STDERR, "GOOGLE_DRIVE_TEST_ROOT_FOLDER_ID must not be the production root folder" . PHP_EOL);
    exit(2);
}

$config = [
    'auth_mode' => 'service_account',
    'credentials_path' => $credentialsPath,
    'shared_drive_id' => $sharedDriveId,
    'root_folder_id' => $testRootFolderId,
    'max_upload_bytes' => 1024 * 1024,
];

$tmp = tempnam(sys_get_temp_dir(), 'kairos_drive_integration_');
if ($tmp === false) {
    fwrite(STDERR, "failed to create integration test fixture" . PHP_EOL);
    exit(1);
}

$nonce = bin2hex(random_bytes(16));
$bytes = "Kairos Drive integration test\n{$nonce}\n";
file_put_contents($tmp, $bytes);
$stored = null;

try {
    $storage = new GoogleDriveStorage($config);
    $stored = $storage->upload([
        'name' => 'kairos-drive-integration.txt',
        'tmp_name' => $tmp,
        'mime_type' => 'text/plain',
        'size' => strlen($bytes),
        'extension' => 'txt',
        'checksum_sha256' => hash('sha256', $bytes),
    ], [
        'kind' => 'course_resource',
        'course_id' => 999999999,
        'uploader_user_id' => 999999999,
    ]);

    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('failed to create download verification stream');
    }
    $metadata = $storage->downloadToStream((string)$stored['file_id'], $stream);
    rewind($stream);
    $downloaded = (string)stream_get_contents($stream);
    fclose($stream);

    if (!hash_equals(hash('sha256', $bytes), hash('sha256', $downloaded))) {
        throw new RuntimeException('downloaded SHA-256 did not match uploaded bytes');
    }
    if (($metadata['app_properties']['kairos_storage_key'] ?? '') !== ($stored['storage_key'] ?? '')) {
        throw new RuntimeException('downloaded metadata did not match the stored object');
    }

    $storage->delete((string)$stored['file_id']);
    $trashed = $storage->getMetadata((string)$stored['file_id']);
    if (empty($trashed['trashed'])) {
        throw new RuntimeException('test file was not moved to trash');
    }

    // A successful integration test must leave no artifact. This requires
    // organizer/Manager permission in the dedicated test Shared Drive.
    $client = new Google\Client();
    $client->setApplicationName('Kairos Drive Integration Test');
    $client->setAuthConfig((string)realpath($credentialsPath));
    $client->setScopes([Google\Service\Drive::DRIVE]);
    $service = new Google\Service\Drive($client);
    $service->files->delete((string)$stored['file_id'], ['supportsAllDrives' => true]);

    try {
        $storage->getMetadata((string)$stored['file_id']);
        throw new RuntimeException('test file still exists after permanent cleanup');
    } catch (DriveStorageException $e) {
        if ($e->reason() !== 'not_found') {
            throw $e;
        }
    }

    echo "drive storage integration test passed" . PHP_EOL;
} catch (Throwable $e) {
    if (is_array($stored) && !empty($stored['file_id'])) {
        try {
            $storage->delete((string)$stored['file_id']);
        } catch (Throwable) {
            // Report the primary failure; operators must inspect the dedicated test folder.
        }
    }
    fwrite(STDERR, 'drive storage integration test failed: ' . get_class($e) . PHP_EOL);
    exit(1);
} finally {
    @unlink($tmp);
}
