-- Feature flag seed data for Kairos LMS
-- Run on production to ensure all LMS features are enabled
-- Safe: uses INSERT IGNORE to avoid duplicating existing rows
-- MariaDB/MySQL cPanel compatible

-- Global feature flags (course_id IS NULL = applies to all courses)
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_assignments', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('assignments', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_expansion_quizzes', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('quizzes', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_modules', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_grading', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_announcements', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
INSERT INTO lms_feature_flags (flag_key, course_id, enabled) VALUES ('lms_analytics', NULL, 1) ON DUPLICATE KEY UPDATE enabled=1;
