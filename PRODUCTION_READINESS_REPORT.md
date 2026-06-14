# Kairos Production Readiness Report

**Review date:** June 14, 2026
**Production route:** `https://kairos.nixorcorporate.com/signoff/`

## Executive Summary

This pass fixed the known production CSP and realtime failures, centralized course capabilities, closed additional publication and object-scope authorization gaps, added auditable announcement management, repaired transactional module ordering, hardened external embeds, improved Light Mode contrast, stabilized the shared course shell and module interactions, implemented durable private Google Drive storage, and added repeatable regression coverage.

The assignment and quiz polish pass additionally replaced raw assignment markup in list cards with sanitized excerpts,
added a shared production-quality assignment/quiz/question editor, introduced rehydratable upload presets and custom
extension controls, enforced assignment extension/MIME/size policy before storage, and removed page-local navigation
capability guesses.

The public-course modernization pass separates public preview, enrolled participation, assigned staff authority, and admin authority. It fixes the downgraded-admin/TA/manager 403 by making student enrollment independent of global role, permits transactional self-enrollment for eligible authenticated users, keeps dependent content and realtime rooms protected, and adds browser-managed speculative course navigation with safe full-navigation fallback.

The LMS integrity pass now distinguishes module unlinking from true quiz/assignment deletion, filters archived parents
from active module/detail/list/grading paths, adds manager/admin delete controls, persists assignment upload settings
without Drive, and makes realtime content events usable as REST cache-invalidation signals.

The application code is ready for a controlled staging deployment after the required infrastructure steps in `PRODUCTION_DEPLOYMENT_CHECKLIST.md`. The migrations `db/migrations/20260613_1430_add_announcement_publication_audit.sql`, `db/migrations/20260614_1327_ensure_assignment_upload_settings.sql`, `db/migrations/20260614_1600_create_lms_assignment_notes.sql`, and `db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql` are required before the related API/UI is deployed. Drive writes must remain disabled until Composer dependencies, service-account access, and the authenticated staging smoke test are complete.

## Baseline Findings

Production inspection and repository review identified:

- Socket.IO 4.7.5 was loaded from `cdn.socket.io`, but the CSP did not allow that origin.
- `ws.js` called bare `io(...)` without checking that the client library loaded, then retried indefinitely.
- Google Identity Services requested `https://accounts.google.com/gsi/style`, which was absent from `style-src`.
- Cloudflare injected an optional Insights beacon that the restrictive CSP correctly blocked.
- Rocket Loader appeared in production stack traces and could reorder critical scripts.
- `play.google.com/log` was blocked by browser/privacy tooling; it is not an application dependency.
- LMS named Socket.IO events were never passed from `ws.js` to `lms-ws.js`.
- LMS pages loaded the adapter but never initialized it.
- Any valid WebSocket token could request an arbitrary `course_id` and join that course event room.
- API-produced realtime events were broadcast to all connected clients instead of scoped subscription rooms.
- Global managers bypassed course scope in the central LMS access helper and course list.
- Queue participant and ETA endpoints accepted arbitrary queue IDs without course-scope authorization.
- LMS resource uploads allowed student/TA roles.
- The Drive upload stub generated fake metadata without persisting file content.
- Resource and grading payloads exposed stored preview URLs instead of enforcing reads through Kairos authorization.

## Implemented Changes

### CSP and Asset Loading

- Vendored Socket.IO 4.7.5 under `public/assets/vendor/socket.io/4.7.5/`.
- Replaced every Socket.IO CDN reference with the repository-controlled asset.
- Added `data-cfasync="false"` to external and application script tags.
- Added explicit CSP directives for script/style elements and attributes, workers, media, manifests, frames, and the canonical secure WebSocket origin.
- Narrowly allowed Google Sign-In styles from `accounts.google.com`.
- Did not allowlist Cloudflare Insights or `play.google.com` telemetry.

### Realtime Reliability

- Added a transport-library guard with one clear diagnostic.
- Added bounded exponential backoff with jitter and an eight-attempt ceiling.
- Prevented duplicate concurrent connection attempts and debounced filter restarts.
- Added online/offline recovery and timer cleanup.
- Added an accessible connection-status pill for connecting, retrying, offline, and unavailable states.
- Added generic named-event dispatch so LMS events reach `LmsWS`.
- Auto-initialized the LMS adapter from course URL context and retained event-ID deduplication.
- Added server-side course/room authorization before joining Socket.IO rooms.
- Scoped API events to channel/course/room rooms and dropped LMS outbox events missing course scope.

