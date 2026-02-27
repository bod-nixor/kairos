-- Ensure lms_questions.question_type supports canonical `multiple_select`.
-- Forward migration (idempotent where possible)

SET @schema_name := DATABASE();
SET @enum_def := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND COLUMN_NAME = 'question_type'
  LIMIT 1
);

-- If legacy enum contains multi_select, convert values first.
UPDATE lms_questions
SET question_type = 'multiple_select'
WHERE question_type = 'multi_select';

-- Ensure enum includes canonical multiple_select (and keep multi_select for rollback compatibility).
SET @needs_enum_update := IF(@enum_def IS NULL, 0, IF(LOCATE("'multiple_select'", @enum_def) = 0, 1, 0));
SET @sql := IF(
  @needs_enum_update = 1,
  "ALTER TABLE lms_questions MODIFY COLUMN question_type ENUM('mcq','multi_select','multiple_select','true_false','short_answer','long_answer','file_upload') NOT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rollback (manual)
-- UPDATE lms_questions SET question_type='multi_select' WHERE question_type='multiple_select';
-- ALTER TABLE lms_questions MODIFY COLUMN question_type ENUM('mcq','multi_select','true_false','short_answer','long_answer','file_upload') NOT NULL;
