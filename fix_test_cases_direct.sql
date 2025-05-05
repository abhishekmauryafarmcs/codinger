-- SQL Script to directly fix test_cases table
-- Execute this in phpMyAdmin

-- Optional: Check column names first
-- SHOW COLUMNS FROM `test_cases`;

-- Method 1: Add missing columns if they don't exist
ALTER TABLE `test_cases` 
ADD COLUMN IF NOT EXISTS `input` TEXT AFTER `problem_id`,
ADD COLUMN IF NOT EXISTS `expected_output` TEXT AFTER `input`;

-- Method 2: If columns have wrong names (like test_input instead of input), this will rename them
-- Uncomment and modify based on what column names you actually have
-- ALTER TABLE `test_cases` CHANGE `test_input` `input` TEXT;
-- ALTER TABLE `test_cases` CHANGE `test_output` `expected_output` TEXT;

-- Method 3: Create a new table with correct schema and copy data (most thorough approach)
-- Create temporary backup table
CREATE TABLE IF NOT EXISTS `test_cases_new` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `problem_id` INT(11) NOT NULL,
    `input` TEXT NOT NULL,
    `expected_output` TEXT NOT NULL,
    `is_visible` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`problem_id`) REFERENCES `problems`(`id`) ON DELETE CASCADE
);

-- Copy data from old table to new table (adjust column names based on your actual schema)
-- This inserts using column mapping - modify field names based on your actual column names
INSERT INTO `test_cases_new` (`problem_id`, `input`, `expected_output`, `is_visible`, `created_at`)
SELECT 
    `problem_id`, 
    -- If input column exists, use it; otherwise use test_input (adjust as needed)
    COALESCE(`input`, `test_input`) AS input_data,
    -- If expected_output column exists, use it; otherwise use test_output (adjust as needed)
    COALESCE(`expected_output`, `test_output`) AS output_data,
    `is_visible`,
    COALESCE(`created_at`, NOW()) AS timestamp
FROM `test_cases`;

-- Drop old table and rename new one (IMPORTANT: Only uncomment after verifying the new table has correct data)
-- DROP TABLE `test_cases`;
-- RENAME TABLE `test_cases_new` TO `test_cases`;

-- Add index for performance
ALTER TABLE `test_cases_new` ADD INDEX `idx_problem_id` (`problem_id`);

-- Show what's in the new table to verify the migration worked
-- SELECT * FROM `test_cases_new` LIMIT 5;

-- NOTE: If you've confirmed the new table is good, you can run these commands:
-- DROP TABLE `test_cases`;
-- RENAME TABLE `test_cases_new` TO `test_cases`; 