### Backend Enforcement and Data Integrity

- Centralized LMS course checks on existing DB-driven RBAC policies.
- Added named `view_course`, `manage_course`, `grade_course`, progress, announcement, course-creation, and staff-assignment capabilities.
- Restricted TA reads to published course content and filtered student grade reads to released grades.
- Added stored submission/assignment/course ownership checks and assignment-level TA grading checks.
- Required target-student enrollment for TA progress reads and writes.
- Added announcement draft/publish state, soft deletion, durable mutation audit, and transactional outbox events.
- Added a course-scoped announcement detail read model, persistent bell read state, safe manager audit summaries, and draft-safe realtime deltas.
- Replaced unsigned-column-breaking negative reorder positions with locked positive temporary positions, strict ID validation, stale-order conflict detection, and transactional reorder events.
- Restricted managers and TAs to assigned courses; only administrators retain global course access.
- Scoped the LMS course list by accessible course IDs.
- Added queue-scope authorization to participant and ETA endpoints.
- Restricted LMS file-resource upload to manager/admin roles.
- Replaced the non-durable Drive stub with the official Google API PHP client and a mockable storage interface.
- Added private Shared Drive folder management for course resources and per-student assignment submissions.
- Added resumable uploads, byte-count verification, and SHA-256 verification before DB success.
- Added compensating Drive cleanup for DB/finalization failures.
- Added database-first resource deletion followed by recoverable Drive trashing and retryable cleanup state.
- Added a protected download/preview endpoint that accepts only a local resource ID, applies course/submission RBAC, revalidates metadata/checksum, and never exposes raw Drive IDs.
- Added a separate Drive write switch so uploads/deletes can be frozen without disabling authenticated reads.
- Version-gated deprecated PHP session-ID settings for PHP 8.4 compatibility.

### UI, Responsive, and Accessibility

- Added consistent global `:focus-visible` treatment.
- Added reduced-motion support based on operating-system preference.
- Improved phone modal sizing with visual-viewport limits.
- Improved shared modal focus placement, focus restoration, Escape handling, and background-scroll locking.
- Kept the existing unified `.k-sidebar`, `.k-topbar`, `.k-main` shell and verified consistent Home/Modules/Quizzes/Assignments navigation across ten LMS surfaces.
- Centralized role-aware course navigation in `public/js/lms-core.js`, including grading/analytics capability gates and direct-load breadcrumb repair.
- Replaced module fake buttons and nested interactions with native accordion buttons, real item anchors, stable delegation, and mobile-size targets.
- Added authorized announcement edit/delete controls and destructive confirmation.
- Added full announcement detail actions from the feed and notification bell without removing read entries.
- Added touch-friendly module-item move controls, duplicate-submission guards, and clean UI rollback/reload on reorder conflicts.
- Added provider-specific iframe policies, privacy-enhanced YouTube URLs, lazy loading, and persistent external/download fallbacks.
- Added semantic Light Mode tokens and automated contrast checks for text, links, focus, controls, placeholders, disabled states, and statuses.
- Normalized the appearance settings launcher with explicit flex centering and icon metrics.
- Verified explicit theme token sets for Default Dark, Light, Midnight, Graphite, Indigo, and Emerald.
- Added polished assignment/quiz cards and shared sectioned management dialogs with inline validation, disabled save
  states, native-dialog keyboard behavior, focus restoration, and responsive one-column layouts.
- Added student-facing assignment upload guidance for resolved types, effective size limit, and points, plus a filtered
  file picker and selected-file display.
- Centralized rich-text sanitization. Assignment and quiz content strips active tags, event attributes, unsafe links,
  and iframes; lesson embeds require an explicit provider-approved mode.

### Assignment Upload Enforcement

