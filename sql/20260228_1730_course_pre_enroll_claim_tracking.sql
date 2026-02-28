-- Add claim tracking for pending pre-enrollments.
-- Forward migration:
ALTER TABLE course_pre_enroll
  ADD COLUMN IF NOT EXISTS claimed_user_id BIGINT UNSIGNED NULL AFTER created_at,
  ADD KEY idx_course_pre_enroll_claimed_user (claimed_user_id),
  ADD CONSTRAINT fk_course_pre_enroll_claimed_user
    FOREIGN KEY (claimed_user_id) REFERENCES users (user_id)
    ON DELETE SET NULL;

-- Rollback (manual):
-- ALTER TABLE course_pre_enroll
--   DROP FOREIGN KEY fk_course_pre_enroll_claimed_user,
--   DROP INDEX idx_course_pre_enroll_claimed_user,
--   DROP COLUMN claimed_user_id;
