# Kairos Production Deployment Checklist

## Change Window

- [ ] Schedule a controlled deployment window.
- [ ] Back up the current `/signoff/` files and effective Apache configuration.
- [ ] Take a database backup before applying the announcement and assignment-settings migrations.
- [ ] Record the current Python realtime process command and rollback artifact.
- [ ] Confirm no unrelated working-tree files are included in the deployment package.

## Files to Deploy

Deploy the complete repository change set, including:

- `.htaccess` and `public/.htaccess`
- `public/html.php`, `public/index.php`, and `src/html_response.php`
- all page templates under `templates/pages/`
- `public/css/kairos-ui.css`
- `public/js/ws.js`, `public/js/lms-ws.js`, `public/js/lms-core.js`, `public/js/index-page.js`, `public/js/settings.js`, `public/js/manager.js`, and `public/js/ta.js`
- `public/js/navigation.js`, `public/js/course.js`, and `public/script.js`
- `src/course_access_policy.php` and `src/rbac.php`
- `public/assets/vendor/socket.io/4.7.5/socket.io.min.js` and `LICENSE`
- changed PHP API and RBAC consumers under `public/api/`
- `composer.json` and `composer.lock`
- `public/api/lms/integrations/drive/` and `public/api/lms/resources/download.php`
- `ws_server.py`
- `db/migrations/20260613_1430_add_announcement_publication_audit.sql`
- `db/migrations/20260614_1327_ensure_assignment_upload_settings.sql`
- `db/migrations/20260614_1600_create_lms_assignment_notes.sql`
- `db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql`
- updated security documentation, runbooks, and test files

Apply all SQL migrations before deploying the related API/UI.

## Database Migration

- [ ] Confirm `lms_announcements` and `lms_event_outbox` are present.
- [ ] Apply:

```bash
mariadb -u <user> -p < db/migrations/20260613_1430_add_announcement_publication_audit.sql
mariadb -u <user> -p < db/migrations/20260614_1327_ensure_assignment_upload_settings.sql
mariadb -u <user> -p < db/migrations/20260614_1600_create_lms_assignment_notes.sql
mariadb -u <user> -p < db/migrations/20260614_1605_add_staff_private_note_to_lms_grades.sql
```

- [ ] Verify `lms_announcements.status`, `published_at`, and `idx_lms_announcements_course_status`.
- [ ] Verify `lms_announcement_audit` and its foreign keys/indexes.
- [ ] Verify `lms_assignments.allowed_file_extensions` and `lms_assignments.max_file_mb`.
- [ ] Verify the table `lms_assignment_notes` has been created with primary keys and `student_user_id` index.
- [ ] Verify the table `lms_grades` contains columns: `staff_private_note`, `grade_override`, `rubric_grades_json`.
- [ ] Do not run the rollback unless application code has first been rolled back and audit retention has been approved.

## Environment Verification

