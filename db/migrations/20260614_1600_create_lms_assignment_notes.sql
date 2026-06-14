-- Create lms_assignment_notes table to support independent student notes on assignments.
-- Forward migration (idempotent; MySQL/MariaDB compatible).

CREATE TABLE IF NOT EXISTS lms_assignment_notes (
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  notes TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id, student_user_id),
  CONSTRAINT fk_lms_assignment_notes_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_assignment_notes_student FOREIGN KEY (student_user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (manual, only after rolling application code back):
-- DROP TABLE IF EXISTS lms_assignment_notes;
