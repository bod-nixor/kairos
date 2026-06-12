# Kairos Production Deployment Checklist

## Change Window

- [ ] Schedule a controlled deployment window.
- [ ] Back up the current `/signoff/` files and effective Apache configuration.
- [ ] Take a database backup even though this change has no migration.
- [ ] Record the current Python realtime process command and rollback artifact.
- [ ] Confirm no unrelated working-tree files are included in the deployment package.

## Files to Deploy

Deploy the complete repository change set, including:

- `.htaccess` and `public/.htaccess`
- all changed `public/*.html` entry points
- `public/css/kairos-ui.css`
- `public/js/ws.js`, `public/js/lms-ws.js`, and `public/js/lms-core.js`
- `public/vendor/socket.io/4.7.5/socket.io.min.js` and `LICENSE`
- changed PHP API and RBAC consumers under `public/api/`
- `composer.json` and `composer.lock`
- `public/api/lms/integrations/drive/` and `public/api/lms/resources/download.php`
- `ws_server.py`
- updated runbooks and test files

No SQL migration is required.

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
- [ ] If deploying to a non-production hostname, update the exact HTTPS/WSS origins in both `.htaccess` files before that deployment.

## Cloudflare

- [ ] Create a configuration rule for `/signoff/*` that disables Rocket Loader.
- [ ] Disable Browser Insights/Web Analytics script injection for `/signoff/*`. If route-level exclusion is unavailable, disable it for the zone/application.
- [ ] Do not add `static.cloudflareinsights.com` to Kairos CSP merely to silence an optional beacon.
- [ ] Do not add `play.google.com` to CSP; blocked Google telemetry is not required by Kairos.
- [ ] Ensure WebSocket proxying is enabled.
- [ ] Purge cached `/signoff/*` HTML, JavaScript, CSS, and `.htaccess`-affected responses after file deployment.

All critical scripts also carry `data-cfasync="false"` as a repository-side defense, but the route rule remains required for deterministic behavior.

## Apache and cPanel

- [ ] Confirm `mod_headers`, `mod_rewrite`, `mod_proxy`, `mod_proxy_http`, and `mod_proxy_wstunnel` are enabled as required by the hosting layout.
- [ ] Confirm the effective document root uses the deployed `.htaccess` policy.
- [ ] Confirm `/signoff/vendor/socket.io/4.7.5/socket.io.min.js` returns HTTP 200 and a JavaScript content type.
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

curl -sSI https://kairos.nixorcorporate.com/signoff/vendor/socket.io/4.7.5/socket.io.min.js

curl -sS "https://kairos.nixorcorporate.com/signoff/websocket/socket.io/?EIO=4&transport=polling"
```

The effective CSP must:

- allow `https://accounts.google.com` in `style-src-elem`;
- allow `wss://kairos.nixorcorporate.com` in `connect-src`;
- retain `object-src 'none'`, `base-uri 'self'`, and `script-src-attr 'none'`;
- omit the Socket.IO CDN, Cloudflare Insights, and `play.google.com`.

## Browser Smoke Tests

- [ ] Hard-refresh the login page with DevTools open and cache disabled.
- [ ] Confirm no CSP violation for Socket.IO or Google Sign-In styles.
- [ ] Confirm `typeof window.io === "function"`.
- [ ] Confirm the Google button is styled and interactive.
- [ ] Confirm no `ReferenceError: io is not defined`.
- [ ] Confirm no Rocket Loader frames appear in application initialization failures.
- [ ] Confirm the Network panel opens a successful WebSocket connection at `/websocket/socket.io`.
- [ ] Temporarily stop the realtime service: verify retries stop at eight and the unavailable status appears.
- [ ] Restart the service or restore connectivity: verify the client reconnects.
- [ ] Test `390x844`, `768x1024`, `1440x900`, and a large desktop viewport for horizontal overflow.
- [ ] Check Default Dark, Light, Midnight, Graphite, Indigo, and Emerald themes.
- [ ] Keyboard-test navigation, appearance controls, forms, and dialogs; verify visible focus, Escape close, focus trap, and focus return.

## Role and Authorization Smoke Tests

Use non-production test accounts and do not alter real grades/submissions:

- [ ] Student: can read enrolled courses only; direct foreign course/queue requests return 403.
- [ ] TA: can access assigned courses/rooms/queues only.
- [ ] Manager: can manage assigned courses only; another manager's course returns 403.
- [ ] Admin: retains intended global access.
- [ ] Queue participant and ETA endpoints reject a queue outside the user's courses.
- [ ] WebSocket subscription to an unauthorized course is rejected.
- [ ] LMS resource upload rejects student/TA roles.
- [ ] With Drive writes disabled, file upload/delete returns sanitized `503`; existing managed downloads still work.
- [ ] Enable writes and upload a PDF course resource; confirm no raw Drive ID/link appears in the API response.
- [ ] Confirm the uploaded resource appears under `resources/course-<id>/` with an opaque filename.
- [ ] Enrolled student can preview/download the published resource; foreign-course student receives 403.
- [ ] Student file submission persists under `submissions/course-<id>/assignment-<id>/user-<id>/`.
- [ ] Submitting student, assigned TA, course manager, and admin can download; another student/unassigned TA receives 403.
- [ ] Delete a test resource and confirm the DB record is hidden before the Drive file moves to trash.
- [ ] Text assignment submission and link-based resources remain available if Drive writes are disabled.
- [ ] Grade/release actions remain audited and role-gated.

## Cache and Monitoring

- [ ] Purge Cloudflare cache after deployment.
- [ ] Clear any cPanel/server page cache for `/signoff/*`.
- [ ] Monitor PHP error logs, Python service logs, CSP reports if configured, and HTTP 403/503 rates for at least one academic session.
- [ ] Alert on elevated `storage_unavailable`, `storage_cleanup_pending`, and `storage_integrity_error` responses.

## Rollback

1. Restore the pre-deployment file backup, including both `.htaccess` files.
2. Restore the previous `ws_server.py` artifact and restart the Python service.
3. Revert the Cloudflare route rules only if required by the previous release.
4. Purge `/signoff/*` from Cloudflare and server caches.
5. Re-run login, CSP, API, and WebSocket smoke checks.

There is no database rollback because this deployment contains no schema or data migration. For a Drive incident, set
`GOOGLE_DRIVE_WRITES_ENABLED=false` first so authenticated reads remain available; use
`GOOGLE_DRIVE_ENABLED=false` only when credentials must be fully disabled or revoked.
