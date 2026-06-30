-- Add quiz answer explanations and submitted-response review snapshots.
-- Forward migration: explanations live on questions; snapshots preserve review context
-- for attempts submitted after this migration.

-- lms_questions.answer_explanation
SET @has_answer_explanation := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'lms_questions'
    AND COLUMN_NAME = 'answer_explanation'
);

SET @ddl := IF(
  @has_answer_explanation = 0,
  'ALTER TABLE lms_questions ADD COLUMN answer_explanation TEXT NULL AFTER answer_key_json',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lms_assessment_responses.question_snapshot_json
SET @has_question_snapshot := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'lms_assessment_responses'
    AND COLUMN_NAME = 'question_snapshot_json'
);

SET @ddl := IF(
  @has_question_snapshot = 0,
  'ALTER TABLE lms_assessment_responses ADD COLUMN question_snapshot_json JSON DEFAULT NULL AFTER response_json',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill notes:
-- Existing questions intentionally keep answer_explanation NULL.
-- Existing responses intentionally keep question_snapshot_json NULL and review code
-- falls back to the current question/options when no submitted snapshot exists.

-- Rollback, if needed:
-- ALTER TABLE lms_assessment_responses DROP COLUMN question_snapshot_json;
-- ALTER TABLE lms_questions DROP COLUMN answer_explanation;
