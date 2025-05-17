-- SQL Script to add results_published column to contests table
-- Execute this in phpMyAdmin or any MySQL client

-- Add the column if it doesn't exist
ALTER TABLE `contests` 
ADD COLUMN IF NOT EXISTS `results_published` TINYINT(1) DEFAULT 0 
COMMENT 'Whether contest results are published for students to view';

-- Verify the column was added
SHOW COLUMNS FROM `contests` LIKE 'results_published'; 