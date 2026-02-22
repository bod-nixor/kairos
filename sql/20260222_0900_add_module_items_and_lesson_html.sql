-- Forward migration
CREATE TABLE IF NOT EXISTS lms_module_items (
  module_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  item_type ENUM('lesson','file','video','link','assignment','quiz') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  required_flag TINYINT(1) NOT NULL DEFAULT 0,
  published_flag TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (module_item_id),
  KEY idx_lms_module_items_section (section_id, position),
  KEY idx_lms_module_items_course (course_id, item_type),
  CONSTRAINT fk_lms_module_items_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_module_items_section FOREIGN KEY (section_id) REFERENCES lms_course_sections (section_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_module_items_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE lms_lessons
  ADD COLUMN html_content MEDIUMTEXT NULL AFTER summary;

-- Backfill from summary (idempotent)
UPDATE lms_lessons
SET html_content = CONCAT('<p>', REPLACE(COALESCE(summary,''), '<', '&lt;'), '</p>')
WHERE html_content IS NULL AND summary IS NOT NULL AND summary <> '';

-- Rollback migration
-- ALTER TABLE lms_lessons DROP COLUMN html_content;
-- DROP TABLE IF EXISTS lms_module_items;
