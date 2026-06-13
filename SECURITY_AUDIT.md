# Kairos Security Audit

## Executive Summary

This defensive repository review hardened Kairos for production deployment under `/signoff/` without contacting or probing the live service. The review focused on repository-level Critical and High issues that can be remediated in code or deployable configuration.

No real secret values were identified in tracked text during the redacted scan. Several high-impact weaknesses were found and remediated: repository internals could be directly served when the project root is the webroot, public fallback logs could expose operational details, Google OAuth/session handling returned unsafe errors and did not regenerate sessions, mutating API requests lacked central origin/content-type/body-size defenses, LMS resource uploads lacked robust validation, a schema diagnostic endpoint was production-reachable to admins, and the WebSocket relay accepted its shared secret in the URL.

Kairos is not claimed to be fully secure. The remaining risk is primarily deployment-dependent: TLS/HSTS, secret rotation, Drive permission reconciliation, database least privilege, external log handling, and independent review of every endpoint-level RBAC rule.

## Architecture and Attack Surface

Detected stack:

- Vanilla PHP API under `public/api/`
- Vanilla HTML/CSS/JavaScript frontend under `public/`
- Python Flask-SocketIO relay in `ws_server.py`
- MariaDB/MySQL via PDO and `pymysql`
- Manual SQL migrations under `db/migrations/`
- Apache/cPanel-style routing via root `.htaccess` and `public/.htaccess`
- Google OAuth ID-token login restricted to `nixorcollege.edu.pk`
- Drive integration currently represented by a stub boundary in `public/api/lms/drive_client.php`

Externally reachable surfaces:

- Static frontend pages: course, modules, lessons, resources, quizzes, assignments, grading, analytics, admin, manager, TA, room, projector
- Public config endpoint: `public/api/config.php`
- Auth/session endpoints: `auth.php`, `me.php`, `logout.php`, `session_capabilities.php`
- Student/TA/manager/admin API endpoints for queues, rooms, progress, courses, LMS resources, lessons, modules, quizzes, assignments, grading, analytics, notifications
- File upload endpoints for LMS resources and assignment submissions
- Server-sent events endpoint `changes.php`
- WebSocket relay connect path and `/emit` event injection endpoint
- Apache rewrite layer mapping clean `/signoff/api/*` paths to `public/api/*`

Security baseline:

- OWASP ASVS 5.0 Level 2 as the minimum baseline, with Level 3-style controls where practical for auth/session, admin diagnostics, file upload, and privileged operations.
- OWASP Top 10 and API Security Top 10 classes were considered during code review.

## Findings

