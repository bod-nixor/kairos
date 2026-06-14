# Local Authentication Operations

## Deployment Order

1. Back up the database and application files.
2. Apply `db/migrations/20260614_2100_add_local_authentication.sql`.
3. Confirm PHP reports `PASSWORD_ARGON2ID` support.
4. Configure a working PHP `mail()` transport and sender address.
5. Set a random `AUTH_PRIVACY_HASH_SECRET` of at least 32 characters.
6. Deploy the auth services, endpoints, templates, JavaScript, CSS, and documentation.
7. Keep `LOCAL_AUTH_ENABLED=false` while running Google OAuth and existing-session smoke tests.
8. Test mail delivery, activation, reset, password login, Settings password change, and Google linking in staging.
9. Set `LOCAL_AUTH_ENABLED=true`, purge HTML/asset caches, and repeat the auth smoke plan.

Do not place activation/reset tokens in tickets, chat, logs, screenshots, or database notes.

## Pending Activation

To recover a user who did not receive an activation email:

1. Open Admin > Invite local account.
2. Find the user under Pending accounts.
3. Select **Resend activation**.
4. Confirm the UI reports successful mail delivery.
5. If delivery fails, verify `MAIL_FROM_ADDRESS`, the hosting account's mail routing, spam controls, and PHP mail logs.

Resending revokes previous unused activation tokens. Administrators must not set a password on the user's behalf.

## Temporary Lockout

Password failures can set `users.locked_until`. First determine whether the activity is expected:

```sql
SELECT user_id, username, email, account_status, failed_login_count, locked_until
FROM users
WHERE LOWER(username) = LOWER('<username>')
   OR LOWER(email) = LOWER('<email>');
```

For a confirmed legitimate user, an authorized operator may clear only the temporary counters:

```sql
UPDATE users
SET failed_login_count = 0,
    locked_until = NULL
WHERE user_id = <reviewed_user_id>;
```

Record the reason and operator in the incident/change record. Do not change `password_hash`, issue a known password,
or enable a disabled account without the normal approval path.

## Disabled Account

`disabled` accounts cannot log in by password or Google and existing sessions fail on refresh. Re-enabling is an
administrative identity decision, not a password-reset workaround. Verify the requested role and course mappings
before changing both compatibility fields:

```sql
UPDATE users
SET account_status = 'active',
    is_active = 1,
    failed_login_count = 0,
    locked_until = NULL
WHERE user_id = <reviewed_user_id>;
```

## Password Reset Incident

- Reset requests return a generic response, including for unknown users.
- A successful reset increments `auth_session_version` and revokes other reset tokens.
- If malicious reset mail is reported, review hashed audit events and delivery logs; do not request the raw token.
- If account takeover is suspected, set `account_status='disabled'`, `is_active=0`, increment
  `auth_session_version`, and follow the institutional incident process.

## Mail Failure

Admin creation commits a visible `pending_activation` account before sending mail. If mail fails, the API returns
`activation_email_failed`; the account is not silently active and remains available for a safe resend. Password reset
keeps its response generic even when delivery fails to prevent account enumeration.

## Rollback

1. Set `LOCAL_AUTH_ENABLED=false` first. Google login remains the public authentication path.
2. Restore the previous application files if necessary.
3. Do not drop auth tables or user columns during an incident rollback.
4. Preserve `auth_audit_log` according to institutional retention requirements.
5. Do not restore `users.google_id` to `NOT NULL` while local-only users exist.
6. After rollback, verify Google login, existing sessions, role checks, and `/signoff/` return URLs.

The migration contains destructive rollback statements only as reviewed manual notes. They must not be executed
without application rollback, local-account disposition, and data-loss approval.
