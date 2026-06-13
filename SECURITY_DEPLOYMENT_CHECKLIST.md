# Kairos Security Deployment Checklist

Use this checklist before deploying Kairos to production or staging. Do not run intrusive scans against the live service.

## Required Environment Variables

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_ORIGIN=https://kairos.nixorcorporate.com`
- `PUBLIC_APP_ORIGIN=https://kairos.nixorcorporate.com`
- `ALLOWED_DOMAIN=nixorcollege.edu.pk`
- `GOOGLE_CLIENT_ID=<production Google OAuth client id>`
- `SESSION_COOKIE_NAME=kairos_session`
- `SESSION_COOKIE_PATH=/`
- `SESSION_COOKIE_SAMESITE=Lax`
- `SESSION_COOKIE_SECURE=true`
- `TRUST_PROXY_HEADERS=false` unless the reverse proxy is explicitly trusted
- `CORS_ALLOWED_ORIGINS=` or exact comma-separated trusted origins only
- `CSRF_REQUIRE_ORIGIN=false` initially; set `true` only after confirming all same-origin write requests include Origin/Referer
- `API_MAX_BODY_BYTES=1048576`
- `UPLOAD_MAX_BYTES=26214400`
- `LMS_RESOURCE_UPLOAD_MAX_BYTES=26214400`
- `LMS_UPLOAD_MAX_BYTES=10485760`
- `AUTH_RATE_LIMIT_ATTEMPTS=12`
- `AUTH_RATE_LIMIT_WINDOW_SECONDS=300`
- `ENABLE_ADMIN_DIAGNOSTICS=false`
- `KAIROS_DEBUG=false`
- `WS_SHARED_SECRET=<32 bytes or stronger random secret>`
- `WS_TOKEN_TTL=600`
- `WS_PUBLIC_URL=wss://kairos.nixorcorporate.com`
- `WS_SOCKET_PATH=/websocket/socket.io/`
- `WS_ALLOWED_ORIGINS=https://kairos.nixorcorporate.com`
- `WS_EMIT_MAX_BYTES=65536`
- `WS_LOG_DEBUG=false`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET=utf8mb4`

## Secret Rotation

- Generate a new `WS_SHARED_SECRET` before deployment.
- Rotate the DB password if a sample, shared, or previously exposed password was ever used.
- Confirm Google OAuth client secrets are not stored in the repository or public webroot.
- Rotate Drive service-account credentials before enabling real Drive integration if they were ever copied into files or tickets.
- After rotation, restart PHP/FPM or the cPanel app process and the WebSocket relay so all processes load the new values.

## Debug and Diagnostic Checks

- Confirm `APP_DEBUG=false`.
- Confirm `KAIROS_DEBUG=false`.
- Confirm `ENABLE_ADMIN_DIAGNOSTICS=false`.
- Confirm no `public/logs`, `logs`, `*.log`, `*.sql`, `*.bak`, or archive files are reachable under `/signoff/`.
- Confirm user-facing 500 responses are generic and server logs carry only sanitized diagnostics.

## Public Webroot Requirements

- Preferred webroot: `public/`.
- If the cPanel webroot must be the repository root, keep the root `.htaccess` active.
- Verify `.git`, `.env`, `config/`, `docs/`, `sql/`, `src/`, `templates/`, `tools/`, `storage/`, markdown docs, lock/config files, backups, and logs return `403` or `404`.
- Keep uploaded/private files outside public webroot.
- Disable directory listing.

## Apache, cPanel, and Reverse Proxy

- Required Apache modules: `mod_rewrite`, `mod_headers`; `mod_proxy`/WebSocket proxy support if using the included `/ws` and `/emit` reverse proxy rules.
- Verify `/signoff/api/*` routes to `public/api/*`.
- Verify known HTML pages route through `public/html.php` and never serve `templates/pages/*` directly.
- Verify `/signoff/ws` upgrades to the local relay.
- Verify `/signoff/emit` only reaches the local relay and is not publicly useful without `WS_SHARED_SECRET`.
- Do not enable `TRUST_PROXY_HEADERS=true` unless the app is behind a trusted proxy that strips spoofed `X-Forwarded-*` headers.

## HTTPS and Security Headers

- Enforce HTTPS at cPanel/proxy before production launch.
- Verify representative HTML responses include:
  - `Content-Security-Policy`
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy`
- Verify two separate HTML requests have different CSP nonces, with each response's nonce matching its rendered inline theme script.
- Verify `script-src` and `script-src-elem` contain the nonce and `script-src-attr 'none'` remains enforced.
- Verify API JSON responses include no-store cache headers and a restrictive JSON CSP.
- Enable HSTS only after HTTPS is stable for all required hosts. Start without `preload` and without `includeSubDomains` unless approved.

## CORS and CSRF

- Keep `CORS_ALLOWED_ORIGINS` empty unless a specific staging or production origin needs browser access.
- Never use wildcard CORS with credentials.
- Verify cross-origin POST requests are rejected with `403`.
- Verify same-origin POST requests still work.
- Plan a later frontend update to send a per-session CSRF token on every write request; current defense relies on SameSite, Origin/Referer checks, content-type checks, and exact CORS allowlists.

## Database Least Privilege

- Use a dedicated DB user for Kairos.
- Grant only required privileges on the Kairos schema.
- Do not use root/admin DB credentials in production.
- Confirm migrations are applied manually and reviewed before deploy.
- Confirm `users.is_active`, `users.role_id`, and `roles.name` exist and are indexed appropriately for refreshed-session checks.

## Upload Directory and File Handling

- Keep upload storage outside public webroot or behind an authorization-aware handler.
- Keep Drive permissions aligned with Kairos enrollment/RBAC.
- Do not return raw Drive links that bypass Kairos policy.
- Enforce upload size limits at PHP/cPanel too:
  - `upload_max_filesize`
  - `post_max_size`
  - `max_file_uploads`
- Consider antivirus/content-disarm tooling where hosting supports it.

## Logging and Monitoring

- Send PHP and WebSocket logs to protected storage outside public webroot.
- Monitor:
  - Auth failures and rate-limit triggers
  - Authorization failures
  - Admin/manager role changes
  - Grade/submission status changes
  - Upload rejections
  - WebSocket emit failures
- Never log passwords, full tokens, raw session IDs, private keys, or OAuth credentials.

## Backups

- Store backups outside the served tree.
- Block direct web access to dumps and archives.
- Encrypt backups at rest where hosting supports it.
- Test restore in staging before production launch.

## Safe Verification Commands

Run these against staging or production only after authorization and during a maintenance window where appropriate:

```bash
curl -I https://kairos.nixorcorporate.com/signoff/
curl -I https://kairos.nixorcorporate.com/signoff/.env
curl -I https://kairos.nixorcorporate.com/signoff/sql/initialize_schema.sql
curl -I https://kairos.nixorcorporate.com/signoff/docs/
curl -I https://kairos.nixorcorporate.com/signoff/api/config.php
curl -i -X POST https://kairos.nixorcorporate.com/signoff/api/logout.php \
  -H 'Origin: https://evil.example.test'
```

Expected:

- Sensitive files/directories return `403` or `404`.
- HTML responses include the configured security headers.
- API responses are JSON, no-store, and do not expose stack traces.
- Cross-origin state-changing requests are rejected.

## Rollback Considerations

- If Apache rules block required assets, temporarily roll back only the relevant `.htaccess` rule and document the exception.
- If secure cookies break behind a proxy, confirm TLS/proxy headers first; do not disable `SESSION_COOKIE_SECURE` in production without a documented hosting reason.
- If CSP blocks a required third-party viewer, add the narrow host to the relevant directive and keep `object-src 'none'`.
- If WebSocket emit fails after deployment, confirm `ws_emit.py` and the relay both use `X-Kairos-WS-Secret`, then rotate the shared secret if it was exposed.

## Hosting-Provider or Infrastructure Items

- TLS certificate installation and renewal.
- Optional HSTS activation.
- Protected log storage and rotation.
- DB user provisioning and backups.
- WebSocket reverse proxy support.
- Drive service-account secret storage.
- Malware scanning or document-disarm service for uploads.