| Severity | Category | Affected Location | Evidence | Remediation | Final Status |
| --- | --- | --- | --- | --- | --- |
| High | Webroot exposure | Root `.htaccess` | Real repository files and directories such as `README.md`, `db/migrations/`, `docs/`, `config/`, `src/`, and `tools/` could be served if the repo root is the cPanel document root. | Added deny rules for dotfiles, internals, docs, migrations, backups, logs, archives, and source/config paths while preserving `.well-known` and required `public/` routing. | Remediated |
| High | Sensitive logging | `public/api/queues.php`, `public/api/ta/common.php` | Queue debug logs and TA audit fallback logs were written under `public/logs`, with queue logs including SQL and trace material. | Removed public queue log writer, routed queue diagnostics through `kairos_debug_log` only when `KAIROS_DEBUG` is enabled, and moved TA fallback audit logs to `storage/logs`. Apache now blocks `logs/` and `storage/`. | Remediated |
| High | Authentication/session handling | `public/api/auth.php`, `public/api/logout.php`, `public/api/bootstrap.php` | Auth returned raw exception messages, did not enforce `email_verified` or email-domain consistency, did not validate `iat`, and did not regenerate the session after login. Logout did not clear the cookie and allowed non-POST logout. | Added stricter token shape/claim checks, verified email/domain checks, auth rate limiting, generic auth errors, session regeneration, strict session settings, active-user/role refresh on protected requests, and POST-only logout with cookie clearing. | Remediated |
| High | CSRF/browser request hardening | `public/api/bootstrap.php` | Cookie-authenticated write endpoints had no central cross-origin rejection or content-type/body-size policy. | Added same-origin/allowlisted-origin enforcement for state-changing methods, safe CORS handling, content-type allowlist, and JSON/upload request-size limits. | Remediated with residual token note |
| High | File upload validation | `public/api/lms/resources/upload.php`, `public/api/lms/drive_client.php` | LMS resource uploads trusted client MIME/name, lacked method check, broad access-scope input, and robust type/size validation. | Added POST enforcement, title length, access-scope allowlist, basename/safe filename handling, extension/MIME allowlists, detected MIME checks, and size limits. | Remediated |
| High | Admin diagnostic exposure | `public/api/admin/diag_lms_schema.php` | Admin-only diagnostic endpoint returned schema DDL and diagnostic rows in production. | Disabled unless `ENABLE_ADMIN_DIAGNOSTICS=true`. | Remediated |
| High | WebSocket secret leakage and error disclosure | `ws_emit.py`, `ws_server.py` | `/emit` accepted `secret` in the query string and relay 500 responses returned exception text. Socket.IO origin was hardcoded and verbose logging was enabled. | Emitter now sends `X-Kairos-WS-Secret`; relay validates the header only, uses configurable `WS_ALLOWED_ORIGINS`, limits emit body size, validates event names, disables verbose logging by default, and returns generic 500 JSON. | Remediated |
| Medium | Malformed JSON handling | `public/api/lms/_common.php`, direct API decodes | Several write paths converted malformed JSON to an empty object. | Added strict JSON readers and routed LMS/auth/queue/admin/TA/manager/progress writes through them. | Remediated |
| Medium | User-facing error leakage | Several API endpoints | Some catch blocks returned `$e->getMessage()` to clients; several logs included full traces. | Replaced high-risk client responses with stable generic errors and reduced trace-heavy logs to class-level diagnostics. | Remediated for reviewed paths |
| Medium | Security headers | `.htaccess`, `public/.htaccess`, `public/api/bootstrap.php` | Headers were limited and referrer policy was weak. No CSP was present. | Added `nosniff`, frame protection, stricter referrer policy, permissions policy, compatible CSP, API JSON CSP, and no-store headers. | Remediated with CSP compatibility exception |
| Medium | Secrets/config hygiene | `.env.example` | Example env used legacy `regatta_*` names and weak placeholder wording. | Updated safe Kairos placeholders, origin/CORS settings, session security, upload/body limits, WebSocket origin/size/debug flags, and Drive placeholders. | Remediated |
| Low | Dependency review | `requirements.txt` | No lockfile is present for Python dependencies; local dependency audit was not available in this environment. | Documented deployment requirement to pin/audit dependencies before release. | Manual follow-up |
| Informational | Password storage | Repository-wide | No local password login/storage flow was identified; authentication is Google OAuth. | No password hashing changes required. | Not applicable |
| Informational | AI-specific controls | Repository-wide | No LLM/tool-calling features were identified in Kairos. | No AI-specific code changes required. | Not applicable |

## Important Changes

- `public/api/bootstrap.php`
  - Strict session cookie/settings, secure defaults, same-site normalization, active user refresh, request security headers, CORS policy, Origin/Referer checks for state-changing requests, content-type allowlist, request-size limits, rate-limit helper, strict JSON reader.
- `public/api/auth.php`
  - Hardened Google ID-token validation, email verification/domain checks, auth rate limiting, session regeneration, generic auth failures.
- `public/api/logout.php`
  - POST-only logout, session clearing, cookie expiration.
- `.htaccess` and `public/.htaccess`
  - Sensitive-path denial, compatible CSP and security headers, directory listing disabled.
- `public/api/lms/drive_client.php` and `public/api/lms/resources/upload.php`
  - Upload allowlist validation and safer metadata handling.
- `ws_emit.py` and `ws_server.py`
  - Header-based shared-secret transport, configurable origins, event validation, body limits, generic errors.
