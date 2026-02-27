-- Add assignment upload restriction fields and per-question required flag.
-- Forward migration (idempotent)

SET @schema_name := DATABASE();

-- lms_assignments.allowed_file_extensions
SET @has_col_allowed_ext := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assignments'
    AND COLUMN_NAME = 'allowed_file_extensions'
);
SET @sql := IF(
  @has_col_allowed_ext = 0,
  'ALTER TABLE lms_assignments ADD COLUMN allowed_file_extensions VARCHAR(255) NULL DEFAULT NULL AFTER max_points',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lms_assignments.max_file_mb
SET @has_col_max_file_mb := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assignments'
    AND COLUMN_NAME = 'max_file_mb'
);
SET @sql := IF(
  @has_col_max_file_mb = 0,
  'ALTER TABLE lms_assignments ADD COLUMN max_file_mb INT UNSIGNED NOT NULL DEFAULT 50 AFTER allowed_file_extensions',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lms_questions.is_required
SET @has_col_is_required := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND COLUMN_NAME = 'is_required'
);
SET @sql := IF(
  @has_col_is_required = 0,
  'ALTER TABLE lms_questions ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER position',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lms_questions.idx_lms_questions_required
SET @has_idx_required := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND INDEX_NAME = 'idx_lms_questions_required'
);
SET @sql := IF(
  @has_idx_required = 0,
  'ALTER TABLE lms_questions ADD KEY idx_lms_questions_required (assessment_id, is_required, deleted_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
