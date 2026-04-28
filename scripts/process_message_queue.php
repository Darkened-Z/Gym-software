<?php
/**
 * Queue processor skeleton for WhatsApp reminders
 * Replace the simulated send block with your real provider integration.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/MessageTemplate.php';
require_once __DIR__ . '/../app/models/MessageQueue.php';

$database = new Database();
$db = $database->getConnection();
$templateModel = new MessageTemplate($db);
$queueModel = new MessageQueue($db);

$pending = $queueModel->getPending(50);

foreach ($pending as $row) {
    $payload = json_decode($row['payload_json'] ?? '{}', true) ?: [];

    $templateQuery = $db->prepare('SELECT * FROM message_templates WHERE id = :id LIMIT 1');
    $templateQuery->bindValue(':id', $row['template_id'], PDO::PARAM_INT);
    $templateQuery->execute();
    $template = $templateQuery->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        $queueModel->updateStatus((int)$row['id'], 'failed', 'Template not found');
        continue;
    }

    $message = $templateModel->render($template, $payload);

    try {
        // TODO: Replace this simulated block with your actual WhatsApp provider send logic.
        $providerMessageId = 'simulated-' . $row['id'] . '-' . time();

        $logStmt = $db->prepare("INSERT INTO message_logs
            (queue_id, member_table, member_id, channel, recipient, message_purpose, rendered_message, provider_message_id, delivery_status, provider_response, sent_at)
            VALUES
            (:queue_id, :member_table, :member_id, :channel, :recipient, :message_purpose, :rendered_message, :provider_message_id, 'sent', :provider_response, NOW())");
        $logStmt->execute([
            ':queue_id' => $row['id'],
            ':member_table' => $row['member_table'],
            ':member_id' => $row['member_id'],
            ':channel' => $row['channel'],
            ':recipient' => $row['recipient'],
            ':message_purpose' => $row['message_purpose'],
            ':rendered_message' => $message,
            ':provider_message_id' => $providerMessageId,
            ':provider_response' => json_encode(['status' => 'simulated_sent'])
        ]);

        $queueModel->updateStatus((int)$row['id'], 'sent');
        echo "Sent queue #{$row['id']} to {$row['recipient']}\n";
    } catch (Throwable $e) {
        $queueModel->updateStatus((int)$row['id'], 'failed', $e->getMessage());
        error_log('Queue processor error: ' . $e->getMessage());
    }
}