- [ ] Keep `WS_SHARED_SECRET` identical between PHP and Python; do not rotate it during this deploy.
- [ ] Confirm `WS_PUBLIC_URL=wss://kairos.nixorcorporate.com`.
- [ ] Confirm `WS_SOCKET_PATH=/websocket/socket.io/`.
- [ ] Confirm `WS_ALLOWED_ORIGINS=https://kairos.nixorcorporate.com`.
- [ ] Confirm DB credentials are available to `ws_server.py`; course subscriptions now fail closed when DB authorization cannot run.
- [ ] Confirm `APP_ORIGIN` and `PUBLIC_APP_ORIGIN` are `https://kairos.nixorcorporate.com`.
- [ ] Run `composer install --no-dev --classmap-authoritative --no-interaction`.
- [ ] Run `composer audit --locked`.
- [ ] Confirm `/vendor`, `/composer.json`, and `/composer.lock` are not web-accessible.
- [ ] Configure `GOOGLE_DRIVE_AUTH_MODE=service_account`.
- [ ] Store `GOOGLE_DRIVE_CREDENTIALS_PATH` outside the project/web root with `0600` permissions.
- [ ] Configure `GOOGLE_DRIVE_SHARED_DRIVE_ID`, `GOOGLE_DRIVE_ROOT_FOLDER_ID`, and `GOOGLE_DRIVE_MAX_UPLOAD_BYTES`.
- [ ] Add the service account to the dedicated Shared Drive as Content manager; do not grant public/domain sharing.
- [ ] Set `GOOGLE_DRIVE_ENABLED=true` and keep `GOOGLE_DRIVE_WRITES_ENABLED=false` until read verification completes.
- [ ] Follow `docs/runbooks/google_drive_storage.md` before enabling writes.
- [ ] Run the opt-in Drive integration test against a dedicated test Shared Drive/root; never point it at production.
- [ ] Confirm the Google OAuth client includes `https://kairos.nixorcorporate.com` and the production callback/origin configuration.
- [ ] If deploying to a non-production hostname, update the exact HTTPS/WSS CSP origins in `src/html_response.php` before that deployment.
- [ ] Confirm PHP has access to `random_bytes()`; PHP 8.1+ provides it without an extra package.

## Cloudflare

- [ ] Keep Cloudflare Bot Fight Mode enabled.
- [ ] Keep JavaScript Detections enabled. Bot Fight Mode enables it automatically.
- [ ] Do not create a Bot Fight Mode bypass for `/signoff/`; Bot Fight Mode is expected to inject its detection script into HTML.
- [ ] Create a configuration rule for `/signoff/*` that disables Rocket Loader.
- [ ] Disable Browser Insights/Web Analytics script injection for `/signoff/*`. If route-level exclusion is unavailable, disable it for the zone/application.
- [ ] Do not add `static.cloudflareinsights.com` to Kairos CSP merely to silence an optional beacon.
- [ ] Do not add `play.google.com` to CSP; blocked Google telemetry is not required by Kairos.
- [ ] Bypass Cloudflare HTML caching for Kairos or confirm the cache honors `Cache-Control: private, no-store`; a cached HTML response would reuse its nonce.
- [ ] Static JavaScript, CSS, images, and the vendored Socket.IO client may retain their existing cache policy.
- [ ] Ensure WebSocket proxying is enabled.
- [ ] Purge cached `/signoff/*` HTML, JavaScript, CSS, and `.htaccess`-affected responses after file deployment.

All critical scripts also carry `data-cfasync="false"` as a repository-side defense, but the route rule remains required for deterministic behavior.

## Apache and cPanel

- [ ] Confirm `mod_headers`, `mod_rewrite`, `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel` are enabled as required by the hosting layout.
- [ ] Confirm the effective document root uses the deployed `.htaccess` policy.
- [ ] Apply the HTML routing rules documented in `docs/runbooks/csp_nonce_apache_routing.md`.
- [ ] Confirm known page routes such as `/signoff/course`, `/signoff/course.html`, and `/signoff/` execute `public/html.php` instead of serving template files directly.
- [ ] Confirm the HTML rewrite rules remain after API/realtime proxy rules and before the generic `public/` fallback.
- [ ] Confirm `/signoff/templates/pages/index.html` returns 403/404 when the repository root is web-accessible.
- [ ] Confirm `/signoff/api/config.php`, `/signoff/assets/`, and `/signoff/websocket/socket.io/` retain their existing handlers and do not route through `html.php`.
- [ ] Confirm `/signoff/assets/vendor/socket.io/4.7.5/socket.io.min.js` returns HTTP 200 and a JavaScript content type.
- [ ] Confirm `/signoff/vendor/autoload.php`, `/signoff/composer.json`, and `/signoff/composer.lock` return 403/404.
- [ ] Confirm `/signoff/websocket/socket.io/?EIO=4&transport=polling` reaches the Python service.
- [ ] Check that cPanel did not omit hidden `.htaccess` files during upload.

