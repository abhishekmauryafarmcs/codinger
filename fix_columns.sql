-- Drop and recreate the test_cases table with the correct structure
DROP TABLE IF EXISTS test_cases_backup;

-- Create backup of current table structure
CREATE TABLE test_cases_backup LIKE test_cases;
INSERT INTO test_cases_backup SELECT * FROM test_cases;

-- Drop the current table
DROP TABLE test_cases;

-- Create the table with the correct structure
CREATE TABLE `test_cases` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `problem_id` INT(11) NOT NULL,
    `input` TEXT NOT NULL,
    `expected_output` TEXT NOT NULL,
    `is_visible` TINYINT(1) DEFAULT 1 COMMENT 'Whether this test case is visible to students or hidden',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
);

-- Restore data from backup with appropriate column mapping
-- Replace 'old_input_column_name' and 'old_expected_output_column_name' with the actual column names from your backup
INSERT INTO test_cases (problem_id, input, expected_output, is_visible, created_at)
SELECT problem_id, 
       test_input AS input, 
       test_output AS expected_output, 
       is_visible, 
       created_at 
FROM test_cases_backup;

-- Add index for better performance
ALTER TABLE `test_cases` ADD INDEX `idx_problem_id` (`problem_id`); 