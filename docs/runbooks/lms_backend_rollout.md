# LMS Backend Rollout

## Migrations
Run manual SQL migration in order:

```bash
mariadb -u <user> -p < sql/20260221_1200_lms_expansion_core.sql
mariadb -u <user> -p < db/migrations/20260613_1430_add_announcement_publication_audit.sql
mariadb -u <user> -p < db/migrations/20260614_1327_ensure_assignment_upload_settings.sql
```

The announcement migration must run before deploying the announcement detail/mutation UI. Reorder and embed
hardening require no schema change.

The assignment upload policy requires the canonical guarded migration
`db/migrations/20260614_1327_ensure_assignment_upload_settings.sql`. Confirm the columns after applying it:

```sql
SHOW COLUMNS FROM lms_assignments
WHERE Field IN ('allowed_file_extensions', 'max_file_mb');
```

The application never mutates schema at runtime. Missing restriction columns return a sanitized `503` instead of a
false-success response.

The public-course access and self-enrollment pass also adds no migration. Before deploy, confirm:

```sql
SHOW COLUMNS FROM courses WHERE Field IN ('is_active', 'visibility');
SHOW TABLES LIKE 'student_courses';
SHOW TABLES LIKE 'course_allowlist';
SHOW TABLES LIKE 'course_pre_enroll';
```

Self-enrollment is transactional and writes only `student_courses`. Public preview does not authorize modules, rooms, queues, or realtime subscriptions.

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
5. Disable Drive writes, edit assignment types/max size, save, and reopen; metadata must persist without a storage
   error. A valid file upload should then return storage `503` after local validation.
6. Remove an assignment from a module and confirm it remains in Assignments. Delete it from detail and confirm it
   disappears from Modules, Assignments, direct links, student pages, and active grading while history remains stored.
7. Repeat the remove/delete checks for a quiz. A quiz with an in-progress attempt must return `409`.
8. Confirm student, TA, and foreign-course manager remove/delete requests fail.
9. Manager creates/edits a quiz and adds/edits MCQ, multiple-select, true/false, and written-response questions.
10. Traverse course, modules, lesson, resource, quizzes, quiz, assignments, assignment, grading, and analytics by direct
   load and internal links. Grading follows `grade_course`; Analytics follows `manage_course`.
11. Repeat at `390x844`, `768x1024`, `1440x900`, and a large desktop in Light and Default Dark.

## Public-course and role-context smoke

1. As an unassigned TA and manager, open an active public course and confirm metadata plus the enrol CTA render without a generic 403.
2. Confirm Modules, Quizzes, Assignments, Grading, and Analytics are hidden in public-preview context.
3. Enrol and confirm published student content and rooms become available without a full course-page reload.
4. Confirm self-enrollment creates a `student_courses` row and no staff mapping.
5. Confirm assigned-course TA/manager controls remain present only in the assigned course.
6. Downgrade a former admin, start a fresh request, and confirm the DB role and course mappings determine all controls.
7. Confirm a public-only user cannot connect to `course:<id>` until enrolled.
8. Confirm course links remain ordinary URLs and work with direct load, refresh, modifier-click, Back, and Forward.

## Rollback

Restore the previous application files as one artifact. Keep `allowed_file_extensions` and `max_file_mb` in place;
older code can ignore additive columns. Drop them only after code rollback and explicit data-loss approval. If upload behavior is implicated, set
`GOOGLE_DRIVE_WRITES_ENABLED=false` before rollback so protected reads remain available while new writes stop.
