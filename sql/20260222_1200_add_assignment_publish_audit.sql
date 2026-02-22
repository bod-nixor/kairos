-- Forward migration
CREATE TABLE IF NOT EXISTS lms_assignment_publish_audit (
  assignment_publish_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  old_status ENUM('draft','published','archived') NOT NULL,
  new_status ENUM('draft','published','archived') NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (assignment_publish_audit_id),
  KEY idx_lms_assignment_publish_audit_assignment (assignment_id, created_at),
  KEY idx_lms_assignment_publish_audit_course (course_id, created_at),
  CONSTRAINT fk_lms_assignment_publish_audit_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_assignment_publish_audit_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_assignment_publish_audit_actor FOREIGN KEY (actor_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback migration
-- DROP TABLE IF EXISTS lms_assignment_publish_audit;
