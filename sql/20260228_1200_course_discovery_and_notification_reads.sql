-- Add course visibility/discovery and persistent notification read tracking

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS code VARCHAR(32) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS visibility ENUM('public','restricted') NOT NULL DEFAULT 'public' AFTER code;

CREATE TABLE IF NOT EXISTS course_allowlist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_course_allowlist_course_email (course_id, email),
  KEY idx_course_allowlist_email (email),
  CONSTRAINT fk_course_allowlist_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_pre_enroll (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_course_pre_enroll_course_email (course_id, email),
  KEY idx_course_pre_enroll_email (email),
  CONSTRAINT fk_course_pre_enroll_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_notification_reads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  event_id VARCHAR(128) NOT NULL,
  seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lms_notification_reads_user_course_event (user_id, course_id, event_id),
  KEY idx_lms_notification_reads_user_course_seen (user_id, course_id, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback (manual):
-- DROP TABLE IF EXISTS lms_notification_reads;
-- DROP TABLE IF EXISTS course_pre_enroll;
-- DROP TABLE IF EXISTS course_allowlist;
-- ALTER TABLE courses DROP COLUMN visibility;
