-- SQL Script to fix test_cases column names
-- Execute this in phpMyAdmin

-- First, check if the table exists
SELECT COUNT(*) AS table_exists FROM information_schema.tables 
WHERE table_schema = DATABASE() AND table_name = 'test_cases';

-- Check column names (these queries will tell you what columns actually exist)
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_cases';

-- Backup table just in case
CREATE TABLE IF NOT EXISTS `test_cases_backup` LIKE `test_cases`;
INSERT INTO `test_cases_backup` SELECT * FROM `test_cases`;

-- Now try to add the correct columns if they don't exist
ALTER TABLE `test_cases` ADD COLUMN IF NOT EXISTS `input` TEXT;
ALTER TABLE `test_cases` ADD COLUMN IF NOT EXISTS `expected_output` TEXT;

-- Add migration code if different column names exist
-- If the old columns were 'test_input' and 'test_output' for example:
UPDATE `test_cases` SET `input` = `test_input` WHERE `input` IS NULL AND `test_input` IS NOT NULL;
UPDATE `test_cases` SET `expected_output` = `test_output` WHERE `expected_output` IS NULL AND `test_output` IS NOT NULL;

-- Make sure columns are not null
ALTER TABLE `test_cases` MODIFY `input` TEXT NOT NULL;
ALTER TABLE `test_cases` MODIFY `expected_output` TEXT NOT NULL; 