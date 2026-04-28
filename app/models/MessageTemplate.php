<?php
/**
 * Message Template Model
 */

class MessageTemplate {
    private $conn;
    private $table = 'message_templates';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll($channel = 'whatsapp', $activeOnly = true) {
        $query = "SELECT * FROM {$this->table} WHERE channel = :channel";
        if ($activeOnly) {
            $query .= " AND is_active = 1";
        }
        $query .= " ORDER BY template_name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByKey($templateKey, $channel = 'whatsapp') {
        $query = "SELECT * FROM {$this->table} WHERE template_key = :template_key AND channel = :channel LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':template_key', $templateKey, PDO::PARAM_STR);
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function render(array $template, array $variables = []) {
        $body = $template['body'] ?? '';
        foreach ($variables as $key => $value) {
            $body = str_replace('{{' . $key . '}}', (string)$value, $body);
        }
        return $body;
    }
}