- Added Documents, Images, Video, Audio, Archives, Code, PDF-only, Spreadsheets, Presentations, and Custom selectors.
- Normalized extensions to lowercase, removed leading dots, deduplicated overlaps, and restored preset/custom state on edit.
- Excluded SVG and active web/script/executable formats from both UI presets and server policy.
- Added server MIME detection and OOXML/ODF/container checks before Drive upload or DB transaction.
- Returned stable sanitized `422` messages for disallowed type, content mismatch, and effective size limit failures.
- Removed the update endpoint's silent core-column fallback, which previously returned success while dropping upload
  setting changes when the restriction columns were unavailable.
- Added an explicit schema guard shared by create/update/detail/upload and a canonical `db/migrations/` artifact.
- Assignment update responses now return the normalized saved extension list and max MiB value; the editor and
  student picker rehydrate those authoritative values.
- Empty extension policy explicitly means any supported safe format. Active content, including SVG, remains blocked.
- Metadata-only saves do not initialize Drive. Valid uploads with Drive writes disabled receive a sanitized storage
  `503` only after extension, MIME/container, and size checks pass.

### Quiz and Assignment Deletion Consistency

- Root cause: Modules deleted only the `lms_module_items` link, but the UI called the action Delete and exposed no
  separate parent-delete action.
- Module UI/API now call that operation **Remove from module** and explicitly preserve the underlying content.
- Assignment/quiz detail pages expose separate confirmed delete actions for assigned managers/admins.
- Parent deletion is a transactional soft delete: set `deleted_at`, set `status=archived`, remove all module links,
  and enqueue `assignment.deleted`/`quiz.deleted` before commit.
- Active module, list, detail, quiz-attempt, question-mutation, submission-grading, and student paths reject deleted
  parents. Historical submissions, attempts, grades, audit rows, and files remain stored for audit/recovery.
- Publish and mandatory changes no longer silently recreate a removed module link.
- Modules, standalone lists, and detail pages re-fetch authoritative REST state on course-scoped realtime events.

### Public Course Access and Navigation

- Added a pure course-access decision model with named public, enrolled, student-participation, grading, management, and admin capabilities.
- Made `student_courses` participation available to Student, TA, and Manager users without granting staff authority.
- Added public course metadata access and an in-page enrol CTA instead of the incorrect generic 403.
- Made discovery, detail, rooms, queues, and realtime use the same access facts.
- Made self-enrollment transactional, idempotent, active-course aware, allowlist/pre-enrollment aware, and limited to `student_courses`.
- Deferred protected course API calls and course WebSocket subscription until enrolled/assigned access is confirmed.
- Added short-lived in-memory bootstrap/course metadata deduplication and realtime invalidation.
- Added same-origin Speculation Rules prefetch and navigation progress feedback. Normal URLs remain authoritative; WebSockets are not used for navigation.
- Deferred DOM-swapping partial navigation because existing page controllers do not yet expose safe mount/unmount lifecycles.
- Added no public-course schema migration; the policy uses existing visibility/enrollment tables.

## Browser Verification

Production baseline:

- Login page rendered at the canonical route with no horizontal overflow.
- Google Sign-In markup rendered, but the production policy omitted its stylesheet origin.

Local LMS integrity fixture:

- Rendered the assignment editor at `1280x900` Light and `390x844` Default Dark.
- Confirmed no page or modal horizontal overflow, focus remained inside the open dialog, and the mobile dialog kept
  its fixed action footer and one-column preset layout.
- Confirmed saved `Documents` plus custom `.json` restrictions rehydrated and `max_file_mb` rendered as `25`.
- Confirmed the sanitized preview contained no script/iframe/event-handler nodes and its list excerpt contained no
  markup.
- The in-app browser controller could not navigate to localhost because its thread security handoff was unavailable;
  local headless Chrome was used for equivalent DOM/screenshot checks.
- `window.io` was unavailable because the production CSP blocked the CDN client.
- The production Socket.IO polling endpoint itself returned HTTP 200.

Local static browser verification after changes:

- The local Socket.IO asset returned HTTP 200 with JavaScript MIME type.
- The disconnected indicator progressed from retry `1/8` through `8/8`, then settled on “Realtime updates are unavailable. Changes still save normally.”
- No horizontal overflow at `390x844`, `768x1024`, or `1440x900`.
- Mobile settings control measured `46x46` pixels.
- Theme switching was exercised for dark, light, and midnight; all six theme definitions and required tokens are covered by the production contract test.
- The resource viewer and its updated JavaScript loaded successfully from a local PHP server; the unauthenticated request redirected to the login shell as expected.
- The in-app browser could not establish its local navigation security handoff. Installed headless Chrome was used
  against the local preview harness instead.
