# LMS Backend Rollout

## Migrations
Run manual SQL migration in order:

```bash
mariadb -u <user> -p < sql/20260221_1200_lms_expansion_core.sql
mariadb -u <user> -p < db/migrations/20260613_1430_add_announcement_publication_audit.sql
```

The announcement migration must run before deploying the announcement detail/mutation UI. Reorder and embed
hardening require no schema change.

The assignment upload policy uses the existing columns introduced by
`sql/20260227_1030_assignment_restrictions_and_quiz_question_required.sql`. Confirm those columns exist before
deploying:

```sql
SHOW COLUMNS FROM lms_assignments
WHERE Field IN ('allowed_file_extensions', 'max_file_mb');
```

This LMS polish pass adds no migration and does not mutate schema at runtime.

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
- Assignment upload policy: `/api/lms/assignments/upload-policy.php`
- Grading: queue/details/grade/release under `/api/lms/grading/...`
- Announcements: list/detail/create/update/delete/read under `/api/lms/announcements...`
- Analytics: `/api/lms/analytics/course/get.php`

## Assignment and quiz staging smoke

1. Manager opens Assignments, creates a draft, selects multiple upload presets, adds a custom safe extension, saves,
   and confirms the values rehydrate on edit.
2. Student confirms allowed types, maximum effective size, points, due date, and selected filename are visible.
3. Submit one valid PDF, one disallowed extension, one MIME-mismatched file, one oversized file, and one SVG.
4. Confirm every rejected file returns sanitized `422`, creates no submission row, and is never uploaded to Drive.
5. Manager creates/edits a quiz and adds/edits MCQ, multiple-select, true/false, and written-response questions.
6. Traverse course, modules, lesson, resource, quizzes, quiz, assignments, assignment, grading, and analytics by direct
   load and internal links. Grading follows `grade_course`; Analytics follows `manage_course`.
7. Repeat at `390x844`, `768x1024`, `1440x900`, and a large desktop in Light and Default Dark.

## Rollback

Restore the previous application files as one artifact. No schema rollback is needed for this pass. Existing
`allowed_file_extensions` and `max_file_mb` data can remain in place. If upload behavior is implicated, set
`GOOGLE_DRIVE_WRITES_ENABLED=false` before rollback so protected reads remain available while new writes stop.
