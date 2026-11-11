-- =====================================================================
-- ServerWorld Interactive Online Examination System
-- Database Schema Migration (MySQL 5.7+ / 8.0+ / MariaDB)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `serverworld_exam` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `serverworld_exam`;

-- 1. EXAMINATIONS TABLE
CREATE TABLE IF NOT EXISTS `exams` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(100) NOT NULL DEFAULT 'General Studies',
  `access_code` VARCHAR(50) NOT NULL UNIQUE,
  `duration_minutes` INT NOT NULL DEFAULT 15,
  `max_marks` DECIMAL(8,2) NOT NULL DEFAULT 100.00,
  `pass_percentage` INT NOT NULL DEFAULT 40,
  `certificate_threshold` INT NOT NULL DEFAULT 85,
  `result_visibility` ENUM('immediate', 'hidden') NOT NULL DEFAULT 'immediate',
  `single_attempt` TINYINT(1) NOT NULL DEFAULT 0,
  `paper_file` VARCHAR(255) DEFAULT NULL COMMENT 'Reference uploaded PDF/DOCX',
  `paper_type` ENUM('interactive', 'document') NOT NULL DEFAULT 'interactive',
  `test_type` ENUM('live', 'mock') NOT NULL DEFAULT 'live',
  `status` ENUM('LIVE', 'DRAFT', 'CLOSED') NOT NULL DEFAULT 'LIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_exam_code` (`access_code`),
  INDEX `idx_exam_subject` (`subject`),
  INDEX `idx_exam_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. QUESTIONS TABLE
CREATE TABLE IF NOT EXISTS `questions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `exam_id` INT NOT NULL,
  `question_number` INT NOT NULL DEFAULT 1,
  `question_text` TEXT NOT NULL,
  `option_a` TEXT NOT NULL,
  `option_b` TEXT NOT NULL,
  `option_c` TEXT NOT NULL,
  `option_d` TEXT NOT NULL,
  `correct_answer` VARCHAR(10) NOT NULL COMMENT 'A, B, C, D or 0, 1, 2, 3',
  `marks` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `explanation` TEXT DEFAULT NULL,
  `topic` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_question_exam` (`exam_id`, `question_number`),
  CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. EXAM ATTEMPTS TABLE (Session & Server-Side Timer Validation)
CREATE TABLE IF NOT EXISTS `exam_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `exam_id` INT NOT NULL,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `student_name` VARCHAR(150) NOT NULL,
  `student_urn` VARCHAR(100) NOT NULL,
  `access_code` VARCHAR(50) NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME DEFAULT NULL,
  `duration_seconds` INT NOT NULL DEFAULT 900,
  `status` ENUM('IN_PROGRESS', 'SUBMITTED', 'EXPIRED') NOT NULL DEFAULT 'IN_PROGRESS',
  `score` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `total_marks` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `total_questions` INT NOT NULL DEFAULT 0,
  `correct_count` INT NOT NULL DEFAULT 0,
  `incorrect_count` INT NOT NULL DEFAULT 0,
  `unanswered_count` INT NOT NULL DEFAULT 0,
  `percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `time_taken_seconds` INT NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_attempt_student` (`student_urn`, `exam_id`),
  INDEX `idx_attempt_session` (`session_token`),
  INDEX `idx_attempt_status` (`status`),
  CONSTRAINT `fk_attempts_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. STUDENT RESPONSES TABLE (Autosaved Answer Selections)
CREATE TABLE IF NOT EXISTS `student_responses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT NOT NULL,
  `question_id` INT NOT NULL,
  `selected_option` VARCHAR(10) DEFAULT NULL COMMENT 'A, B, C, D or NULL if unanswered',
  `is_marked_for_review` TINYINT(1) NOT NULL DEFAULT 0,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `marks_awarded` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_question` (`attempt_id`, `question_id`),
  CONSTRAINT `fk_responses_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_responses_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. EXAM RESULTS TABLE (Historical Performance & Ranking Records)
CREATE TABLE IF NOT EXISTS `exam_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT NOT NULL UNIQUE,
  `exam_id` INT NOT NULL,
  `student_name` VARCHAR(150) NOT NULL,
  `student_urn` VARCHAR(100) NOT NULL,
  `score` DECIMAL(8,2) NOT NULL,
  `total_marks` DECIMAL(8,2) NOT NULL,
  `percentage` DECIMAL(5,2) NOT NULL,
  `all_india_rank` INT NOT NULL DEFAULT 1,
  `time_taken` VARCHAR(50) DEFAULT NULL,
  `submitted_at` DATETIME NOT NULL,
  INDEX `idx_result_exam_score` (`exam_id`, `score`),
  CONSTRAINT `fk_results_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

