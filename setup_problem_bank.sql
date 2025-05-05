-- SQL Script to set up all required columns for the problem bank
-- Execute this in phpMyAdmin if the update_database.php script doesn't work

-- Add all required columns to the problems table
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `difficulty` ENUM('easy', 'medium', 'hard') DEFAULT 'medium';
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `problem_type` VARCHAR(50) DEFAULT 'algorithm';
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `time_limit` INT(11) DEFAULT 1;
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `memory_limit` INT(11) DEFAULT 256;

-- Add indexes for better performance
ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_category` (`category`);
ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_difficulty` (`difficulty`);
ALTER TABLE `problems` ADD INDEX IF NOT EXISTS `idx_problem_type` (`problem_type`);

-- Update existing problems to have reasonable defaults if any exist
UPDATE `problems` SET 
    `difficulty` = 'medium',
    `problem_type` = 'algorithm',
    `time_limit` = 1,
    `memory_limit` = 256
WHERE `difficulty` IS NULL; 