- Captured assignment list screenshots in Light and Default Dark, an assignment editor phone screenshot, the upload
  preset editor in Light, and the quiz editor at tablet size.
- Verified no document or modal horizontal overflow at `390x844`, `768x1024`, `1440x900`, and `1920x1080`.
- Verified sanitized assignment output contained zero script/iframe/event-handler nodes and list excerpts contained no markup.
- Verified runtime navigation sets: student gets core links, TA adds Grading, manager/admin add Grading and Analytics.
- Verified modal focus starts inside the dialog, save disables controls and sets busy state, and Escape closes with focus
  restored to the trigger.

Authenticated student/TA/manager/admin workflows could not be exercised locally without OAuth credentials and a test database. The in-app browser's local-navigation security handoff was also unavailable for the mocked role fixture in this session. Deterministic policy, endpoint-contract, UI, navigation, and realtime-subscription tests cover the flow; exact deployment smoke tests are in the deployment checklist.

Live Google Drive upload/download calls were not run because no production or staging credentials were used. Provider activation remains an explicit deployment gate.

## Tests and Results

Passed:

```bash
bash tools/check-errors.sh
node tools/tests/realtime_runtime_test.mjs
node tools/tests/production_contract_test.mjs
node tools/tests/course_ui_contract_test.mjs
node tools/tests/csp_nonce_response_test.mjs
node tools/tests/navigation_performance_test.mjs
node tools/tests/lms_management_contract_test.mjs
node tools/tests/ui_hardening_contract_test.mjs
php tools/tests/course_authorization_policy_test.php
php tools/tests/announcement_authorization_test.php
php tools/tests/resource_embed_policy_test.php
php tools/tests/sections_reorder_endpoint_test.php
php tools/tests/drive_storage_test.php
php tools/tests/lms_upload_and_question_policy_test.php
php tools/tests/drive_storage_integration_test.php  # safe default: skipped
git diff --check
```

All safe PHP tests passed. The real Drive integration test skipped safely unless explicitly enabled:

```bash
KAIROS_DRIVE_TESTS=1 php tools/tests/drive_storage_integration_test.php
```

Run the Drive test only with the dedicated test Shared Drive variables in `docs/runbooks/google_drive_storage.md`.
The reorder regression test is deterministic and does not connect to a database or mutate schema.

Additional checks:

```bash
node --check public/js/ws.js
node --check public/js/lms-ws.js
node --check public/js/lms-core.js
python3 -m py_compile ws_server.py ws_emit.py
sha256sum public/assets/vendor/socket.io/4.7.5/socket.io.min.js
composer validate --strict --no-check-publish
composer audit --locked
```

Vendored Socket.IO SHA-256:

```text
73eba16bc895fdfa454e27ecb80def31ede8d861f99e175ff93b110eabec044f
```

## Remaining Requirements

1. Back up the database and apply the following migrations:
   - `db/migrations/20260613_1430_add_announcement_publication_audit.sql`
   - `db/migrations/20260614_1327_ensure_assignment_upload_settings.sql`
   - `db/migrations/20260614_1600_create_lms_assignment_notes.sql`
   - `db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql`
2. Deploy the full change set, purge Cloudflare caches, and restart the Python realtime service.
3. Disable Cloudflare Browser Insights injection and Rocket Loader for `/signoff/*`.
4. Install locked Composer dependencies and configure the private Shared Drive/service account.
5. Enable Drive reads first, then writes only after the staging storage smoke test passes.
6. Run authenticated role and cross-course authorization smoke tests after deployment.
7. Confirm Apache applies the new CSP and blocks `/vendor`, `composer.json`, and `composer.lock`.

## Optional Follow-ups

- Prefer external scripts for new bootstrap behavior; existing inline scripts are restricted by immutable CSP hashes.
- Add an automated browser suite with test OAuth/session fixtures for every role and all six themes.
- Cache websocket authorization mappings briefly to reduce information-schema queries at high connection volume.