## Realtime Service

- [ ] Install the pinned Python dependencies from `requirements.txt` in the production virtual environment.
- [ ] Run:

```bash
WS_SHARED_SECRET=<existing-secret> LMS_OUTBOX_ENABLED=0 python -c "import ws_server; print('import ok')"
```

- [ ] Restart the cPanel/Passenger/systemd process serving `ws_server.py`.
- [ ] Verify logs show successful connections without tokens, query strings, or sensitive payloads.
- [ ] Verify a user assigned to course A cannot subscribe to course B.
- [ ] Verify room subscriptions reject a mismatched `course_id`/`room_id`.
- [ ] Verify LMS outbox delivery marks events delivered only after scoped emission.

## Header and Asset Smoke Tests

```bash
curl -sSI https://kairos.nixorcorporate.com/signoff/ \
  | tr -d '\r' \
  | grep -iE 'content-security-policy|cross-origin-opener-policy|x-content-type-options'

curl -sS -D /tmp/kairos-csp-1.headers -o /tmp/kairos-csp-1.html \
  https://kairos.nixorcorporate.com/signoff/
curl -sS -D /tmp/kairos-csp-2.headers -o /tmp/kairos-csp-2.html \
  https://kairos.nixorcorporate.com/signoff/
grep -oi "'nonce-[^']*'" /tmp/kairos-csp-1.headers /tmp/kairos-csp-2.headers
grep -o 'nonce="[^"]*"' /tmp/kairos-csp-1.html /tmp/kairos-csp-2.html

curl -sSI https://kairos.nixorcorporate.com/signoff/assets/vendor/socket.io/4.7.5/socket.io.min.js

curl -sS "https://kairos.nixorcorporate.com/signoff/websocket/socket.io/?EIO=4&transport=polling"
```

The effective CSP must:

- allow `https://accounts.google.com` in `style-src-elem`;
- allow `wss://kairos.nixorcorporate.com` in `connect-src`;
- retain `object-src 'none'`, `base-uri 'self'`, and `script-src-attr 'none'`;
- contain the same fresh nonce in `script-src`, `script-src-elem`, and rendered inline theme scripts;
- produce different nonce values for the two separate HTML requests above;
- omit `'unsafe-inline'` from script directives;
- omit the Socket.IO CDN, Cloudflare Insights, and `play.google.com`.

## Browser Smoke Tests

- [ ] Hard-refresh the login page with DevTools open and cache disabled.
- [ ] Confirm no CSP violation for Socket.IO or Google Sign-In styles.
- [ ] Confirm Cloudflare JavaScript Detections executes without an inline-script CSP violation.
- [ ] Confirm Cloudflare copied the response-header nonce onto any injected inline detection script.
- [ ] Confirm `typeof window.io === "function"`.
- [ ] Confirm the Google button is styled and interactive.
- [ ] Confirm no `ReferenceError: io is not defined`.
- [ ] Confirm no Rocket Loader frames appear in application initialization failures.
- [ ] Confirm the Network panel opens a successful WebSocket connection at `/websocket/socket.io`.
- [ ] Temporarily stop the realtime service: verify retries stop at eight and the unavailable status appears.
- [ ] Restart the service or restore connectivity: verify the client reconnects.
- [ ] Test `390x844`, `768x1024`, `1440x900`, and a large desktop viewport for horizontal overflow.
- [ ] Check Default Dark, Light, Midnight, Graphite, Indigo, and Emerald themes.
- [ ] In Light Mode, verify muted text, placeholders, disabled controls, links, focus rings, borders, status badges, the top bar, and announcement bell remain legible.
- [ ] Keyboard-test navigation, appearance controls, forms, and dialogs; verify visible focus, Escape close, focus trap, and focus return.
- [ ] Open assignment and quiz create/edit dialogs in Light and Default Dark; verify section hierarchy, inline
      validation, disabled save state, focus return, and no mobile overflow.

