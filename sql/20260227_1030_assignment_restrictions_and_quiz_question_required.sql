-- Add assignment upload restriction fields and per-question required flag.
-- Forward migration
ALTER TABLE lms_assignments
  ADD COLUMN allowed_file_extensions VARCHAR(255) NULL DEFAULT NULL AFTER max_points,
  ADD COLUMN max_file_mb INT UNSIGNED NOT NULL DEFAULT 50 AFTER allowed_file_extensions;

ALTER TABLE lms_questions
  ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER position,
  ADD KEY idx_lms_questions_required (assessment_id, is_required, deleted_at);

-- Backfill existing rows (idempotent)
UPDATE lms_assignments
SET max_file_mb = 50
WHERE max_file_mb IS NULL OR max_file_mb <= 0;

UPDATE lms_questions
SET is_required = 0
WHERE is_required IS NULL;

-- Rollback (manual)
-- ALTER TABLE lms_questions DROP KEY idx_lms_questions_required, DROP COLUMN is_required;
-- ALTER TABLE lms_assignments DROP COLUMN max_file_mb, DROP COLUMN allowed_file_extensions;
