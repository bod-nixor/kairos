-- Add admin-invited local authentication while preserving Google OAuth.
-- Apply before deploying the local-auth API/UI. This migration is additive
-- except for allowing users.google_id to be NULL for local-only accounts.

ALTER TABLE users
  MODIFY COLUMN google_id VARCHAR(255) NULL;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS username VARCHAR(64) NULL AFTER google_id,
  ADD COLUMN IF NOT EXISTS google_email VARCHAR(255) NULL AFTER email,
  ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER picture_url,
  ADD COLUMN IF NOT EXISTS account_status VARCHAR(32) NOT NULL DEFAULT 'active' AFTER is_active,
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER account_status,
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER password_changed_at,
  ADD COLUMN IF NOT EXISTS failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
  ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER failed_login_count,
  ADD COLUMN IF NOT EXISTS auth_session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER locked_until;

UPDATE users
SET google_email = email
WHERE google_id IS NOT NULL
  AND google_email IS NULL;

UPDATE users
SET account_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'disabled' END
WHERE account_status IS NULL
   OR account_status = '';

ALTER TABLE users
  ADD UNIQUE INDEX IF NOT EXISTS uk_users_username (username),
  ADD UNIQUE INDEX IF NOT EXISTS uk_users_google_email (google_email),
  ADD INDEX IF NOT EXISTS idx_users_account_status (account_status),
  ADD INDEX IF NOT EXISTS idx_users_locked_until (locked_until);

CREATE TABLE IF NOT EXISTS auth_tokens (
  token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_ip_hash CHAR(64) NULL,
  user_agent_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uk_auth_tokens_hash (token_hash),
  KEY idx_auth_tokens_user_purpose (user_id, purpose, expires_at),
  KEY idx_auth_tokens_expiry (expires_at),
  CONSTRAINT fk_auth_tokens_user
    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_audit_log (
  auth_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  identifier_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  user_agent_hash CHAR(64) NULL,
  status VARCHAR(24) NOT NULL,
  metadata_json JSON NULL,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (auth_audit_id),
  KEY idx_auth_audit_event_time (event_name, occurred_at),
  KEY idx_auth_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_auth_audit_subject_time (subject_user_id, occurred_at),
  CONSTRAINT fk_auth_audit_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE SET NULL,
  CONSTRAINT fk_auth_audit_subject
    FOREIGN KEY (subject_user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
  bucket_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (bucket_hash),
  KEY idx_auth_rate_limits_blocked_until (blocked_until),
  KEY idx_auth_rate_limits_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback (manual, only after application rollback and local-account review):
-- 1. Do not restore google_id to NOT NULL while any local-only users exist.
-- 2. Preserve auth_audit_log according to institutional retention policy.
-- 3. The following destructive statements require explicit data-loss approval:
-- DROP TABLE IF EXISTS auth_rate_limits;
-- DROP TABLE IF EXISTS auth_tokens;
-- DROP TABLE IF EXISTS auth_audit_log;
-- ALTER TABLE users
--   DROP INDEX IF EXISTS uk_users_username,
--   DROP INDEX IF EXISTS uk_users_google_email,
--   DROP INDEX IF EXISTS idx_users_account_status,
--   DROP INDEX IF EXISTS idx_users_locked_until,
--   DROP COLUMN IF EXISTS username,
--   DROP COLUMN IF EXISTS google_email,
--   DROP COLUMN IF EXISTS password_hash,
--   DROP COLUMN IF EXISTS account_status,
--   DROP COLUMN IF EXISTS password_changed_at,
--   DROP COLUMN IF EXISTS last_login_at,
--   DROP COLUMN IF EXISTS failed_login_count,
--   DROP COLUMN IF EXISTS locked_until,
--   DROP COLUMN IF EXISTS auth_session_version;
