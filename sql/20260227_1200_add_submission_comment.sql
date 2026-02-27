-- Add optional submission comment field for student notes to staff.
ALTER TABLE lms_submissions
  ADD COLUMN IF NOT EXISTS submission_comment TEXT NULL AFTER text_submission;

-- Rollback (manual):
-- ALTER TABLE lms_submissions DROP COLUMN submission_comment;
