-- Add reason and is_permanent columns to contest_exits table if they don't exist
ALTER TABLE contest_exits 
ADD COLUMN IF NOT EXISTS reason VARCHAR(50) DEFAULT 'page_switch_violations',
ADD COLUMN IF NOT EXISTS is_permanent TINYINT(1) DEFAULT 0;

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_contest_exits_user_contest ON contest_exits (user_id, contest_id);

-- Update any existing records to have a reason (not permanent by default)
UPDATE contest_exits SET reason = 'manual_exit' WHERE reason IS NULL; 