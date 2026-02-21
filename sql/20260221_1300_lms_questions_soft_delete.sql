-- Add soft-delete support to lms_questions for consistency with LMS entities.
-- Forward migration
ALTER TABLE lms_questions
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD KEY idx_lms_questions_deleted_at (assessment_id, deleted_at);

-- Rollback
-- ALTER TABLE lms_questions DROP KEY idx_lms_questions_deleted_at, DROP COLUMN deleted_at;
