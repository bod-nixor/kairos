-- Improve latest-grade lookup performance for grading queue/detail APIs.
ALTER TABLE lms_grades
  ADD INDEX IF NOT EXISTS idx_lms_grades_submission_updated_grade (submission_id, updated_at, grade_id);

-- Rollback (manual):
-- ALTER TABLE lms_grades DROP INDEX idx_lms_grades_submission_updated_grade;
