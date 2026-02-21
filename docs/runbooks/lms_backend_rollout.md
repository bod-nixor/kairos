# LMS Backend Rollout

## Migrations
Run manual SQL migration in order:

```bash
mariadb -u <user> -p < sql/20260221_1200_lms_expansion_core.sql
```

## Drive configuration
Environment variables:
- `GOOGLE_DRIVE_ENABLED` (`0`/`1`)
- `LMS_DRIVE_PREVIEW_BASE` (defaults to `https://drive.google.com/file/d`)
- `GOOGLE_SERVICE_ACCOUNT_JSON` (reserved for full Drive integration wiring)
- `GOOGLE_SHARED_DRIVE_ID` (reserved)
- `GOOGLE_DRIVE_BASE_FOLDER_ID` (reserved)

Current implementation stores resource metadata with a stubbed Drive adapter and keeps file access controlled via LMS authorization.

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
