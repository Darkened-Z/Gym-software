<?php
/**
 * MemberFitness Model — stores AI coach profiles, progress measurements,
 * workout logs, and meal logs for members (men & women).
 * Self-healing table creation on instantiation.
 */

class MemberFitness {
    private $conn;
    private $table = 'member_fitness';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    gender ENUM('men', 'women') NOT NULL,
                    member_id INT NOT NULL,
                    height DECIMAL(5, 2) NULL,
                    goal ENUM('lose', 'gain', 'maintain') NOT NULL DEFAULT 'maintain',
                    target_weight DECIMAL(5, 2) NULL,
                    goal_notes TEXT NULL,
                    vegetarian TINYINT(1) NOT NULL DEFAULT 0,
                    allergies TEXT NULL,
                    injuries TEXT NULL,
                    experience ENUM('novice', 'intermediate', 'advanced') NOT NULL DEFAULT 'intermediate',
                    progress_json LONGTEXT NULL,
                    logs_json LONGTEXT NULL,
                    meals_json LONGTEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_member (gender, member_id),
                    INDEX idx_gender_member (gender, member_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            error_log('MemberFitness::ensureSchema: ' . $e->getMessage());
        }
    }

    public function getByMember(string $gender, int $memberId): array {
        $gender = $gender === 'women' ? 'women' : 'men';
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM `{$this->table}` WHERE gender = :gender AND member_id = :member_id LIMIT 1"
            );
            $stmt->execute([':gender' => $gender, ':member_id' => $memberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $row['progress'] = json_decode($row['progress_json'] ?? '[]', true) ?: [];
                $row['session_logs'] = json_decode($row['logs_json'] ?? '[]', true) ?: [];
                $row['meal_logs'] = json_decode($row['meals_json'] ?? '[]', true) ?: [];
                $row['vegetarian'] = (bool)$row['vegetarian'];
                return $row;
            }
        } catch (Exception $e) {
            error_log('MemberFitness::getByMember: ' . $e->getMessage());
        }

        // Return baseline defaults if no record exists yet
        return [
            'gender' => $gender,
            'member_id' => $memberId,
            'height' => 175.0,
            'goal' => 'maintain',
            'target_weight' => null,
            'goal_notes' => '',
            'vegetarian' => false,
            'allergies' => '',
            'injuries' => '',
            'experience' => 'intermediate',
            'progress' => [],
            'session_logs' => [],
            'meal_logs' => []
        ];
    }

    public function save(string $gender, int $memberId, array $data): bool {
        $gender = $gender === 'women' ? 'women' : 'men';
        $current = $this->getByMember($gender, $memberId);

        $height = array_key_exists('height', $data)
            ? (is_numeric($data['height']) ? (float)$data['height'] : null)
            : ($current['height'] ?? 175.0);
        $goal = isset($data['goal']) && in_array($data['goal'], ['lose', 'gain', 'maintain'], true)
            ? $data['goal']
            : ($current['goal'] ?? 'maintain');
        $targetWeight = array_key_exists('target_weight', $data)
            ? (is_numeric($data['target_weight']) ? (float)$data['target_weight'] : null)
            : ($current['target_weight'] ?? null);
        $goalNotes = array_key_exists('goal_notes', $data)
            ? trim((string)$data['goal_notes'])
            : (string)($current['goal_notes'] ?? '');
        $vegetarian = array_key_exists('vegetarian', $data)
            ? (!empty($data['vegetarian']) ? 1 : 0)
            : (!empty($current['vegetarian']) ? 1 : 0);
        $allergies = array_key_exists('allergies', $data)
            ? (is_array($data['allergies']) ? implode(', ', $data['allergies']) : trim((string)$data['allergies']))
            : (string)($current['allergies'] ?? '');
        $injuries = array_key_exists('injuries', $data)
            ? (is_array($data['injuries']) ? implode(', ', $data['injuries']) : trim((string)$data['injuries']))
            : (string)($current['injuries'] ?? '');
        $experience = isset($data['experience']) && in_array($data['experience'], ['novice', 'intermediate', 'advanced'], true)
            ? $data['experience']
            : ($current['experience'] ?? 'intermediate');

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO `{$this->table}`
                    (gender, member_id, height, goal, target_weight, goal_notes, vegetarian, allergies, injuries, experience)
                 VALUES
                    (:gender, :member_id, :height, :goal, :target_weight, :goal_notes, :vegetarian, :allergies, :injuries, :experience)
                 ON DUPLICATE KEY UPDATE
                    height = VALUES(height),
                    goal = VALUES(goal),
                    target_weight = VALUES(target_weight),
                    goal_notes = VALUES(goal_notes),
                    vegetarian = VALUES(vegetarian),
                    allergies = VALUES(allergies),
                    injuries = VALUES(injuries),
                    experience = VALUES(experience)"
            );
            return $stmt->execute([
                ':gender' => $gender,
                ':member_id' => $memberId,
                ':height' => $height,
                ':goal' => $goal,
                ':target_weight' => $targetWeight,
                ':goal_notes' => $goalNotes,
                ':vegetarian' => $vegetarian,
                ':allergies' => $allergies,
                ':injuries' => $injuries,
                ':experience' => $experience,
            ]);
        } catch (Exception $e) {
            error_log('MemberFitness::save: ' . $e->getMessage());
            return false;
        }
    }

    public function addProgress(string $gender, int $memberId, array $entry): bool {
        $current = $this->getByMember($gender, $memberId);
        $progress = $current['progress'] ?? [];
        $entry['date'] = $entry['date'] ?? date('Y-m-d');
        $progress[] = $entry;

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO `{$this->table}`
                    (gender, member_id, progress_json)
                 VALUES
                    (:gender, :member_id, :json)
                 ON DUPLICATE KEY UPDATE
                    progress_json = VALUES(progress_json)"
            );
            return $stmt->execute([
                ':gender' => $gender,
                ':member_id' => $memberId,
                ':json' => json_encode($progress)
            ]);
        } catch (Exception $e) {
            error_log('MemberFitness::addProgress: ' . $e->getMessage());
            return false;
        }
    }

    public function addSessionLog(string $gender, int $memberId, array $log): bool {
        $current = $this->getByMember($gender, $memberId);
        $logs = $current['session_logs'] ?? [];
        $log['date'] = $log['date'] ?? date('Y-m-d');
        $logs[] = $log;

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO `{$this->table}`
                    (gender, member_id, logs_json)
                 VALUES
                    (:gender, :member_id, :json)
                 ON DUPLICATE KEY UPDATE
                    logs_json = VALUES(logs_json)"
            );
            return $stmt->execute([
                ':gender' => $gender,
                ':member_id' => $memberId,
                ':json' => json_encode($logs)
            ]);
        } catch (Exception $e) {
            error_log('MemberFitness::addSessionLog: ' . $e->getMessage());
            return false;
        }
    }

    public function addMealLog(string $gender, int $memberId, array $meal): bool {
        $current = $this->getByMember($gender, $memberId);
        $meals = $current['meal_logs'] ?? [];
        $meal['date'] = $meal['date'] ?? date('Y-m-d');
        $meals[] = $meal;

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO `{$this->table}`
                    (gender, member_id, meals_json)
                 VALUES
                    (:gender, :member_id, :json)
                 ON DUPLICATE KEY UPDATE
                    meals_json = VALUES(meals_json)"
            );
            return $stmt->execute([
                ':gender' => $gender,
                ':member_id' => $memberId,
                ':json' => json_encode($meals)
            ]);
        } catch (Exception $e) {
            error_log('MemberFitness::addMealLog: ' . $e->getMessage());
            return false;
        }
    }
}
