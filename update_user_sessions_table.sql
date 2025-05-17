-- SQL script to update the user_sessions table schema to allow multiple roles with the same session ID

-- First, drop the existing unique key constraint on session_id
ALTER TABLE `user_sessions` DROP INDEX `session_id`;

-- Add a new unique constraint on the combination of user_id, role, and session_id
-- This ensures each role can have its own session entry for the same session ID
ALTER TABLE `user_sessions` ADD UNIQUE KEY `unique_user_session` (`user_id`, `role`, `session_id`);

-- Update foreign key constraint for admin users
ALTER TABLE `user_sessions` DROP FOREIGN KEY `user_sessions_ibfk_1`;
ALTER TABLE `user_sessions` ADD CONSTRAINT `user_sessions_ibfk_student` 
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
WHERE `role` = 'student';

-- Make sure auto_increment is set correctly
ALTER TABLE `user_sessions` MODIFY `id` INT(11) AUTO_INCREMENT; 