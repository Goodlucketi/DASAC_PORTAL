CREATE DATABASE IF NOT EXISTS skillportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skillportal;

CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  code VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS programs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL UNIQUE,
  code VARCHAR(20) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_program_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','instructor','hod','director','superadmin') NOT NULL,
  status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  full_name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cohorts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  program_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(40) NOT NULL UNIQUE,
  capacity INT UNSIGNED NOT NULL DEFAULT 100,
  starts_on DATE NULL,
  ends_on DATE NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cohort_program FOREIGN KEY (program_id) REFERENCES programs(id),
  CONSTRAINT fk_cohort_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  matric_no VARCHAR(40) NOT NULL UNIQUE,
  program_id INT UNSIGNED NOT NULL,
  cohort_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  address VARCHAR(500) NOT NULL DEFAULT '',
  next_of_kin VARCHAR(150) NOT NULL DEFAULT '',
  legal_name VARCHAR(150) NOT NULL,
  date_of_birth DATE NULL,
  gender VARCHAR(40) NOT NULL DEFAULT '',
  CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_student_program FOREIGN KEY (program_id) REFERENCES programs(id),
  CONSTRAINT fk_student_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id),
  CONSTRAINT fk_student_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  department_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL DEFAULT '',
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_staff_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  test_max DECIMAL(5,2) NOT NULL DEFAULT 30,
  exam_max DECIMAL(5,2) NOT NULL DEFAULT 70,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_course_department FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_course_instructor FOREIGN KEY (instructor_id) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS program_prerequisites (
  program_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (program_id, course_id),
  CONSTRAINT fk_prerequisite_program FOREIGN KEY (program_id) REFERENCES programs(id),
  CONSTRAINT fk_prerequisite_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  enrolled_at DATE NOT NULL,
  status ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_enrollment_student_course (student_id, course_id),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_enrollment_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS geofences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  radius_m INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  geofence_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  assigned_to INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('scheduled','open','closed') NOT NULL DEFAULT 'scheduled',
  CONSTRAINT fk_session_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_session_geofence FOREIGN KEY (geofence_id) REFERENCES geofences(id),
  CONSTRAINT fk_session_creator FOREIGN KEY (created_by) REFERENCES staff(id),
  CONSTRAINT fk_session_assignee FOREIGN KEY (assigned_to) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  checked_in_at DATETIME NOT NULL,
  distance_m DECIMAL(9,2) NOT NULL,
  status ENUM('present','late','excused') NOT NULL DEFAULT 'present',
  UNIQUE KEY uq_attendance_session_student (session_id, student_id),
  CONSTRAINT fk_attendance_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id),
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  distance_m DECIMAL(9,2) NOT NULL,
  flag_reason ENUM('outside_geofence','duplicate_checkin','repeated_failure','suspicious_location') NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attempt_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id),
  CONSTRAINT fk_attempt_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_attempt_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_corrections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NULL,
  attendance_record_id INT UNSIGNED NULL,
  submitted_by INT UNSIGNED NULL,
  requested_status ENUM('present','excused') NOT NULL DEFAULT 'present',
  explanation TEXT NOT NULL,
  proof_path VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  review_reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_att_corr_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_att_corr_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id),
  CONSTRAINT fk_att_corr_record FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id),
  CONSTRAINT fk_att_corr_submitter FOREIGN KEY (submitted_by) REFERENCES users(id),
  CONSTRAINT fk_att_corr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS score_flags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  flagged_by INT UNSIGNED NOT NULL,
  flag_type ENUM('missing','disputed') NOT NULL,
  note VARCHAR(1000) NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_score_flag_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_score_flag_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_score_flag_user FOREIGN KEY (flagged_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  test_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  exam_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  status ENUM('draft','verified','published') NOT NULL DEFAULT 'draft',
  entered_by INT UNSIGNED NOT NULL,
  verified_by INT UNSIGNED NULL,
  published_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_score_student_course (student_id, course_id),
  CONSTRAINT fk_score_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_score_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_score_entered_by FOREIGN KEY (entered_by) REFERENCES users(id),
  CONSTRAINT fk_score_verified_by FOREIGN KEY (verified_by) REFERENCES users(id),
  CONSTRAINT fk_score_published_by FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS score_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  score_id INT UNSIGNED NOT NULL,
  reviewer_id INT UNSIGNED NOT NULL,
  decision ENUM('verified','returned') NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_score_review_score FOREIGN KEY (score_id) REFERENCES scores(id),
  CONSTRAINT fk_score_review_user FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS score_amendments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  score_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  test_score DECIMAL(5,2) NOT NULL,
  exam_score DECIMAL(5,2) NOT NULL,
  request_reason VARCHAR(1000) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  review_reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  CONSTRAINT fk_amendment_score FOREIGN KEY (score_id) REFERENCES scores(id),
  CONSTRAINT fk_amendment_requester FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_amendment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  serial VARCHAR(80) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  status ENUM('pending','recommended','cleared','rejected','revoked') NOT NULL DEFAULT 'pending',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  CONSTRAINT fk_certificate_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_certificate_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transfer_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  current_program_id INT UNSIGNED NOT NULL,
  target_program_id INT UNSIGNED NOT NULL,
  reason VARCHAR(100) NOT NULL,
  explanation TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  current_department_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NULL,
  status ENUM('pending_instructor','pending_current_hod','pending_target_hod','pending_director','approved','rejected','returned') NOT NULL DEFAULT 'pending_instructor',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_transfer_current_program FOREIGN KEY (current_program_id) REFERENCES programs(id),
  CONSTRAINT fk_transfer_target_program FOREIGN KEY (target_program_id) REFERENCES programs(id),
  CONSTRAINT fk_transfer_department FOREIGN KEY (current_department_id) REFERENCES departments(id),
  CONSTRAINT fk_transfer_instructor FOREIGN KEY (instructor_id) REFERENCES staff(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transfer_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT UNSIGNED NOT NULL,
  reviewer_id INT UNSIGNED NOT NULL,
  stage ENUM('instructor','current_hod','target_hod','director') NOT NULL,
  decision ENUM('approved','rejected','returned','comment') NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_review_request FOREIGN KEY (transfer_id) REFERENCES transfer_requests(id),
  CONSTRAINT fk_transfer_review_user FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_change_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  field_name ENUM('legal_name','date_of_birth','gender') NOT NULL,
  requested_value VARCHAR(255) NOT NULL,
  proof_path VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_profile_change_student FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audience ENUM('all','course','department','program') NOT NULL DEFAULT 'all',
  course_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  program_id INT UNSIGNED NULL,
  posted_by INT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_announcement_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_announcement_department FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_announcement_program FOREIGN KEY (program_id) REFERENCES programs(id),
  CONSTRAINT fk_announcement_poster FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO departments (id, name, code) VALUES
  (1, 'Renewable Energy', 'REN'), (2, 'Electrical Trades', 'ELE');

INSERT IGNORE INTO programs (id, department_id, name, code) VALUES
  (1, 1, 'Solar Installation', 'SOL'), (2, 2, 'Electrical Installation', 'EIN'),
  (3, 2, 'Welding and Fabrication', 'WEL'), (4, 1, 'Computer Hardware Maintenance', 'CHM');

-- Demo password for each account is 1234; this bcrypt hash was generated with PHP password_hash().
INSERT IGNORE INTO users (id, email, password_hash, role, status, full_name) VALUES
  (1, 'student@demo.com', '$2y$12$u/GR4sdYwdk2novOShNWWuu3WCmz0sov54LGu7P1l326gLeHnDfg6', 'student', 'active', 'Amara Okafor'),
  (2, 'instructor@demo.com', '$2y$12$u/GR4sdYwdk2novOShNWWuu3WCmz0sov54LGu7P1l326gLeHnDfg6', 'instructor', 'active', 'Tunde Bello'),
  (3, 'hod@demo.com', '$2y$12$u/GR4sdYwdk2novOShNWWuu3WCmz0sov54LGu7P1l326gLeHnDfg6', 'hod', 'active', 'Grace Adeyemi'),
  (4, 'director@demo.com', '$2y$12$u/GR4sdYwdk2novOShNWWuu3WCmz0sov54LGu7P1l326gLeHnDfg6', 'director', 'active', 'Kola Adebayo'),
  (5, 'superadmin@demo.com', '$2y$12$u/GR4sdYwdk2novOShNWWuu3WCmz0sov54LGu7P1l326gLeHnDfg6', 'superadmin', 'active', 'Nneka Eze');

INSERT IGNORE INTO students (id, user_id, matric_no, program_id, department_id, phone, email, address, next_of_kin, legal_name, date_of_birth, gender) VALUES
  (1, 1, 'SP/2025/0142', 1, 1, '08012345678', 'student@demo.com', '14 Palm Avenue, Lagos', 'Chidi Okafor', 'Amara Okafor', '2004-04-12', 'Female');

INSERT IGNORE INTO staff (id, user_id, department_id, title) VALUES
  (1, 2, 1, 'Solar Installation Instructor'), (2, 3, 1, 'Head of Department'),
  (3, 4, 1, 'Training Director'), (4, 5, 1, 'System Administrator');

INSERT IGNORE INTO courses (id, department_id, instructor_id, code, title) VALUES
  (1, 1, 1, 'SOL 201', 'Solar PV System Design'),
  (2, 1, 1, 'SOL 203', 'Electrical Safety Practice'),
  (3, 1, 1, 'SOL 205', 'Installation Workshop');

INSERT IGNORE INTO enrollments (student_id, course_id, enrolled_at) VALUES
  (1, 1, CURRENT_DATE), (1, 2, CURRENT_DATE), (1, 3, CURRENT_DATE);

INSERT IGNORE INTO geofences (id, name, latitude, longitude, radius_m, active) VALUES
  (1, 'Lagos Skills Campus', 6.5244000, 3.3792000, 150, 1);

INSERT IGNORE INTO attendance_sessions (id, course_id, geofence_id, title, starts_at, ends_at, status) VALUES
  (1, 3, 1, 'Solar PV Installation Lab', TIMESTAMP(CURRENT_DATE, '00:00:00'), TIMESTAMP(CURRENT_DATE, '23:59:59'), 'open');

INSERT IGNORE INTO attendance_records (session_id, student_id, checked_in_at, distance_m, status) VALUES
  (1, 1, TIMESTAMP(CURRENT_DATE, '08:42:00'), 34.50, 'present');

INSERT IGNORE INTO scores (id, student_id, course_id, test_score, exam_score, status, entered_by, verified_by, published_by) VALUES
  (1, 1, 1, 24, 46, 'published', 2, 3, 4),
  (2, 1, 2, 22, 36, 'published', 2, 3, 4),
  (3, 1, 3, 26, 49, 'verified', 2, 3, NULL);

INSERT IGNORE INTO certificates (student_id, serial, title, status, approved_by, approved_at) VALUES
  (1, 'SP-CERT-2026-0142', 'Certificate in Solar Installation', 'cleared', 4, CURRENT_TIMESTAMP);

INSERT IGNORE INTO announcements (title, body, pinned, published_at) VALUES
  ('Workshop safety briefing', 'All current cohort learners should attend the safety briefing before Friday lab sessions.', 1, CURRENT_TIMESTAMP),
  ('Assessment timetable published', 'The practical assessment timetable is now available from your instructor.', 0, CURRENT_TIMESTAMP),
  ('Campus equipment update', 'New test meters are available for supervised checkout from the workshop desk.', 0, CURRENT_TIMESTAMP);

INSERT IGNORE INTO notifications (user_id, body) VALUES
  (1, 'Your attendance record was updated.'), (1, 'Workshop safety briefing is pinned.');