-- Add staff_private_note, grade_override, and rubric_grades_json columns to lms_grades table.
-- Forward migration (idempotent; MySQL/MariaDB compatible).

SET @schema_name := DATABASE();

SET @has_staff_private_note := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_grades'
    AND COLUMN_NAME = 'staff_private_note'
);

SET @ddl_spn := IF(
  @has_staff_private_note = 0,
  'ALTER TABLE lms_grades ADD COLUMN staff_private_note TEXT NULL DEFAULT NULL AFTER feedback',
  'SELECT 1'
);

PREPARE stmt_spn FROM @ddl_spn;
EXECUTE stmt_spn;
DEALLOCATE PREPARE stmt_spn;

SET @has_grade_override := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_grades'
    AND COLUMN_NAME = 'grade_override'
);

SET @ddl_go := IF(
  @has_grade_override = 0,
  'ALTER TABLE lms_grades ADD COLUMN grade_override DECIMAL(8,2) NULL DEFAULT NULL AFTER staff_private_note',
  'SELECT 1'
);

PREPARE stmt_go FROM @ddl_go;
EXECUTE stmt_go;
DEALLOCATE PREPARE stmt_go;

SET @has_rubric_grades_json := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_grades'
    AND COLUMN_NAME = 'rubric_grades_json'
);

SET @ddl_rg := IF(
  @has_rubric_grades_json = 0,
  'ALTER TABLE lms_grades ADD COLUMN rubric_grades_json JSON NULL DEFAULT NULL AFTER grade_override',
  'SELECT 1'
);

PREPARE stmt_rg FROM @ddl_rg;
EXECUTE stmt_rg;
DEALLOCATE PREPARE stmt_rg;

-- Rollback (manual):
-- ALTER TABLE lms_grades DROP COLUMN staff_private_note, DROP COLUMN grade_override, DROP COLUMN rubric_grades_json;
