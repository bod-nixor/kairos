-- Add announcement publication state and durable mutation audit records.
-- Forward migration (idempotent, MariaDB/MySQL compatible).

SET @schema_name := DATABASE();

SET @has_status := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_announcements'
    AND COLUMN_NAME = 'status'
);
SET @sql := IF(
  @has_status = 0,
  "ALTER TABLE lms_announcements ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'published' AFTER body",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_published_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_announcements'
    AND COLUMN_NAME = 'published_at'
);
SET @sql := IF(
  @has_published_at = 0,
  'ALTER TABLE lms_announcements ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_status_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_announcements'
    AND INDEX_NAME = 'idx_lms_announcements_course_status'
);
SET @sql := IF(
  @has_status_index = 0,
  'ALTER TABLE lms_announcements ADD KEY idx_lms_announcements_course_status (course_id, status, deleted_at, created_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE lms_announcements
SET status = 'published',
    published_at = COALESCE(published_at, created_at)
WHERE deleted_at IS NULL
  AND status = 'published'
  AND published_at IS NULL;

CREATE TABLE IF NOT EXISTS lms_announcement_audit (
  announcement_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  announcement_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  action ENUM('create','update','publish','unpublish','delete') NOT NULL,
  old_values_json JSON NULL,
  new_values_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_audit_id),
  KEY idx_lms_announcement_audit_announcement (announcement_id, created_at),
  KEY idx_lms_announcement_audit_course (course_id, created_at),
  CONSTRAINT fk_lms_announcement_audit_announcement
    FOREIGN KEY (announcement_id) REFERENCES lms_announcements (announcement_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_announcement_audit_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_announcement_audit_actor
    FOREIGN KEY (actor_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback (manual, only after code rollback and audit retention approval):
-- DROP TABLE IF EXISTS lms_announcement_audit;
-- ALTER TABLE lms_announcements
--   DROP INDEX idx_lms_announcements_course_status,
--   DROP COLUMN published_at,
--   DROP COLUMN status;
