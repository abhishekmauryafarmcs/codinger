-- Add created_at column to problems table if it doesn't exist
ALTER TABLE `problems` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP; 