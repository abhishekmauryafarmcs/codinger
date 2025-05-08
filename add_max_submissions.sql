-- SQL Script to add max_submissions column to contests table
-- Execute this in phpMyAdmin

-- Add the column if it doesn't exist
ALTER TABLE `contests` 
ADD COLUMN IF NOT EXISTS `max_submissions` INT(11) DEFAULT 0 
COMMENT 'Maximum number of submissions allowed per problem (0 = unlimited)';

-- Verify the column was added
SHOW COLUMNS FROM `contests` LIKE 'max_submissions'; 