## Role and Authorization Smoke Tests

Use non-production test accounts and do not alter real grades/submissions:

- [ ] Student: can read protected content only in enrolled courses; direct foreign course/queue requests return 403.
- [ ] Student: can preview an active public course before enrollment and sees an enrol CTA instead of a generic 403.
- [ ] TA/Manager: can preview and enroll in a public foreign course without receiving staff controls there.
- [ ] Self-enrollment creates only `student_courses`; no TA/manager/admin mapping is added.
- [ ] Public preview cannot read modules/assignments/quizzes/announcements, rooms/queues, or subscribe to the course realtime room.
- [ ] Downgraded former admin receives current DB role capabilities on the next request and has no stale admin controls.
- [ ] TA: can access assigned courses/rooms/queues only.
- [ ] Manager: can manage assigned courses only; another manager's course returns 403.
- [ ] Admin: retains intended global access.
- [ ] Student and TA: unpublished modules, lessons, resources, quizzes, assignments, and announcements remain hidden.
- [ ] Student: only released grades/feedback are returned.
- [ ] TA: grading requires assignment assignment and progress requires target enrollment in the same course.
- [ ] Manager: create, edit, publish/unpublish, and soft-delete an announcement in an assigned course.
- [ ] Student/TA: announcement create/update/delete endpoints return 403.
- [ ] Manager: foreign `announcement_id`, `submission_id`, `assignment_id`, and module IDs return 403/404.
- [ ] Course breadcrumb returns to `course.html?course_id=<id>` from every nested course page.
- [ ] Course navigation ordering and profile identity remain stable across direct loads and page transitions.
- [ ] Manager/admin see Grading and Analytics on every managed course surface; TA sees only capability-approved
      Grading; student sees neither. Compare desktop and mobile drawer output.
- [ ] Module accordions work with click, Enter, Space, and narrow touch input; item links remain tappable beside edit controls.
- [ ] Manager reorders module items by drag and by mobile up/down controls; rapid duplicate input produces one save and no HTTP 500.
- [ ] Two manager sessions reorder the same module; the stale session receives `409 reorder_conflict`, refreshes, and does not overwrite the committed order.
- [ ] Student/TA reorder requests return 403 and foreign/missing/duplicate item IDs return 403/404/422.
- [ ] Announcement bell keeps seen entries, distinguishes unread entries, and opens full detail; deleted/unpublished stale entries show “Announcement unavailable.”
- [ ] Student/TA cannot open draft announcement detail; manager/admin can view change-history summaries.
- [ ] Test YouTube watch/shorts, Vimeo, Google Docs/Slides/Drive, Office, and managed PDF previews; verify lazy loading, meaningful titles, and original-resource fallbacks.
- [ ] Confirm no iframe warning combines `allow-scripts` with `allow-same-origin`; do not treat blocked optional provider telemetry as an application failure.
- [ ] Settings cog is centered in all six themes and required viewports.
- [ ] Queue participant and ETA endpoints reject a queue outside the user's courses.
- [ ] WebSocket subscription to an unauthorized course is rejected.
- [ ] LMS resource upload rejects student/TA roles.
- [ ] With Drive writes disabled, file upload/delete returns sanitized `503`; existing managed downloads still work.
- [ ] Enable writes and upload a PDF course resource; confirm no raw Drive ID/link appears in the API response.
- [ ] Confirm the uploaded resource appears under `resources/course-<id>/` with an opaque filename.
- [ ] Enrolled student can preview/download the published resource; foreign-course student receives 403.
- [ ] Student file submission persists under `submissions/course-<id>/assignment-<id>/user-<id>/`.
- [ ] Assignment cards show readable excerpts without raw HTML; detail shows sanitized formatting and strips scripts,
      event handlers, unsafe links, and iframes.
- [ ] Assignment upload presets and safe custom extensions persist and rehydrate; the student file input `accept`
      attribute matches the resolved policy.
