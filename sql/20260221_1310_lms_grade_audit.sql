-- Add immutable grade audit records for grading draft/release actions.
-- Forward migration
CREATE TABLE IF NOT EXISTS lms_grade_audit (
  grade_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  graded_by BIGINT UNSIGNED NOT NULL,
  score DECIMAL(8,2) NOT NULL,
  max_score DECIMAL(8,2) NOT NULL,
  feedback LONGTEXT DEFAULT NULL,
  action ENUM('draft','override','release') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (grade_audit_id),
  KEY idx_lms_grade_audit_submission (submission_id, created_at),
  KEY idx_lms_grade_audit_graded_by (graded_by, created_at),
  CONSTRAINT fk_lms_grade_audit_submission FOREIGN KEY (submission_id) REFERENCES lms_submissions (submission_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_grade_audit_graded_by FOREIGN KEY (graded_by) REFERENCES users (user_id),
  CONSTRAINT chk_lms_grade_audit_score_nonnegative CHECK (score >= 0),
  CONSTRAINT chk_lms_grade_audit_max_positive CHECK (max_score > 0),
  CONSTRAINT chk_lms_grade_audit_score_le_max CHECK (score <= max_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_lms_grade_audit_no_update;
DROP TRIGGER IF EXISTS trg_lms_grade_audit_no_delete;

DELIMITER $$
CREATE TRIGGER trg_lms_grade_audit_no_update
BEFORE UPDATE ON lms_grade_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lms_grade_audit rows are immutable';
END$$

CREATE TRIGGER trg_lms_grade_audit_no_delete
BEFORE DELETE ON lms_grade_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lms_grade_audit rows are immutable';
END$$
DELIMITER ;

-- Rollback
-- DROP TRIGGER IF EXISTS trg_lms_grade_audit_no_update;
-- DROP TRIGGER IF EXISTS trg_lms_grade_audit_no_delete;
-- DROP TABLE IF EXISTS lms_grade_audit;
