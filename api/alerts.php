<?php
/**
 * Real-time access alerts API (admin/staff, session-authed).
 *
 * The F22/iclock path records an access_alerts row whenever an unpaid /
 * overdue member scans in. The dashboard polls this endpoint to show a live
 * feed + fire browser notifications on the front-desk laptop and the owner's
 * phone.
 *
 *   GET  ?action=recent   -> alerts in the last 24h (newest first) + unseen count
 *   POST ?action=mark_seen -> mark alerts seen (stops the notification re-firing)
 *   GET  ?action=stats    -> this-month totals + repeat offenders
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

header('Content-Type: application/json');
AuthHelper::requireAdminOrStaff();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'recent';

function alerts_table_exists(PDO $db): bool {
    try {
        $s = $db->query("SHOW TABLES LIKE 'access_alerts'");
        return $s && $s->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $db = (new Database())->getConnection();

    if (!alerts_table_exists($db)) {
        // Nothing has ever alerted yet — return an empty-but-valid shape.
        echo json_encode(['success' => true, 'unseen' => 0, 'alerts' => [], 'stats' => null]);
        exit;
    }

    switch ($action) {
        case 'recent': {
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]) ?: 50;
            $stmt = $db->prepare(
                "SELECT id, member_id, gender, member_code, name, phone, due_date, days_overdue,
                        due_amount, entered_at, seen
                 FROM access_alerts
                 WHERE entered_at >= NOW() - INTERVAL 24 HOUR
                 ORDER BY entered_at DESC
                 LIMIT {$limit}"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $unseen = 0;
            foreach ($rows as $r) if ((int)$r['seen'] === 0) $unseen++;

            echo json_encode(['success' => true, 'unseen' => $unseen, 'alerts' => $rows]);
            break;
        }

        case 'mark_seen': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'POST only']); break; }
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $ids = $data['ids'] ?? null;
            if (is_array($ids) && $ids) {
                $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $db->prepare("UPDATE access_alerts SET seen = 1 WHERE id IN ({$ph})");
                    $stmt->execute($ids);
                }
            } else {
                // mark all recent seen
                $db->exec("UPDATE access_alerts SET seen = 1 WHERE seen = 0 AND entered_at >= NOW() - INTERVAL 24 HOUR");
            }
            echo json_encode(['success' => true]);
            break;
        }

        case 'stats': {
            $monthStart = date('Y-m-01');
            $total = (int)$db->query("SELECT COUNT(*) FROM access_alerts WHERE entered_at >= '{$monthStart}'")->fetchColumn();
            $stmt = $db->query(
                "SELECT name, member_code, gender, COUNT(*) AS entries, MAX(days_overdue) AS days_overdue,
                        MAX(due_amount) AS due_amount
                 FROM access_alerts
                 WHERE entered_at >= '{$monthStart}'
                 GROUP BY member_id, gender, name, member_code
                 ORDER BY entries DESC
                 LIMIT 10"
            );
            $offenders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            echo json_encode(['success' => true, 'month_total' => $total, 'repeat_offenders' => $offenders]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('alerts.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
