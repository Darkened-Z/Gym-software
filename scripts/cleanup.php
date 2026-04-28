<?php
/**
 * Cleanup Jobs Runner
 * Handles automated cleanup tasks for orphaned sessions, old logs, etc.
 * Run via cron: *\/5 * * * * php /path/to/cleanup.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Can be run from command line or web (for testing)
if (php_sapi_name() !== 'cli') {
    // Web access - require admin authentication
    session_start();
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        die('Unauthorized');
    }
    header('Content-Type: text/plain');
}

echo "========================================\n";
echo "Gym Management - Cleanup Jobs\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // ========================================================================
    // JOB 1: Close Orphaned Sessions
    // ========================================================================
    
    if (env('CLEANUP_ORPHANED_SESSIONS_ENABLED', 'true') === 'true') {
        echo "[JOB 1] Closing orphaned sessions...\n";
        
        $hours = (int)env('CLEANUP_ORPHANED_SESSIONS_HOURS', 24);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        echo "  Cutoff time: {$cutoff} ({$hours} hours ago)\n";
        
        $db->beginTransaction();
        
        try {
            // Process men's sessions
            $query = "SELECT id, member_id, check_in 
                      FROM attendance_men 
                      WHERE check_out IS NULL 
                      AND check_in < :cutoff";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $stmt->execute();
            $orphanedMen = $stmt->fetchAll();
            
            foreach ($orphanedMen as $session) {
                // Set check_out to end of day
                $checkInDate = date('Y-m-d', strtotime($session['check_in']));
                $checkOut = $checkInDate . ' 23:59:59';
                
                $checkInTime = new DateTime($session['check_in']);
                $checkOutTime = new DateTime($checkOut);
                $interval = $checkInTime->diff($checkOutTime);
                $duration = ($interval->h * 60) + $interval->i;
                
                $updateQuery = "UPDATE attendance_men 
                                SET check_out = :check_out,
                                    duration_minutes = :duration,
                                    exit_gate_id = 'AUTO_CLEANUP'
                                WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindValue(':check_out', $checkOut, PDO::PARAM_STR);
                $updateStmt->bindValue(':duration', $duration, PDO::PARAM_INT);
                $updateStmt->bindValue(':id', $session['id'], PDO::PARAM_INT);
                $updateStmt->execute();
                
                // Reset member check-in status
                $memberUpdate = "UPDATE members_men SET is_checked_in = 0 WHERE id = :id";
                $memberStmt = $db->prepare($memberUpdate);
                $memberStmt->bindValue(':id', $session['member_id'], PDO::PARAM_INT);
                $memberStmt->execute();
            }
            
            // Process women's sessions
            $query = "SELECT id, member_id, check_in 
                      FROM attendance_women 
                      WHERE check_out IS NULL 
                      AND check_in < :cutoff";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $stmt->execute();
            $orphanedWomen = $stmt->fetchAll();
            
            foreach ($orphanedWomen as $session) {
                $checkInDate = date('Y-m-d', strtotime($session['check_in']));
                $checkOut = $checkInDate . ' 23:59:59';
                
                $checkInTime = new DateTime($session['check_in']);
                $checkOutTime = new DateTime($checkOut);
                $interval = $checkInTime->diff($checkOutTime);
                $duration = ($interval->h * 60) + $interval->i;
                
                $updateQuery = "UPDATE attendance_women 
                                SET check_out = :check_out,
                                    duration_minutes = :duration,
                                    exit_gate_id = 'AUTO_CLEANUP'
                                WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindValue(':check_out', $checkOut, PDO::PARAM_STR);
                $updateStmt->bindValue(':duration', $duration, PDO::PARAM_INT);
                $updateStmt->bindValue(':id', $session['id'], PDO::PARAM_INT);
                $updateStmt->execute();
                
                $memberUpdate = "UPDATE members_women SET is_checked_in = 0 WHERE id = :id";
                $memberStmt = $db->prepare($memberUpdate);
                $memberStmt->bindValue(':id', $session['member_id'], PDO::PARAM_INT);
                $memberStmt->execute();
            }
            
            $total = count($orphanedMen) + count($orphanedWomen);
            echo "  ✓ Closed {$total} orphaned sessions\n";
            echo "    - Men: " . count($orphanedMen) . "\n";
            echo "    - Women: " . count($orphanedWomen) . "\n";
            
            $db->commit();
            
            // Update job status
            $updateJob = "INSERT INTO system_jobs (job_name, last_run, status, result)
                          VALUES ('cleanup_orphaned_sessions', NOW(), 'completed', :result)
                          ON DUPLICATE KEY UPDATE 
                          last_run = NOW(), status = 'completed', result = :result";
            $jobStmt = $db->prepare($updateJob);
            $jobStmt->bindValue(':result', "Closed {$total} sessions", PDO::PARAM_STR);
            $jobStmt->execute();
            
        } catch (Exception $e) {
            $db->rollBack();
            echo "  ✗ ERROR: " . $e->getMessage() . "\n";
            
            $updateJob = "UPDATE system_jobs SET last_run = NOW(), status = 'failed', result = :error
                          WHERE job_name = 'cleanup_orphaned_sessions'";
            $jobStmt = $db->prepare($updateJob);
            $jobStmt->bindValue(':error', $e->getMessage(), PDO::PARAM_STR);
            $jobStmt->execute();
        }
    } else {
        echo "[JOB 1] Skipped (disabled in config)\n";
    }
    
    echo "\n";
    
    // ========================================================================
    // JOB 2: Clean Old Gate Activity Logs
    // ========================================================================
    
    if (env('CLEANUP_OLD_LOGS_ENABLED', 'true') === 'true') {
        echo "[JOB 2] Cleaning old gate activity logs...\n";
        
        $retentionDays = (int)env('LOG_RETENTION_DAYS', 90);
        $cutoff = date('Y-m-d', strtotime("-{$retentionDays} days"));
        
        echo "  Retention: {$retentionDays} days (delete before {$cutoff})\n";
        
        $query = "DELETE FROM gate_activity_log WHERE DATE(created_at) < :cutoff";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->execute();
        $deleted = $stmt->rowCount();
        
        echo "  ✓ Deleted {$deleted} old log entries\n";
        
        // Update job status
        $updateJob = "INSERT INTO system_jobs (job_name, last_run, status, result)
                      VALUES ('cleanup_old_gate_activity', NOW(), 'completed', :result)
                      ON DUPLICATE KEY UPDATE 
                      last_run = NOW(), status = 'completed', result = :result";
        $jobStmt = $db->prepare($updateJob);
        $jobStmt->bindValue(':result', "Deleted {$deleted} entries", PDO::PARAM_STR);
        $jobStmt->execute();
    } else {
        echo "[JOB 2] Skipped (disabled in config)\n";
    }
    
    echo "\n";
    
    // ========================================================================
    // JOB 3: Clean Old Gate Cooldown Records
    // ========================================================================
    
    echo "[JOB 3] Cleaning old gate cooldown records...\n";
    
    // Delete cooldown records older than 1 hour (they're no longer relevant)
    $query = "DELETE FROM gate_cooldown 
              WHERE TIMESTAMPDIFF(HOUR, last_scan, NOW()) > 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    echo "  ✓ Deleted {$deleted} old cooldown records\n";
    
    echo "\n========================================\n";
    echo "All cleanup jobs completed\n";
    echo "Finished: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "\n[FATAL ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
