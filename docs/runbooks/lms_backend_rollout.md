# LMS Backend Rollout

## Migrations
Run manual SQL migration in order:

```bash
mariadb -u <user> -p < sql/20260221_1200_lms_expansion_core.sql
```

## Drive configuration
Install locked PHP dependencies before enabling Drive:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
composer audit --locked
```

Environment variables:

- `GOOGLE_DRIVE_ENABLED`
- `GOOGLE_DRIVE_WRITES_ENABLED`
- `GOOGLE_DRIVE_AUTH_MODE=service_account`
- `GOOGLE_DRIVE_CREDENTIALS_PATH`
- `GOOGLE_DRIVE_SHARED_DRIVE_ID`
- `GOOGLE_DRIVE_ROOT_FOLDER_ID`
- `GOOGLE_DRIVE_MAX_UPLOAD_BYTES`

The adapter uses private Shared Drive files, resumable uploads, post-upload SHA-256 verification, and RBAC-mediated
downloads. Keep writes disabled until the staging smoke test passes. Full service-account setup, folder layout,
credential rotation, rollback, and orphan reconciliation are documented in `docs/runbooks/google_drive_storage.md`.

No schema migration is required for this integration. Existing `lms_resources` columns and `metadata_json` store the
canonical Drive mapping and cleanup state.

## Realtime outbox pipeline
- PHP writes events into `lms_event_outbox` using payload fields:
  `event_name`, `event_id`, `occurred_at`, `actor_id`, `entity_type`, `entity_id`, `course_id`.
- Python `ws_server.py` polls undelivered rows and emits to Socket.IO rooms (`course:<id>`), then marks rows `delivered_at`.
- Polling controls:
  - `LMS_OUTBOX_ENABLED` (default enabled)
  - `LMS_OUTBOX_POLL_SECONDS` (default `1`)

## Endpoint groups
- Session capabilities: `/api/session_capabilities.php`
- Feature flags: `/api/lms/features.php`, `/api/lms/features/set.php`
- Branding: `/api/lms/branding.php`, `/api/lms/branding/set.php`
- Content: sections/lessons/lesson blocks + completion endpoints under `/api/lms/...`
- Resources: upload/get under `/api/lms/resources/...`
- Quiz: CRUD, questions, attempts under `/api/lms/quiz/...`
- Assignments: CRUD, submission, TA assignment under `/api/lms/assignments/...`
- Grading: queue/details/grade/release under `/api/lms/grading/...`
- Announcements: list/create under `/api/lms/announcements...`
- Analytics: `/api/lms/analytics/course/get.php`
