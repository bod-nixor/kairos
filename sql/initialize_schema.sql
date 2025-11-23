-- Kairos base schema for MariaDB/MySQL (InnoDB)
-- Run with: mariadb -u <user> -p < sql/initialize_schema.sql

CREATE DATABASE IF NOT EXISTS kairos
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE kairos;

-- Core reference tables
CREATE TABLE IF NOT EXISTS roles (
  role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id),
  UNIQUE KEY uk_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  google_id VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  picture_url VARCHAR(512) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uk_users_google (google_id),
  UNIQUE KEY uk_users_email (email),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courses (
  course_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(64) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id),
  UNIQUE KEY uk_courses_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rooms (
  room_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  location VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (room_id),
  KEY idx_rooms_course (course_id),
  CONSTRAINT fk_rooms_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Queues and live queue entries
CREATE TABLE IF NOT EXISTS queues (
  queue_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (queue_id),
  KEY idx_queues_room (room_id),
  CONSTRAINT fk_queues_room FOREIGN KEY (room_id) REFERENCES rooms (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS queue_entries (
  queue_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  `timestamp` DATETIME NOT NULL,
  PRIMARY KEY (queue_id, user_id),
  KEY idx_queue_entries_queue_user (queue_id, user_id),
  CONSTRAINT fk_queue_entries_queue FOREIGN KEY (queue_id) REFERENCES queues (queue_id),
  CONSTRAINT fk_queue_entries_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- View used by queue/room endpoints to expose course metadata
DROP VIEW IF EXISTS queues_info;
CREATE VIEW queues_info AS
SELECT
  q.queue_id,
  q.room_id,
  r.course_id,
  q.name,
  q.description
FROM queues q
JOIN rooms r ON r.room_id = q.room_id;

-- TA servicing and optional audit trail
CREATE TABLE IF NOT EXISTS ta_assignments (
  ta_assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ta_user_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  queue_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  PRIMARY KEY (ta_assignment_id),
  KEY idx_ta_assignments_queue (queue_id),
  KEY idx_ta_assignments_student (student_user_id),
  CONSTRAINT fk_ta_assignments_ta FOREIGN KEY (ta_user_id) REFERENCES users (user_id),
  CONSTRAINT fk_ta_assignments_student FOREIGN KEY (student_user_id) REFERENCES users (user_id),
  CONSTRAINT fk_ta_assignments_queue FOREIGN KEY (queue_id) REFERENCES queues (queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ta_audit_log (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  queue_id BIGINT UNSIGNED DEFAULT NULL,
  student_user_id BIGINT UNSIGNED DEFAULT NULL,
  meta_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_ta_audit_user (actor_user_id),
  CONSTRAINT fk_ta_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ta_comments (
  comment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  ta_user_id BIGINT UNSIGNED NOT NULL,
  text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id),
  KEY idx_ta_comments_user_course (user_id, course_id),
  CONSTRAINT fk_ta_comments_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_ta_comments_course FOREIGN KEY (course_id) REFERENCES courses (course_id),
  CONSTRAINT fk_ta_comments_ta FOREIGN KEY (ta_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional change feed for SSE/WebSocket payload persistence
CREATE TABLE IF NOT EXISTS change_log (
  change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel VARCHAR(64) NOT NULL,
  ref_id BIGINT UNSIGNED DEFAULT NULL,
  course_id BIGINT UNSIGNED DEFAULT NULL,
  payload_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (change_id),
  KEY idx_change_log_channel (channel),
  KEY idx_change_log_ref (ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enrollment and staff mappings used by RBAC helpers
CREATE TABLE IF NOT EXISTS student_courses (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_student_courses_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_student_courses_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_courses (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (user_id, course_id),
  KEY idx_user_courses_role (role),
  CONSTRAINT fk_user_courses_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_user_courses_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollments (
  enrollment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (enrollment_id),
  KEY idx_enrollments_user (user_id),
  KEY idx_enrollments_course (course_id),
  KEY idx_enrollments_role (role),
  CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_enrollments_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ta_courses (
  ta_user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (ta_user_id, course_id),
  CONSTRAINT fk_ta_courses_user FOREIGN KEY (ta_user_id) REFERENCES users (user_id),
  CONSTRAINT fk_ta_courses_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_tas (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_course_tas_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_course_tas_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ta_enrollments (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_ta_enrollments_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_ta_enrollments_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_courses (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_staff_courses_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_staff_courses_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_courses (
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_manager_courses_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_manager_courses_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_staff (
  course_staff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  PRIMARY KEY (course_staff_id),
  KEY idx_course_staff_role (role),
  CONSTRAINT fk_course_staff_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_course_staff_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_roles (
  course_role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  PRIMARY KEY (course_role_id),
  KEY idx_course_roles_role (role),
  CONSTRAINT fk_course_roles_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_course_roles_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student progress tracking
CREATE TABLE IF NOT EXISTS progress_category (
  category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (category_id),
  KEY idx_progress_category_course (course_id),
  CONSTRAINT fk_progress_category_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS progress_details (
  detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (detail_id),
  KEY idx_progress_details_category (category_id),
  CONSTRAINT fk_progress_details_category FOREIGN KEY (category_id) REFERENCES progress_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS progress_status (
  progress_status_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  display_order INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (progress_status_id),
  UNIQUE KEY uk_progress_status_name (name),
  KEY idx_progress_status_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS progress (
  user_id BIGINT UNSIGNED NOT NULL,
  detail_id BIGINT UNSIGNED NOT NULL,
  status_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, detail_id),
  KEY idx_progress_detail (detail_id),
  KEY idx_progress_status (status_id),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT fk_progress_detail FOREIGN KEY (detail_id) REFERENCES progress_details (detail_id),
  CONSTRAINT fk_progress_status FOREIGN KEY (status_id) REFERENCES progress_status (progress_status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed base roles to simplify initial setup
INSERT INTO roles (name) VALUES ('student'), ('ta'), ('manager'), ('admin')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Seed default progress statuses used by APIs/UI
INSERT INTO progress_status (name, display_order)
VALUES ('Pending', 1), ('Completed', 2), ('Review', 3)
ON DUPLICATE KEY UPDATE name = VALUES(name), display_order = VALUES(display_order);
