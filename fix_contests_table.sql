-- SQL Script to fix the contests table structure
-- Execute this in phpMyAdmin

-- Check if the columns already exist
SHOW COLUMNS FROM `contests` LIKE 'allowed_tab_switches';
SHOW COLUMNS FROM `contests` LIKE 'prevent_copy_paste';

-- Add the missing columns if they don't exist
ALTER TABLE `contests` 
ADD COLUMN IF NOT EXISTS `allowed_tab_switches` INT(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `prevent_copy_paste` TINYINT(1) DEFAULT 0;

-- Verify the table structure after modification
SHOW COLUMNS FROM `contests`;

-- Note: This script adds the 'allowed_tab_switches' and 'prevent_copy_paste' columns
-- that are expected by the create_contest.php script when inserting new contests. 