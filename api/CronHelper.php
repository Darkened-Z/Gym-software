<?php
/**
 * Cron Logic Helper to automate tasks
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';

class CronHelper {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->ensureJobsTable();
    }

    private function ensureJobsTable() {
        // Create table if not exists
        $query = "CREATE TABLE IF NOT EXISTS system_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(50) UNIQUE NOT NULL,
            last_run DATETIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'idle'
        )";
        $this->db->exec($query);
    }

    public function runDailyAutoArchive() {
        $jobName = 'auto_archive_inactive';
        
        // Check if run today
        $query = "SELECT last_run FROM system_jobs WHERE job_name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $jobName);
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        $shouldRun = false;
        if (!$job) {
            $shouldRun = true;
            // Insert initial record
            $ins = $this->db->prepare("INSERT INTO system_jobs (job_name, status) VALUES (:name, 'running')");
            $ins->bindValue(':name', $jobName);
            $ins->execute();
        } else {
            $lastRun = $job['last_run'];
            if (!$lastRun || date('Y-m-d', strtotime($lastRun)) < date('Y-m-d')) {
                $shouldRun = true;
            }
        }

        if ($shouldRun) {
            $this->performArchive();
            
            // Update last run
            $upd = $this->db->prepare("UPDATE system_jobs SET last_run = NOW(), status = 'idle' WHERE job_name = :name");
            $upd->bindValue(':name', $jobName);
            $upd->execute();
            
            return true; // Ran successfully
        }

        return false; // Already ran today
    }

    private function performArchive() {
        foreach (['men', 'women'] as $gender) {
            $member = new Member($this->db, $gender);
            $member->syncAllActivityStatuses();
        }
    }
}