- `public/api/admin/diag_lms_schema.php`
  - Disabled by default.
- `public/api/queues.php` and `public/api/ta/common.php`
  - Public log exposure removed.
- `.env.example`
  - Safe production-oriented placeholders and security configuration.

## Tests and Commands

Verified automatically:

- `python3 -m py_compile ws_server.py ws_emit.py` - passed.
- `git diff --check` - passed.
- `/home/shahzain/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3 -m pip check` - passed.
- Redacted tracked-text secret scan for common credential/token patterns - no real secrets identified; placeholders/config references only.
- Public-tree sensitive artifact scan for `.env`, backups, logs, SQL dumps, archives, and temp files - no matching files under `public/`.
- Raw `php://input` JSON decode scan under `public/api/` - no remaining direct decode matches.
- Client-visible exception leakage scan for reviewed patterns - no remaining matches.

Blocked locally:

- PHP syntax checks and PHP regression tests, including `tools/tests/security_controls_test.php`, could not be executed because no `php` binary is installed in the environment. A non-interactive `sudo apt-get install php-cli php-xml` attempt failed because `sudo` requires a password.

## Residual Risks

- Inline scripts are restricted to immutable SHA-256 hashes in both Apache CSP variants; updating inline script content requires updating the matching hashes.
- CSRF protection is currently enforced through SameSite cookies, exact Origin/Referer checks, content-type controls, and no CORS reflection. A per-session CSRF token should be added once all direct `fetch()` call sites can consistently send the token.
- WebSocket `/emit` still accepts the legacy `secret` query parameter for compatibility. Remove query-secret support after all deployments use `X-Kairos-WS-Secret`.
- Drive integration is currently a stub. Real Drive upload/download permission reconciliation must be implemented and verified before relying on Drive-backed files for sensitive submissions.
- Endpoint-level RBAC is improved through session refresh and existing helpers, but every protected read/write path still needs independent review with real role fixtures.
- Python dependency versions are not locked. Pin and audit dependencies before production.

## Manual Review Items

- Verify every manager/admin/TA endpoint has correct object-level authorization with production-like fixtures.
- Confirm `users.is_active`, `users.role_id`, and `roles.name` exist in the deployed schema before enabling the refreshed-session behavior.
- Confirm Apache `mod_headers`, `mod_rewrite`, and `mod_proxy` modules are available and the `.htaccess` rules are honored by cPanel.
- Confirm whether public document root is repository root or `public/`; both paths now have protections, but hosting should prefer `public/` when possible.
- Confirm CSP hash coverage after any inline bootstrap script change.

## Infrastructure-Dependent Controls

- TLS must be enforced at the hosting layer before enabling HSTS.
- HSTS should be enabled only after validating HTTPS on all required hosts. Do not enable preload until subdomain implications are approved.
- Database credentials must be least-privilege and limited to the Kairos schema.
- Logs should be shipped to a protected location outside the public webroot.
- Backup/dump/archive files must never be placed under the served tree.
- Uploaded file storage must remain outside public webroot or be served only through authorization-aware handlers.
- Drive service-account credentials, if used, must be stored outside source and rotated through the hosting secret boundary.

## Secrets and Rotation

No real secret values were identified in tracked text during the redacted scan. Placeholders were found in `.env.example` and README examples only.

Manual rotation still required before production:

- `WS_SHARED_SECRET`: rotate if any deployed instance used an old placeholder or URL-query secret.
- Database password: rotate if deployed from a sample value or shared outside the hosting secret store.
- Google OAuth client secret, if configured outside this repository: rotate only if it was ever exposed in hosting config or previous commits.
- Drive service-account credential, if configured outside this repository: rotate before enabling real Drive integration.

## Assumptions

- This review was limited to the local repository and did not test the live production service.
- The application remains deployed under `/signoff/`.
- Google OAuth remains the only authentication mechanism.
- cPanel/Apache hosting honors `.htaccess` rules.
- MySQL/MariaDB remains the canonical business state.
