-- Ensure assignment upload restrictions are persisted in the canonical LMS table.
-- Forward migration (idempotent; MySQL/MariaDB compatible).

SET @schema_name := DATABASE();

SET @has_allowed_file_extensions := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assignments'
    AND COLUMN_NAME = 'allowed_file_extensions'
);
SET @ddl := IF(
  @has_allowed_file_extensions = 0,
  'ALTER TABLE lms_assignments ADD COLUMN allowed_file_extensions VARCHAR(255) NULL DEFAULT NULL AFTER max_points',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_max_file_mb := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_assignments'
    AND COLUMN_NAME = 'max_file_mb'
);
SET @ddl := IF(
  @has_max_file_mb = 0,
  'ALTER TABLE lms_assignments ADD COLUMN max_file_mb INT UNSIGNED NOT NULL DEFAULT 50 AFTER allowed_file_extensions',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Idempotent backfill for legacy or manually-created rows.
UPDATE lms_assignments
SET max_file_mb = 50
WHERE max_file_mb IS NULL OR max_file_mb = 0;

UPDATE lms_assignments
SET max_file_mb = 1024
WHERE max_file_mb > 1024;

-- No index is required: these settings are read by assignment primary key/course queries.

-- Rollback (manual, only after rolling application code back):
-- ALTER TABLE lms_assignments
--   DROP COLUMN max_file_mb,
--   DROP COLUMN allowed_file_extensions;
-- Warning: rollback permanently discards saved upload-policy metadata.
