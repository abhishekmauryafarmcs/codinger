-- SQL Script to set up test_cases table
-- Execute this in phpMyAdmin

-- Create test_cases table linked to problems
CREATE TABLE IF NOT EXISTS `test_cases` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `problem_id` INT(11) NOT NULL,
    `input` TEXT NOT NULL,
    `expected_output` TEXT NOT NULL,
    `is_visible` TINYINT(1) DEFAULT 1 COMMENT 'Whether this test case is visible to students or hidden',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
);

-- Add indexes for better performance
ALTER TABLE `test_cases` ADD INDEX IF NOT EXISTS `idx_problem_id` (`problem_id`); 