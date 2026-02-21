-- Add immutable grade audit records for grading draft/release actions.
-- Forward migration
CREATE TABLE IF NOT EXISTS lms_grade_audit (
  grade_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  graded_by BIGINT UNSIGNED NOT NULL,
  score DECIMAL(8,2) NOT NULL,
  max_score DECIMAL(8,2) NOT NULL,
  feedback LONGTEXT DEFAULT NULL,
  action VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (grade_audit_id),
  KEY idx_lms_grade_audit_submission (submission_id, created_at),
  KEY idx_lms_grade_audit_graded_by (graded_by, created_at),
  CONSTRAINT fk_lms_grade_audit_submission FOREIGN KEY (submission_id) REFERENCES lms_submissions (submission_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_grade_audit_graded_by FOREIGN KEY (graded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback
-- DROP TABLE IF EXISTS lms_grade_audit;
