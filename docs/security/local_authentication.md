# Local Authentication Security Model

**Effective date:** June 14, 2026

## Registration and Login Methods

- Public self-registration remains Google-only and requires a verified account in `nixorcollege.edu.pk`.
- Password accounts can be created only by an authenticated administrator.
- Administrators provide identity, role, and optional course assignment data, but never a password.
- New local accounts remain `pending_activation` until the user follows a single-use email link and sets a password.
- An active local user may link one approved Nixor Google identity from Settings. Linking never occurs automatically by matching email.

## Password Storage

Kairos uses PHP `PASSWORD_ARGON2ID` with environment-controlled parameters:

- `ARGON2_MEMORY_COST`, minimum `19456` KiB
- `ARGON2_TIME_COST`, minimum `2`
- `ARGON2_THREADS`, minimum `1`
- `PASSWORD_MIN_LENGTH`, minimum `12`
- `PASSWORD_MAX_LENGTH`, default `1024`

Invalid or weaker configuration fails closed when local authentication is enabled. Successful login calls
`password_needs_rehash()` and upgrades older hashes. Kairos stores only the Argon2id result. No application-level
pepper is used because Kairos does not yet have a versioned pepper-rotation mechanism; adding an unversioned pepper
would make safe rotation and account recovery worse.

## Activation and Reset Tokens

- Tokens contain 256 bits from `random_bytes()`.
- Only SHA-256 token hashes are stored in `auth_tokens`.
- Activation and reset links put the raw token in the URL fragment, not the query string. Browsers do not send URL
  fragments to Apache or PHP, reducing token exposure in access logs.
- The client removes the fragment from browser history before calling the API.
- Tokens are purpose-bound, single-use, short-lived, and previous tokens are revoked when a replacement is issued.
- Activation defaults to 24 hours; password reset defaults to 1 hour; Google link state defaults to 10 minutes.
- Password-setting POSTs require a synchronizer CSRF token plus the existing exact-origin request policy.

## Abuse Protection

Password login is limited by two privacy-preserving buckets:

1. Client IP.
2. Normalized username/email identifier.

Bucket values, IPs, identifiers, and user agents are HMAC-SHA-256 hashed with `AUTH_PRIVACY_HASH_SECRET` before
storage. Repeated failures also update the user-level failure counter and temporary `locked_until` timestamp.
Responses for wrong passwords and unknown users are identical. Password reset requests always return the same
confirmation text.

## Sessions and CSRF

- Session IDs are regenerated after Google or password login.
- Cookies are `HttpOnly`, `SameSite`, and `Secure` according to the existing session configuration.
- New auth endpoints require `X-CSRF-Token`, issued from the current server-side session.
- Existing exact-origin and content-type checks remain active.
- `auth_session_version` is copied into the login session. Password reset/change increments the database version,
  invalidating other sessions on their next authenticated request.
- Return URLs are accepted only when they are relative paths beginning with `/signoff/`; external, protocol-relative,
  backslash, and encoded-backslash variants are rejected.

## Account States

- `pending_activation`: no password login; activation email can be reissued by an admin.
- `active`: normal login when at least one login method exists.
- `disabled`: all protected session refresh and login attempts fail.
- Temporary lockout uses `locked_until`; the optional `locked` status remains available for future administrative use.

`is_active` remains synchronized for compatibility with existing APIs. Server-side RBAC still derives authority from
the existing role and course mapping tables; auth method does not grant permissions.

## Google Linking

The Settings flow:

1. Requires an active authenticated session and CSRF token.
2. Creates a short-lived, hashed `google_link_state` token bound to the current user.
3. Verifies Google signature, issuer, audience, expiry, issued-at time, verified email, hosted domain, and email domain.
4. Rejects a Google subject already linked to another user.
5. Stores `google_id` and `google_email`, consumes the state token, and audits the result.

Unlinking is intentionally not implemented, so Kairos cannot accidentally remove a user's last login method.

## Audit Events

`auth_audit_log` records login success/failure/throttle, account creation, activation token issuance/email delivery,
activation, reset request/token/email/completion, password change, and Google link start/success/failure. Raw
passwords, hashes, ID tokens, activation/reset tokens, email addresses, IP addresses, and user-agent strings are not
stored in audit metadata.
