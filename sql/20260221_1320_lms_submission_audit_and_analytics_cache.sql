-- Add submission audit and lightweight analytics cache for LMS.
-- Forward migration
CREATE TABLE IF NOT EXISTS lms_submission_audit (
  submission_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  new_status ENUM('submitted','late','graded','released') NOT NULL,
  occurred_at DATETIME NOT NULL,
  version INT UNSIGNED NOT NULL,
  metadata_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (submission_audit_id),
  KEY idx_lms_submission_audit_submission (submission_id, occurred_at),
  KEY idx_lms_submission_audit_course (course_id, occurred_at),
  CONSTRAINT fk_lms_submission_audit_submission FOREIGN KEY (submission_id) REFERENCES lms_submissions (submission_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_submission_audit_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_submission_audit_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE RESTRICT,
  CONSTRAINT fk_lms_submission_audit_actor FOREIGN KEY (actor_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_lms_submission_audit_no_update;
DROP TRIGGER IF EXISTS trg_lms_submission_audit_no_delete;

DELIMITER $$
CREATE TRIGGER trg_lms_submission_audit_no_update
BEFORE UPDATE ON lms_submission_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lms_submission_audit rows are immutable';
END$$

CREATE TRIGGER trg_lms_submission_audit_no_delete
BEFORE DELETE ON lms_submission_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lms_submission_audit rows are immutable';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS lms_course_analytics_cache (
  course_id BIGINT UNSIGNED NOT NULL,
  payload_json JSON NOT NULL,
  refreshed_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id),
  KEY idx_lms_course_analytics_cache_expires (expires_at),
  CONSTRAINT fk_lms_course_analytics_cache_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_lms_lesson_completions_course_id ON lms_lesson_completions (course_id, completed_at);
CREATE INDEX idx_lms_assessment_attempts_course_id ON lms_assessment_attempts (course_id, status);
CREATE INDEX idx_lms_submissions_course_id ON lms_submissions (course_id, status, submitted_at);
CREATE INDEX idx_lms_grades_course_id ON lms_grades (course_id, status);
CREATE INDEX idx_lms_assessment_responses_question_score ON lms_assessment_responses (question_id, max_score, score);

-- Rollback
-- DROP INDEX idx_lms_assessment_responses_question_score ON lms_assessment_responses;
-- DROP INDEX idx_lms_grades_course_id ON lms_grades;
-- DROP INDEX idx_lms_submissions_course_id ON lms_submissions;
-- DROP INDEX idx_lms_assessment_attempts_course_id ON lms_assessment_attempts;
-- DROP INDEX idx_lms_lesson_completions_course_id ON lms_lesson_completions;
-- DROP TABLE IF EXISTS lms_course_analytics_cache;
-- DROP TRIGGER IF EXISTS trg_lms_submission_audit_no_update;
-- DROP TRIGGER IF EXISTS trg_lms_submission_audit_no_delete;
-- DROP TABLE IF EXISTS lms_submission_audit;
