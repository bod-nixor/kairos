-- Add published_at timestamp to assessments and assignments for activity feed accuracy.
-- Forward migration (idempotent)

SET @schema_name := DATABASE();

-- lms_assessments.published_at
SET @has_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assessments'
    AND COLUMN_NAME = 'published_at'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE lms_assessments ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lms_assignments.published_at
SET @has_col2 := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assignments'
    AND COLUMN_NAME = 'published_at'
);
SET @sql2 := IF(
  @has_col2 = 0,
  'ALTER TABLE lms_assignments ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Backfill: set published_at = updated_at for currently-published items (idempotent)
UPDATE lms_assessments
SET published_at = updated_at
WHERE status = 'published' AND published_at IS NULL;

UPDATE lms_assignments
SET published_at = updated_at
WHERE status = 'published' AND published_at IS NULL;

-- Rollback (manual):
-- ALTER TABLE lms_assessments DROP COLUMN published_at;
-- ALTER TABLE lms_assignments DROP COLUMN published_at;
