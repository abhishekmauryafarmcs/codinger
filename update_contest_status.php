<?php
/**
 * Codinger Contest Status Updater
 * 
 * This script updates the status of contests based on their start and end times.
 * It should be run periodically via a cron job to ensure that contest statuses
 * are always accurate.
 * 
 * Example cron entry (runs every 5 minutes):
 * 5-59/5 * * * * php /path/to/codinger/update_contest_status.php
 */

require_once 'config/db.php';

// Set to true to enable detailed output
$verbose = isset($argv[1]) && $argv[1] === '--verbose';

// Function to log messages
function log_message($message, $is_error = false) {
    global $verbose;
    
    if ($verbose || $is_error) {
        echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
    }
    
    // Also log to error log for tracking
    if ($is_error) {
        error_log('Contest Status Updater Error: ' . $message);
    }
}

// Get current time
$now = date('Y-m-d H:i:s');
log_message("Starting contest status update at $now");

try {
    // Update upcoming contests to ongoing
    $stmt = $conn->prepare("
        UPDATE contests 
        SET status = 'ongoing' 
        WHERE status = 'upcoming' 
        AND start_time <= ?
    ");
    $stmt->bind_param("s", $now);
    $stmt->execute();
    $upcoming_updated = $stmt->affected_rows;
    $stmt->close();
    
    // Update ongoing contests to completed
    $stmt = $conn->prepare("
        UPDATE contests 
        SET status = 'completed' 
        WHERE status = 'ongoing' 
        AND end_time <= ?
    ");
    $stmt->bind_param("s", $now);
    $stmt->execute();
    $ongoing_updated = $stmt->affected_rows;
    $stmt->close();
    
    log_message("Updated $upcoming_updated upcoming contests to ongoing");
    log_message("Updated $ongoing_updated ongoing contests to completed");
    
    // Get list of active contests for verification
    $stmt = $conn->prepare("
        SELECT id, title, start_time, end_time, status
        FROM contests
        WHERE status = 'ongoing'
        ORDER BY end_time ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $active_contests = $result->num_rows;
    $stmt->close();
    
    log_message("Currently $active_contests active contests:");
    
    if ($verbose && $active_contests > 0) {
        while ($row = $result->fetch_assoc()) {
            $remaining = (strtotime($row['end_time']) - time()) / 60; // minutes remaining
            log_message("  - ID {$row['id']}: {$row['title']} (ends in " . round($remaining) . " minutes)");
        }
    }
    
    // Check for any contests with inconsistent dates
    $stmt = $conn->prepare("
        SELECT id, title, start_time, end_time, status
        FROM contests
        WHERE 
            (status = 'upcoming' AND start_time < ?) OR
            (status = 'ongoing' AND end_time < ?) OR
            (status = 'completed' AND end_time > ?)
    ");
    $stmt->bind_param("sss", $now, $now, $now);
    $stmt->execute();
    $result = $stmt->get_result();
    $inconsistent = $result->num_rows;
    
    if ($inconsistent > 0) {
        log_message("WARNING: Found $inconsistent contests with inconsistent status:", true);
        while ($row = $result->fetch_assoc()) {
            log_message("  - ID {$row['id']}: {$row['title']} (Status: {$row['status']}, Start: {$row['start_time']}, End: {$row['end_time']})", true);
        }
        
        // Try to fix inconsistent statuses
        $stmt = $conn->prepare("
            UPDATE contests 
            SET status = CASE
                WHEN end_time <= ? THEN 'completed'
                WHEN start_time <= ? THEN 'ongoing'
                ELSE 'upcoming'
            END
        ");
        $stmt->bind_param("ss", $now, $now);
        $stmt->execute();
        $fixed = $stmt->affected_rows;
        log_message("Fixed $fixed contest statuses", $fixed > 0);
    } else {
        log_message("All contest statuses are consistent");
    }
    
    log_message("Contest status update completed successfully");
    
} catch (Exception $e) {
    log_message("Error updating contest statuses: " . $e->getMessage(), true);
}

// Close database connection
$conn->close(); 