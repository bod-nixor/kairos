-- Persist per-user LMS UI settings (theme + compact/reduce-motion preferences).
CREATE TABLE IF NOT EXISTS lms_user_ui_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  theme ENUM('light','dark') NULL,
  gradient_theme VARCHAR(32) NOT NULL DEFAULT 'ocean',
  compact_mode TINYINT(1) NOT NULL DEFAULT 0,
  reduce_motion TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_lms_user_ui_settings_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (manual):
-- DROP TABLE IF EXISTS lms_user_ui_settings;
