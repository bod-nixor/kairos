-- LMS expansion schema for Kairos
-- Forward migration

CREATE TABLE IF NOT EXISTS lms_branding_config (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_name VARCHAR(255) NOT NULL DEFAULT 'Nixor College',
  logo_url VARCHAR(512) DEFAULT NULL,
  primary_color VARCHAR(32) DEFAULT NULL,
  secondary_color VARCHAR(32) DEFAULT NULL,
  allowed_domains_json JSON NOT NULL,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lms_branding_updated_by (updated_by),
  CONSTRAINT fk_lms_branding_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO lms_branding_config (institution_name, allowed_domains_json)
SELECT 'Nixor College', JSON_ARRAY('nixorcollege.edu.pk')
WHERE NOT EXISTS (SELECT 1 FROM lms_branding_config);

CREATE TABLE IF NOT EXISTS lms_feature_flags (
  feature_flag_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED DEFAULT NULL,
  flag_key VARCHAR(128) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  rollout_json JSON DEFAULT NULL,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (feature_flag_id),
  UNIQUE KEY uk_lms_feature_course_key (course_id, flag_key),
  KEY idx_lms_feature_key (flag_key),
  CONSTRAINT fk_lms_feature_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_feature_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_course_sections (
  section_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (section_id),
  KEY idx_lms_sections_course (course_id, position),
  CONSTRAINT fk_lms_sections_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_sections_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_lessons (
  lesson_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT DEFAULT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  requires_previous TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (lesson_id),
  KEY idx_lms_lessons_section (section_id, position),
  KEY idx_lms_lessons_course (course_id),
  CONSTRAINT fk_lms_lessons_section FOREIGN KEY (section_id) REFERENCES lms_course_sections (section_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_lessons_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_lessons_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_resources (
  resource_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  resource_type ENUM('file','link','embed','video') NOT NULL DEFAULT 'file',
  drive_file_id VARCHAR(255) DEFAULT NULL,
  drive_preview_url VARCHAR(1024) DEFAULT NULL,
  mime_type VARCHAR(255) DEFAULT NULL,
  file_size BIGINT UNSIGNED DEFAULT NULL,
  checksum_sha256 CHAR(64) DEFAULT NULL,
  access_scope ENUM('course','assignment_submission','private') NOT NULL DEFAULT 'course',
  metadata_json JSON DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (resource_id),
  KEY idx_lms_resources_course (course_id, created_at),
  KEY idx_lms_resources_drive (drive_file_id),
  CONSTRAINT fk_lms_resources_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_resources_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_lesson_blocks (
  block_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lesson_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  block_type ENUM('text','embed','file','video','checklist') NOT NULL,
  content_json JSON NOT NULL,
  resource_id BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (block_id),
  KEY idx_lms_blocks_lesson (lesson_id, position),
  KEY idx_lms_blocks_resource (resource_id),
  CONSTRAINT fk_lms_blocks_lesson FOREIGN KEY (lesson_id) REFERENCES lms_lessons (lesson_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_blocks_resource FOREIGN KEY (resource_id) REFERENCES lms_resources (resource_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_lesson_completions (
  completion_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lesson_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (completion_id),
  UNIQUE KEY uk_lms_completion (lesson_id, user_id),
  KEY idx_lms_completion_course_user (course_id, user_id),
  CONSTRAINT fk_lms_completion_lesson FOREIGN KEY (lesson_id) REFERENCES lms_lessons (lesson_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_completion_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_completion_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_assessments (
  assessment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  instructions TEXT DEFAULT NULL,
  assessment_type ENUM('quiz','test') NOT NULL DEFAULT 'quiz',
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
  time_limit_minutes INT UNSIGNED DEFAULT NULL,
  available_from DATETIME DEFAULT NULL,
  due_at DATETIME DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (assessment_id),
  KEY idx_lms_assessment_course (course_id, status),
  CONSTRAINT fk_lms_assessment_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_assessment_section FOREIGN KEY (section_id) REFERENCES lms_course_sections (section_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_assessment_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_questions (
  question_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id BIGINT UNSIGNED NOT NULL,
  prompt TEXT NOT NULL,
  question_type ENUM('mcq','multi_select','true_false','short_answer','long_answer','file_upload') NOT NULL,
  points DECIMAL(7,2) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  answer_key_json JSON DEFAULT NULL,
  settings_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id),
  KEY idx_lms_questions_assessment (assessment_id, position),
  CONSTRAINT fk_lms_questions_assessment FOREIGN KEY (assessment_id) REFERENCES lms_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_question_options (
  option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  option_text TEXT NOT NULL,
  option_value VARCHAR(255) DEFAULT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (option_id),
  KEY idx_lms_options_question (question_id, position),
  CONSTRAINT fk_lms_options_question FOREIGN KEY (question_id) REFERENCES lms_questions (question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_assessment_attempts (
  attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('in_progress','submitted','auto_graded','manual_required','graded') NOT NULL DEFAULT 'in_progress',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME DEFAULT NULL,
  score DECIMAL(8,2) DEFAULT NULL,
  max_score DECIMAL(8,2) DEFAULT NULL,
  grading_status ENUM('pending','auto_graded','manual_required','graded','released') NOT NULL DEFAULT 'pending',
  graded_by BIGINT UNSIGNED DEFAULT NULL,
  graded_at DATETIME DEFAULT NULL,
  released_at DATETIME DEFAULT NULL,
  PRIMARY KEY (attempt_id),
  KEY idx_lms_attempts_assessment_user (assessment_id, user_id, started_at),
  KEY idx_lms_attempts_course_status (course_id, status),
  CONSTRAINT fk_lms_attempts_assessment FOREIGN KEY (assessment_id) REFERENCES lms_assessments (assessment_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_attempts_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_attempts_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_attempts_graded_by FOREIGN KEY (graded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_assessment_responses (
  response_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  response_json JSON DEFAULT NULL,
  text_response LONGTEXT DEFAULT NULL,
  resource_id BIGINT UNSIGNED DEFAULT NULL,
  score DECIMAL(8,2) DEFAULT NULL,
  max_score DECIMAL(8,2) DEFAULT NULL,
  needs_manual_grading TINYINT(1) NOT NULL DEFAULT 0,
  graded_by BIGINT UNSIGNED DEFAULT NULL,
  graded_at DATETIME DEFAULT NULL,
  feedback TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (response_id),
  UNIQUE KEY uk_lms_attempt_question (attempt_id, question_id),
  KEY idx_lms_response_needs_manual (needs_manual_grading),
  CONSTRAINT fk_lms_response_attempt FOREIGN KEY (attempt_id) REFERENCES lms_assessment_attempts (attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_response_question FOREIGN KEY (question_id) REFERENCES lms_questions (question_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_response_resource FOREIGN KEY (resource_id) REFERENCES lms_resources (resource_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_response_graded_by FOREIGN KEY (graded_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_assignments (
  assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  instructions LONGTEXT DEFAULT NULL,
  due_at DATETIME DEFAULT NULL,
  late_allowed TINYINT(1) NOT NULL DEFAULT 1,
  max_points DECIMAL(8,2) NOT NULL DEFAULT 100,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (assignment_id),
  KEY idx_lms_assignment_course (course_id, due_at, status),
  CONSTRAINT fk_lms_assignment_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_assignment_section FOREIGN KEY (section_id) REFERENCES lms_course_sections (section_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_assignment_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_assignment_tas (
  assignment_id BIGINT UNSIGNED NOT NULL,
  ta_user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id, ta_user_id),
  KEY idx_lms_assignment_tas_ta (ta_user_id),
  CONSTRAINT fk_lms_assignment_tas_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_assignment_tas_user FOREIGN KEY (ta_user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_submissions (
  submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  text_submission LONGTEXT DEFAULT NULL,
  status ENUM('draft','submitted','late','graded','released') NOT NULL DEFAULT 'submitted',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_late TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (submission_id),
  KEY idx_lms_submissions_assignment_student (assignment_id, student_user_id, submitted_at),
  KEY idx_lms_submissions_course_status (course_id, status),
  CONSTRAINT fk_lms_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_submissions_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_submissions_student FOREIGN KEY (student_user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_submission_files (
  submission_file_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (submission_file_id),
  KEY idx_lms_submission_files_submission (submission_id),
  CONSTRAINT fk_lms_submission_files_submission FOREIGN KEY (submission_id) REFERENCES lms_submissions (submission_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_submission_files_resource FOREIGN KEY (resource_id) REFERENCES lms_resources (resource_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_rubrics (
  rubric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('draft','released') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rubric_id),
  KEY idx_lms_rubric_assignment (assignment_id),
  CONSTRAINT fk_lms_rubric_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_rubric_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_rubric_items (
  rubric_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rubric_id BIGINT UNSIGNED NOT NULL,
  criterion VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  max_points DECIMAL(8,2) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rubric_item_id),
  KEY idx_lms_rubric_items_rubric (rubric_id, position),
  CONSTRAINT fk_lms_rubric_items_rubric FOREIGN KEY (rubric_id) REFERENCES lms_rubrics (rubric_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_grades (
  grade_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED DEFAULT NULL,
  submission_id BIGINT UNSIGNED DEFAULT NULL,
  attempt_id BIGINT UNSIGNED DEFAULT NULL,
  status ENUM('draft','released','overridden') NOT NULL DEFAULT 'draft',
  score DECIMAL(8,2) NOT NULL,
  max_score DECIMAL(8,2) NOT NULL,
  feedback LONGTEXT DEFAULT NULL,
  graded_by BIGINT UNSIGNED NOT NULL,
  released_by BIGINT UNSIGNED DEFAULT NULL,
  released_at DATETIME DEFAULT NULL,
  override_reason TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (grade_id),
  KEY idx_lms_grades_student (student_user_id, course_id),
  KEY idx_lms_grades_assignment (assignment_id, status),
  CONSTRAINT fk_lms_grades_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_grades_student FOREIGN KEY (student_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_grades_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments (assignment_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_grades_submission FOREIGN KEY (submission_id) REFERENCES lms_submissions (submission_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_grades_attempt FOREIGN KEY (attempt_id) REFERENCES lms_assessment_attempts (attempt_id) ON DELETE SET NULL,
  CONSTRAINT fk_lms_grades_graded_by FOREIGN KEY (graded_by) REFERENCES users (user_id),
  CONSTRAINT fk_lms_grades_released_by FOREIGN KEY (released_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_announcements (
  announcement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body LONGTEXT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (announcement_id),
  KEY idx_lms_announcements_course (course_id, created_at),
  CONSTRAINT fk_lms_announcements_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_lms_announcements_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lms_event_outbox (
  event_id CHAR(36) NOT NULL,
  event_name VARCHAR(128) NOT NULL,
  occurred_at DATETIME NOT NULL,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  course_id BIGINT UNSIGNED DEFAULT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  payload_json JSON NOT NULL,
  delivered_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_lms_outbox_delivery (delivered_at, occurred_at),
  KEY idx_lms_outbox_course (course_id, delivered_at),
  CONSTRAINT fk_lms_outbox_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id),
  CONSTRAINT fk_lms_outbox_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (manual): drop in reverse dependency order
-- DROP TABLE IF EXISTS lms_event_outbox;
-- DROP TABLE IF EXISTS lms_announcements;
-- DROP TABLE IF EXISTS lms_grades;
-- DROP TABLE IF EXISTS lms_rubric_items;
-- DROP TABLE IF EXISTS lms_rubrics;
-- DROP TABLE IF EXISTS lms_submission_files;
-- DROP TABLE IF EXISTS lms_submissions;
-- DROP TABLE IF EXISTS lms_assignment_tas;
-- DROP TABLE IF EXISTS lms_assignments;
-- DROP TABLE IF EXISTS lms_assessment_responses;
-- DROP TABLE IF EXISTS lms_assessment_attempts;
-- DROP TABLE IF EXISTS lms_question_options;
-- DROP TABLE IF EXISTS lms_questions;
-- DROP TABLE IF EXISTS lms_assessments;
-- DROP TABLE IF EXISTS lms_lesson_completions;
-- DROP TABLE IF EXISTS lms_lesson_blocks;
-- DROP TABLE IF EXISTS lms_resources;
-- DROP TABLE IF EXISTS lms_lessons;
-- DROP TABLE IF EXISTS lms_course_sections;
-- DROP TABLE IF EXISTS lms_feature_flags;
-- DROP TABLE IF EXISTS lms_branding_config;