- [ ] With `GOOGLE_DRIVE_WRITES_ENABLED=false`, edit only assignment upload types/max size; confirm save and re-open
      succeed without a storage error.
- [ ] Disallowed extension, MIME mismatch, oversized file, and SVG each return sanitized `422`; confirm no submission
      row and no Drive file are created.
- [ ] Manager removes an assignment and quiz from a module; confirm each remains in its standalone library and the
      action text says “Remove from module.”
- [ ] Manager deletes an assignment and quiz from their detail pages; confirm Modules, standalone lists, direct
      links, student pages, and active grading views no longer expose them.
- [ ] Confirm historical submission/grade and attempt/grade rows remain in the database after parent soft deletion.
- [ ] Student, TA, and foreign-course manager delete/remove API calls return 403/404.
- [ ] A second browser session receives the course-scoped delete event and refreshes from REST.
- [ ] Quiz create/edit and question create/edit show polished validation and no raw JSON/debug text.
- [ ] Submitting student, assigned TA, course manager, and admin can download; another student/unassigned TA receives 403.
- [ ] Delete a test resource and confirm the DB record is hidden before the Drive file moves to trash.
- [ ] Text assignment submission and link-based resources remain available if Drive writes are disabled.
- [ ] Grade/release actions remain audited and role-gated.
- [ ] Internal course links remain shareable URLs; modifier-click, direct refresh, Back, and Forward use native browser behavior.
- [ ] Supported browsers prefetch likely same-origin course links; unsupported browsers navigate normally.
- [ ] Navigation does not create duplicate WebSocket connections or use WebSockets as a page transport.

## Cache and Monitoring

- [ ] Purge Cloudflare cache after deployment.
- [ ] Clear any cPanel/server page cache for `/signoff/*`.
- [ ] Monitor PHP error logs, Python service logs, CSP reports if configured, and HTTP 403/503 rates for at least one academic session.
- [ ] Alert on elevated `storage_unavailable`, `storage_cleanup_pending`, and `storage_integrity_error` responses.

## Rollback

1. Restore the pre-deployment file backup, including both `.htaccess` files, `public/` entrypoints, and the prior HTML files.
2. Restore the previous `ws_server.py` artifact and restart the Python service.
3. Revert the Cloudflare route rules only if required by the previous release.
4. Purge `/signoff/*` from Cloudflare and server caches.
5. Re-run login, CSP, API, and WebSocket smoke checks.

If application rollback is required, restore the prior files first. Retain `lms_announcement_audit` unless an approved data-retention decision permits the manual rollback documented in the migration. For a Drive incident, set
`GOOGLE_DRIVE_WRITES_ENABLED=false` first so authenticated reads remain available; use
`GOOGLE_DRIVE_ENABLED=false` only when credentials must be fully disabled or revoked.

If application rollback is required, leave the assignment restriction columns and their data in place. Drop them
only after the prior application is restored and data-loss approval is recorded; the migration contains the manual
rollback statement. Soft-deleted quiz/assignment records must not be mass-restored as part of application rollback.

## Shared Identity Shell Validation (June 14, 2026 Pass)

- [ ] Confirm `window.KairosIdentity` is exposed and caches the resolved session object successfully in memory.
- [ ] Verify profile picture/avatar, initials fallback, display name, and role label render correctly on index, settings, admin, manager, and TA pages.
- [ ] Confirm no layout jumps occur when loading the avatar, and the initials fallback works when an image fails to load.
- [ ] Verify that the `.is-loading` skeleton shimmers appear briefly on boot and disappear when the session is successfully resolved.
- [ ] Confirm that settings/admin pages do not erase the user profile card during bootstrap/role checks.
- [ ] Confirm that a 401 response from the session endpoints redirects to `/signoff/` safely while preserving query/hash params in sessionStorage.
- [ ] Verify theme contrast and readability of the user profile card across all six themes.
