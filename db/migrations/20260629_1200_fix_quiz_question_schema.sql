-- Fix quiz question creation schema drift.
-- Forward migration: make the production schema match the quiz-question API.

-- lms_questions.question_type must accept the canonical frontend/backend value `multiple_select`.
SET @schema_name := DATABASE();
SET @question_type_enum := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND COLUMN_NAME = 'question_type'
  LIMIT 1
);

SET @needs_question_type_enum := IF(@question_type_enum IS NULL, 0, IF(LOCATE("'multiple_select'", @question_type_enum) = 0, 1, 0));
SET @sql := IF(
  @needs_question_type_enum = 1,
  "ALTER TABLE lms_questions MODIFY COLUMN question_type ENUM('mcq','multi_select','multiple_select','true_false','short_answer','long_answer','file_upload') NOT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE lms_questions
SET question_type = 'multiple_select'
WHERE question_type = 'multi_select';

-- lms_questions.is_required is used by create/update/list/attempt endpoints.
SET @has_is_required := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND COLUMN_NAME = 'is_required'
);
SET @sql := IF(
  @has_is_required = 0,
  'ALTER TABLE lms_questions ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER position',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_required_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'lms_questions'
    AND INDEX_NAME = 'idx_lms_questions_required'
);
SET @sql := IF(
  @has_required_index = 0,
  'ALTER TABLE lms_questions ADD KEY idx_lms_questions_required (assessment_id, is_required, deleted_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE lms_questions
SET is_required = 0
WHERE is_required IS NULL;

-- Rollback (manual, if needed):
-- ALTER TABLE lms_questions DROP KEY idx_lms_questions_required;
-- ALTER TABLE lms_questions DROP COLUMN is_required;
-- UPDATE lms_questions SET question_type='multi_select' WHERE question_type='multiple_select';
-- ALTER TABLE lms_questions MODIFY COLUMN question_type ENUM('mcq','multi_select','true_false','short_answer','long_answer','file_upload') NOT NULL;
