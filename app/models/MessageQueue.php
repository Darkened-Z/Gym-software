<?php
/**
 * Message Queue Model
 */

class MessageQueue {
    private $conn;
    private $table = 'message_queue';
    private $logTable = 'message_logs';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create($data) {
        $query = "INSERT INTO {$this->table}
            (member_table, member_id, template_id, channel, recipient, message_purpose, payload_json, scheduled_for, status)
            VALUES
            (:member_table, :member_id, :template_id, :channel, :recipient, :message_purpose, :payload_json, :scheduled_for, :status)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_table', $data['member_table'], PDO::PARAM_STR);
        $stmt->bindValue(':member_id', $data['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':template_id', $data['template_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':channel', $data['channel'] ?? 'whatsapp', PDO::PARAM_STR);
        $stmt->bindValue(':recipient', $data['recipient'], PDO::PARAM_STR);
        $stmt->bindValue(':message_purpose', $data['message_purpose'], PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', $data['payload_json'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':scheduled_for', $data['scheduled_for'], PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'] ?? 'pending', PDO::PARAM_STR);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function getPending($limit = 50) {
        $query = "SELECT * FROM {$this->table}
                  WHERE status = 'pending' AND scheduled_for <= :current_time
                  ORDER BY scheduled_for ASC
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':current_time', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status, $failureReason = null) {
        $query = "UPDATE {$this->table}
                  SET status = :status,
                      attempt_count = attempt_count + 1,
                      last_attempt_at = NOW(),
                      sent_at = CASE WHEN :sent_status = 'sent' THEN NOW() ELSE sent_at END,
                      failure_reason = :failure_reason
                  WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':sent_status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':failure_reason', $failureReason, PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function hasRecentSimilarMessage($memberTable, $memberId, $purpose, $cooldownHours = 48) {
        $query = "SELECT id FROM {$this->table}
                  WHERE member_table = :member_table
                    AND member_id = :member_id
                    AND message_purpose = :message_purpose
                    AND created_at >= DATE_SUB(NOW(), INTERVAL :cooldown HOUR)
                    AND status IN ('pending', 'processing', 'sent')
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_table', $memberTable, PDO::PARAM_STR);
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':message_purpose', $purpose, PDO::PARAM_STR);
        $stmt->bindValue(':cooldown', (int)$cooldownHours, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getStats() {
        $query = "SELECT
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                    COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                    COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count
                  FROM {$this->table}";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'pending_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ];

        return [
            'pending_count' => (int)($stats['pending_count'] ?? 0),
            'sent_count' => (int)($stats['sent_count'] ?? 0),
            'failed_count' => (int)($stats['failed_count'] ?? 0),
        ];
    }
}
