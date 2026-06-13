# Google Drive Storage Runbook

## Purpose

Kairos stores uploaded LMS resources and assignment submission files as private blob files in a Google Shared Drive. MySQL remains authoritative for ownership, course scope, submission linkage, checksums, and access policy. Browser clients never receive a raw Drive file ID or a Drive sharing URL.

Official references:

- [Google API PHP client](https://github.com/googleapis/google-api-php-client)
- [Drive resumable uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads)
- [Shared Drive API support](https://developers.google.com/workspace/drive/api/guides/enable-shareddrives)
- [Drive blob downloads](https://developers.google.com/workspace/drive/api/guides/manage-downloads)

## Runtime Dependencies

Install the locked production dependencies from the repository root:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
composer audit --locked
```

The lock currently installs `google/apiclient 2.19.3` and `google/apiclient-services 0.444.0`. Composer cleanup retains only the Drive service wrapper.

Required PHP extensions:

- `fileinfo`, `json`, and `openssl`
- `pdo_mysql` for the application database

Recommended:

- `curl` for more efficient Google API transport
- `zip` so OOXML (`docx`, `pptx`, `xlsx`) containers can be inspected before upload
- `mbstring` for Unicode-aware input length handling

Without `zip`, OOXML uploads fail validation rather than bypassing content checks.

## Google Cloud Setup

1. Create or select the Google Cloud project used for Kairos infrastructure.
2. Enable the Google Drive API.
3. Create a dedicated service account for Kairos storage.
4. Create a JSON key only if the cPanel runtime cannot use a keyless identity mechanism.
5. Store the JSON key outside the Kairos project and web root.
6. Set file ownership to the PHP/cPanel account and permissions to `0600`.

Do not use the browser OAuth client secret for server-side storage. OAuth refresh-token fallback is not implemented.

## Shared Drive Setup

1. Create or select a Shared Drive dedicated to Kairos academic files.
2. Add the service account as **Content manager**. Do not grant domain-wide or public access.
3. Create a root folder named `Kairos`.
4. Record the Shared Drive ID and the `Kairos` root folder ID.
5. Do not create public links or broad inherited groups on the Shared Drive.

Kairos creates these managed subfolders:

```text
Kairos/
  resources/
    course-<course_id>/
  submissions/
    course-<course_id>/
      assignment-<assignment_id>/
        user-<user_id>/
```

Stored filenames are opaque random values. Original filenames remain in MySQL metadata and are restored only in the authenticated `Content-Disposition` response.

## Environment

```env
GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_WRITES_ENABLED=false
GOOGLE_DRIVE_AUTH_MODE=service_account
GOOGLE_DRIVE_CREDENTIALS_PATH=/home/account/private/kairos-drive-service-account.json
GOOGLE_DRIVE_SHARED_DRIVE_ID=<shared-drive-id>
GOOGLE_DRIVE_ROOT_FOLDER_ID=<kairos-root-folder-id>
GOOGLE_DRIVE_MAX_UPLOAD_BYTES=26214400
```

Activation sequence:

1. Deploy code and run `composer install`.
2. Configure credentials and IDs.
3. Set `GOOGLE_DRIVE_ENABLED=true` and keep writes disabled.
4. Verify an existing test file can be downloaded through Kairos RBAC, if one exists.
5. Set `GOOGLE_DRIVE_WRITES_ENABLED=true`.
6. Run the upload/download/delete smoke checks below.

`GOOGLE_DRIVE_ENABLED=false` disables both reads and writes with sanitized `503` responses. `GOOGLE_DRIVE_WRITES_ENABLED=false` freezes uploads and managed-file deletion while preserving authenticated reads.

## Security Model

- The service account is the only Drive principal used by the application.
- Kairos does not call the Drive permissions API and does not create public links.
- `files.list` is scoped to the configured Shared Drive using `corpora=drive`, `driveId`, `includeItemsFromAllDrives`, and `supportsAllDrives`.
- Uploads use resumable transfer and are downloaded once server-side to verify byte count and SHA-256 before the API reports success.
- Each file receives opaque `appProperties` for its Kairos storage key and applicable course, assignment, submission, uploader, and resource identifiers.
- Downloads accept only a local `resource_id`. Kairos resolves the stored Drive ID server-side, applies course/submission RBAC, rechecks metadata and SHA-256, and streams bytes with private no-store headers.
- HTML, SVG, JavaScript, XML, and other active types are never served inline.
- Assignment uploads are restricted to the assignment's normalized extension allowlist. `finfo` MIME detection and
  Office/ODF container checks run before Drive is called.
- SVG is intentionally unsupported for assignment uploads. It is active content and is excluded from the Images
  preset, storage upload policy, and inline preview policy.

## Browser Preview Policy

Managed PDFs preview only through the authenticated same-origin Kairos inline endpoint. The iframe uses
`sandbox="allow-same-origin"` without script permission and always provides the authenticated download fallback.
External Google Drive/Docs/Slides links use their canonical `/preview` or `/embed` forms and retain an original-link
fallback. Office documents use the Microsoft Office viewer only when the source URL is a direct HTTPS document URL.
The complete provider matrix is in `docs/ui/resource_embed_policy.md`.

## Smoke Tests

Use test courses and test accounts only.

1. Manager uploads a PDF resource.
2. Confirm a new opaque file appears under `resources/course-<id>/`.
3. Confirm the API response contains a Kairos download URL but no Drive ID or Drive URL.
4. Student enrolled in the course previews/downloads the published PDF.
5. Student outside the course receives `403`.
6. Student submits a PDF assignment file.
7. Confirm it appears under the expected course/assignment/user folder.
8. The submitting student, assigned TA, course manager, and admin can download it.
9. Another student and an unassigned TA receive `403`.
10. Delete the test course resource and confirm the DB row is soft-deleted before the Drive file moves to trash.
11. Set `GOOGLE_DRIVE_WRITES_ENABLED=false`; confirm downloads still work while upload/delete return sanitized `503` errors.
12. For an assignment with a restricted preset, verify disallowed, MIME-mismatched, oversized, and SVG files return
    sanitized `422` responses and do not appear in the Shared Drive.

## Automated Tests

The ordinary mock suite never contacts Google:

```bash
php tools/tests/drive_storage_test.php
```

The real-provider test is opt-in and requires a dedicated test folder. The service account must have Manager/organizer
permission in the dedicated test Shared Drive so the test can permanently purge its generated artifact:

```bash
KAIROS_DRIVE_TESTS=1 \
GOOGLE_DRIVE_CREDENTIALS_PATH=/absolute/private/path/test-service-account.json \
GOOGLE_DRIVE_TEST_SHARED_DRIVE_ID=<test-shared-drive-id> \
GOOGLE_DRIVE_TEST_ROOT_FOLDER_ID=<dedicated-test-root-folder-id> \
GOOGLE_DRIVE_ROOT_FOLDER_ID=<production-root-folder-id> \
php tools/tests/drive_storage_integration_test.php
```

The harness refuses to use the configured production root, uploads generated bytes, downloads and verifies SHA-256,
moves the file to trash, permanently purges it, and confirms it no longer exists. Without `KAIROS_DRIVE_TESTS=1`, it
prints a skip and exits successfully.

## Failure and Recovery

Upload order is:

1. validate content and limits;
2. upload and verify Drive bytes;
3. create the MySQL resource/submission records;
4. attach final Kairos IDs to Drive `appProperties`;
5. commit MySQL.

If step 3 or 4 fails, Kairos retries Drive cleanup three times. Files are moved to Drive trash rather than permanently erased.

Delete order is:

1. soft-delete the MySQL resource and detach module items;
2. mark `storage_cleanup_pending` in metadata;
3. move the Drive file to trash;
4. clear the pending marker.

If Drive cleanup fails, the API returns `503 storage_cleanup_pending`. Repeating the same delete request retries cleanup for the soft-deleted row.

Kairos does not currently have an in-place binary replacement flow. Managed resources may be retitled, but changing
their URL is rejected. Upload a new managed resource, update the module reference, then delete the old resource after
the new file is verified.

## Orphan Reconciliation

All managed Drive files carry `kairos_managed=1` and a unique `kairos_storage_key`. Reconciliation is read-only until an operator reviews the result:

1. List non-trashed files below the configured root with `appProperties` containing `kairos_managed=1`.
2. Export active and deleted local mappings:

```sql
SELECT
    resource_id,
    drive_file_id,
    deleted_at,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.storage_key')) AS storage_key,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.storage_cleanup_pending')) AS cleanup_pending
FROM lms_resources
WHERE drive_file_id IS NOT NULL;
```

3. Flag Drive storage keys with no local row as upload orphans.
4. Flag soft-deleted rows with `cleanup_pending=true` as cleanup retries.
5. Confirm course/submission context from Drive `appProperties`.
6. Move reviewed orphans to trash. Do not permanently purge them during the same reconciliation pass.

Never delete a Drive file merely because its original filename does not match a DB title; stored filenames are intentionally opaque.

## Credential Rotation

1. Create a new service-account JSON key.
2. Place it outside the project root with `0600` permissions.
3. Update `GOOGLE_DRIVE_CREDENTIALS_PATH`.
4. Restart PHP workers/processes if the hosting environment caches environment variables.
5. Run an authenticated download and a test upload with writes enabled.
6. Revoke the old key in Google Cloud.
7. Remove the old key file from the server.

Do not log credential paths, key contents, OAuth tokens, raw Drive IDs, or student file contents.

## Rollback

For an integration incident:

1. Set `GOOGLE_DRIVE_WRITES_ENABLED=false` to stop mutations while retaining reads.
2. If credentials may be compromised, set `GOOGLE_DRIVE_ENABLED=false` and revoke the key. Managed files will return `503` until a replacement credential is configured.
3. Restore the previous application artifact only if its database/API contracts remain compatible.
4. Do not delete `lms_resources` mappings during rollback.
5. Re-enable reads, verify integrity, then re-enable writes.

No schema rollback is required for this integration.
