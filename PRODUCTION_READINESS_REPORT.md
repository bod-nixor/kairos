# Kairos Production Readiness Report

**Review date:** June 12, 2026  
**Production route:** `https://kairos.nixorcorporate.com/signoff/`

## Executive Summary

This pass fixed the known production CSP and realtime failures, closed additional course-scope authorization gaps, implemented durable private Google Drive storage, improved shared responsive/accessibility behavior, and added repeatable regression coverage.

The application code is ready for a controlled staging deployment after the required infrastructure steps in `PRODUCTION_DEPLOYMENT_CHECKLIST.md`. No database migration is required. Drive writes must remain disabled until Composer dependencies, service-account access, and the authenticated staging smoke test are complete.

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

- Vendored Socket.IO 4.7.5 under `public/vendor/socket.io/4.7.5/`.
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
- Verified explicit theme token sets for Default Dark, Light, Midnight, Graphite, Indigo, and Emerald.

## Browser Verification

Production baseline:

- Login page rendered at the canonical route with no horizontal overflow.
- Google Sign-In markup rendered, but the production policy omitted its stylesheet origin.
- `window.io` was unavailable because the production CSP blocked the CDN client.
- The production Socket.IO polling endpoint itself returned HTTP 200.

Local static browser verification after changes:

- The local Socket.IO asset returned HTTP 200 with JavaScript MIME type.
- The disconnected indicator progressed from retry `1/8` through `8/8`, then settled on “Realtime updates are unavailable. Changes still save normally.”
- No horizontal overflow at `390x844`, `768x1024`, or `1440x900`.
- Mobile settings control measured `46x46` pixels.
- Theme switching was exercised for dark, light, and midnight; all six theme definitions and required tokens are covered by the production contract test.
- The resource viewer and its updated JavaScript loaded successfully from a local PHP server; the unauthenticated request redirected to the login shell as expected.
- The in-app screenshot capture command timed out, but DOM, computed-style, viewport, and network checks completed.

Authenticated student/TA/manager/admin workflows could not be exercised locally without OAuth credentials, a test database, and the Python realtime dependencies. Exact deployment smoke tests are in the deployment checklist.

Live Google Drive upload/download calls were not run because no production or staging credentials were used. Provider activation remains an explicit deployment gate.

## Tests and Results

Passed:

```bash
bash tools/check-errors.sh
node tools/tests/realtime_runtime_test.mjs
node tools/tests/production_contract_test.mjs
php tools/tests/drive_storage_test.php
php tools/tests/drive_storage_integration_test.php  # safe default: skipped
git diff --check
```

All safe PHP tests passed. The real Drive integration test and destructive DB integration test skipped safely unless explicitly enabled:

```bash
KAIROS_DRIVE_TESTS=1 php tools/tests/drive_storage_integration_test.php
KAIROS_DB_TESTS=1 php tools/tests/sections_reorder_endpoint_test.php
```

Run the Drive test only with the dedicated test Shared Drive variables in `docs/runbooks/google_drive_storage.md`.
Run the DB command only against an isolated test database. The current DB test uses legacy LMS table names and may
require separate modernization before it can validate the current schema.

Additional checks:

```bash
node --check public/js/ws.js
node --check public/js/lms-ws.js
node --check public/js/lms-core.js
python3 -m py_compile ws_server.py ws_emit.py
sha256sum public/vendor/socket.io/4.7.5/socket.io.min.js
composer validate --strict --no-check-publish
composer audit --locked
```

Vendored Socket.IO SHA-256:

```text
73eba16bc895fdfa454e27ecb80def31ede8d861f99e175ff93b110eabec044f
```

## Remaining Requirements

1. Deploy the full change set, purge Cloudflare caches, and restart the Python realtime service.
2. Disable Cloudflare Browser Insights injection and Rocket Loader for `/signoff/*`.
3. Install locked Composer dependencies and configure the private Shared Drive/service account.
4. Enable Drive reads first, then writes only after the staging storage smoke test passes.
5. Run authenticated role and cross-course authorization smoke tests after deployment.
6. Confirm Apache applies the new CSP and blocks `/vendor`, `composer.json`, and `composer.lock`.

## Optional Follow-ups

- Prefer external scripts for new bootstrap behavior; existing inline scripts are restricted by immutable CSP hashes.
- Replace the legacy `sections_reorder_endpoint_test.php` schema setup with current migrations and transaction-based fixtures.
- Add an automated browser suite with test OAuth/session fixtures for every role and all six themes.
- Cache websocket authorization mappings briefly to reduce information-schema queries at high connection volume.
