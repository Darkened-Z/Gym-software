<?php
/**
 * Reports API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Attendance.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

// Export action streams CSV — skip JSON content-type until we know the action
$action = $_GET['action'] ?? '';
if ($action !== 'export') {
    header('Content-Type: application/json');
}

AuthHelper::requireAdminOrStaff();

try {
    $database = new Database();
    $db = $database->getConnection();
    $range = $_GET['range'] ?? '30d';

    $daysByRange = [
        '7d' => 7,
        '30d' => 30,
        '3m' => 90,
        '6m' => 180,
        '12m' => 365,
    ];
    $rangeDays = (int)($daysByRange[$range] ?? 30);

    switch ($action) {
        case 'members':
            $memberMen = new Member($db, 'men');
            $memberWomen = new Member($db, 'women');

            $statsMen = $memberMen->getStats();
            $statsWomen = $memberWomen->getStats();
            $opsMen = $memberMen->getOperationalSnapshot();
            $opsWomen = $memberWomen->getOperationalSnapshot();

            $memberTrendQuery = "SELECT label, SUM(total) AS total FROM (
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM members_men GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM members_women GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ) t GROUP BY label ORDER BY label ASC";
            $memberTrendStmt = $db->prepare($memberTrendQuery);
            $memberTrendStmt->execute();
            $memberTrend = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (int)($row['total'] ?? 0)];
            }, $memberTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            echo json_encode([
                'success' => true,
                'data' => [
                    'men' => $statsMen,
                    'women' => $statsWomen,
                    'operations' => [
                        'checked_in_now' => ($opsMen['checked_in_now'] ?? 0) + ($opsWomen['checked_in_now'] ?? 0),
                        'due_today' => ($opsMen['due_today'] ?? 0) + ($opsWomen['due_today'] ?? 0),
                        'overdue' => ($opsMen['overdue'] ?? 0) + ($opsWomen['overdue'] ?? 0),
                        'new_this_month' => ($opsMen['new_this_month'] ?? 0) + ($opsWomen['new_this_month'] ?? 0),
                        'active_due_amount' => ($opsMen['total_active_due'] ?? 0) + ($opsWomen['total_active_due'] ?? 0)
                    ],
                    'charts' => [
                        'gender_split' => [
                            ['label' => 'Men', 'total' => (int)($statsMen['total'] ?? 0)],
                            ['label' => 'Women', 'total' => (int)($statsWomen['total'] ?? 0)]
                        ],
                        'active_split' => [
                            ['label' => 'Men Active', 'total' => (int)($statsMen['active'] ?? 0)],
                            ['label' => 'Women Active', 'total' => (int)($statsWomen['active'] ?? 0)],
                            ['label' => 'Men Inactive', 'total' => (int)($statsMen['inactive'] ?? 0)],
                            ['label' => 'Women Inactive', 'total' => (int)($statsWomen['inactive'] ?? 0)]
                        ],
                        'monthly_growth' => $memberTrend
                    ]
                ]
            ]);
            break;

        case 'defaulters':
            foreach (['men', 'women'] as $syncGender) {
                (new Member($db, $syncGender))->syncAllActivityStatuses();
            }

            $memberSources = [
                ['table' => 'members_men', 'gender' => 'men'],
                ['table' => 'members_women', 'gender' => 'women']
            ];

            $unionParts = [];
            foreach ($memberSources as $source) {
                $dateColumn = resolve_member_date_column($db, $source['table']);
                $unionParts[] = "SELECT id, member_code, name, phone, status, total_due_amount, next_fee_due_date, {$dateColumn} AS join_date, '{$source['gender']}' AS gender
                                 FROM {$source['table']}
                                 WHERE status = 'active'
                                   AND ((next_fee_due_date IS NOT NULL AND next_fee_due_date < CURDATE())
                                        OR COALESCE(total_due_amount, 0) > 0)";
            }

            $query = "SELECT * FROM (" . implode(' UNION ALL ', $unionParts) . ") d
                      ORDER BY (next_fee_due_date IS NULL) ASC, next_fee_due_date ASC, total_due_amount DESC, name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $defaulters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $todayTs = strtotime(date('Y-m-d'));
            $overdueCount = 0;
            $outstandingDuesCount = 0;
            $totalOutstanding = 0.0;
            $genderCounts = ['Men' => 0, 'Women' => 0];

            foreach ($defaulters as &$defaulter) {
                $dueAmount = (float)($defaulter['total_due_amount'] ?? 0);
                $defaulter['total_due_amount'] = $dueAmount;
                $totalOutstanding += $dueAmount;
                if ($dueAmount > 0) {
                    $outstandingDuesCount++;
                }

                $genderKey = ($defaulter['gender'] ?? '') === 'women' ? 'Women' : 'Men';
                $genderCounts[$genderKey]++;

                $daysOverdue = 0;
                if (!empty($defaulter['next_fee_due_date'])) {
                    $dueTs = strtotime($defaulter['next_fee_due_date']);
                    if ($dueTs !== false && $dueTs < $todayTs) {
                        $daysOverdue = (int)floor(($todayTs - $dueTs) / 86400);
                        $overdueCount++;
                    }
                }
                $defaulter['days_overdue'] = $daysOverdue;
            }
            unset($defaulter);

            $topDefaulters = array_slice(array_map(static function ($row) {
                return [
                    'label' => $row['name'] . ' (' . $row['member_code'] . ')',
                    'total' => (float)($row['total_due_amount'] ?? 0),
                ];
            }, $defaulters), 0, 10);

            $duesTrendStmt = $db->prepare("SELECT label, SUM(total_due_amount) AS total FROM (
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COALESCE(SUM(total_due_amount), 0) AS total_due_amount FROM members_men GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COALESCE(SUM(total_due_amount), 0) AS total_due_amount FROM members_women GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ) dues_trend GROUP BY label ORDER BY label ASC");
            $duesTrendStmt->execute();
            $duesTrend = array_map(static function ($row) {
                return [
                    'label' => $row['label'],
                    'total' => (float)($row['total'] ?? 0),
                ];
            }, $duesTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $overdueBands = [
                ['label' => '1-7 days', 'total' => 0],
                ['label' => '8-30 days', 'total' => 0],
                ['label' => '31-60 days', 'total' => 0],
                ['label' => '60+ days', 'total' => 0],
            ];
            foreach ($defaulters as $row) {
                $days = (int)($row['days_overdue'] ?? 0);
                if ($days >= 1 && $days <= 7) $overdueBands[0]['total']++;
                elseif ($days <= 30) $overdueBands[1]['total']++;
                elseif ($days <= 60) $overdueBands[2]['total']++;
                elseif ($days > 60) $overdueBands[3]['total']++;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'defaulters' => $defaulters,
                    'total_count' => count($defaulters),
                    'overdue_count' => $overdueCount,
                    'outstanding_dues_count' => $outstandingDuesCount,
                    'total_outstanding_amount' => $totalOutstanding,
                    'charts' => [
                        'gender_split' => [
                            ['label' => 'Men', 'total' => $genderCounts['Men']],
                            ['label' => 'Women', 'total' => $genderCounts['Women']]
                        ],
                        'top_defaulters' => $topDefaulters,
                        'overdue_bands' => $overdueBands,
                        'dues_trend' => $duesTrend
                    ]
                ]
            ]);
            break;

        case 'payments':
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');

            $query = "SELECT
                        COUNT(*) as total_payments,
                        COALESCE(SUM(amount), 0) as total_revenue,
                        COALESCE(AVG(amount), 0) as avg_payment,
                        SUM(CASE WHEN payment_date = CURDATE() THEN amount ELSE 0 END) as revenue_today,
                        SUM(CASE WHEN payment_date BETWEEN :month_start_amount AND :month_end_amount THEN amount ELSE 0 END) as revenue_this_month,
                        SUM(CASE WHEN payment_date = CURDATE() THEN 1 ELSE 0 END) as payments_today,
                        SUM(CASE WHEN payment_date BETWEEN :month_start_count AND :month_end_count THEN 1 ELSE 0 END) as payments_this_month,
                        SUM(CASE WHEN status = 'pending' THEN COALESCE(remaining_amount, 0) ELSE 0 END) as pending_remaining_amount
                     FROM (
                         SELECT amount, payment_date, status, remaining_amount FROM payments_men
                         UNION ALL
                         SELECT amount, payment_date, status, remaining_amount FROM payments_women
                     ) all_payments";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':month_start_amount', $monthStart, PDO::PARAM_STR);
            $stmt->bindValue(':month_end_amount', $monthEnd, PDO::PARAM_STR);
            $stmt->bindValue(':month_start_count', $monthStart, PDO::PARAM_STR);
            $stmt->bindValue(':month_end_count', $monthEnd, PDO::PARAM_STR);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $dailyTrendStmt = $db->prepare("SELECT payment_date AS label, SUM(amount) AS total FROM (
                    SELECT payment_date, amount FROM payments_men WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY)
                    UNION ALL
                    SELECT payment_date, amount FROM payments_women WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY)
                ) p GROUP BY payment_date ORDER BY payment_date ASC");
            $dailyTrendStmt->execute();
            $dailyTrend = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
            }, $dailyTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $monthlyTrendStmt = $db->prepare("SELECT label, SUM(total) AS total FROM (
                    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS label, SUM(amount) AS total FROM payments_men GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                    UNION ALL
                    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS label, SUM(amount) AS total FROM payments_women GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ) p GROUP BY label ORDER BY label ASC");
            $monthlyTrendStmt->execute();
            $monthlyTrend = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
            }, $monthlyTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $methodStmt = $db->prepare("SELECT payment_method AS label, COUNT(*) AS total FROM (
                    SELECT COALESCE(payment_method, 'Unknown') AS payment_method FROM payments_men
                    UNION ALL
                    SELECT COALESCE(payment_method, 'Unknown') AS payment_method FROM payments_women
                ) p GROUP BY payment_method ORDER BY total DESC, payment_method ASC");
            $methodStmt->execute();
            $methodBreakdown = array_map(static function ($row) {
                return ['label' => $row['label'] ?: 'Unknown', 'total' => (int)($row['total'] ?? 0)];
            }, $methodStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_payments' => (int)($stats['total_payments'] ?? 0),
                    'total_revenue' => (float)($stats['total_revenue'] ?? 0),
                    'avg_payment' => (float)($stats['avg_payment'] ?? 0),
                    'revenue_today' => (float)($stats['revenue_today'] ?? 0),
                    'revenue_this_month' => (float)($stats['revenue_this_month'] ?? 0),
                    'payments_today' => (int)($stats['payments_today'] ?? 0),
                    'payments_this_month' => (int)($stats['payments_this_month'] ?? 0),
                    'pending_remaining_amount' => (float)($stats['pending_remaining_amount'] ?? 0),
                    'charts' => [
                        'daily_revenue' => $dailyTrend,
                        'monthly_revenue' => $monthlyTrend,
                        'payment_methods' => $methodBreakdown
                    ]
                ]
            ]);
            break;

        case 'attendance':
            $attendanceMen = new Attendance($db, 'men');
            $attendanceWomen = new Attendance($db, 'women');
            $todayMen = $attendanceMen->getTodaySummary();
            $todayWomen = $attendanceWomen->getTodaySummary();

            $monthStart = date('Y-m-01');
            $monthQuery = "SELECT
                                COUNT(*) as month_count,
                                COUNT(DISTINCT member_key) as unique_members_this_month
                           FROM (
                                SELECT CONCAT('men-', member_id) as member_key, check_in FROM attendance_men WHERE DATE(check_in) >= :month_start_1
                                UNION ALL
                                SELECT CONCAT('women-', member_id) as member_key, check_in FROM attendance_women WHERE DATE(check_in) >= :month_start_2
                           ) attendance_union";
            $monthStmt = $db->prepare($monthQuery);
            $monthStmt->bindValue(':month_start_1', $monthStart, PDO::PARAM_STR);
            $monthStmt->bindValue(':month_start_2', $monthStart, PDO::PARAM_STR);
            $monthStmt->execute();
            $monthStats = $monthStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $dailyAttendanceStmt = $db->prepare("SELECT label, SUM(total) AS total FROM (
                SELECT DATE(check_in) AS label, COUNT(*) AS total FROM attendance_men WHERE DATE(check_in) >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY) GROUP BY DATE(check_in)
                UNION ALL
                SELECT DATE(check_in) AS label, COUNT(*) AS total FROM attendance_women WHERE DATE(check_in) >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY) GROUP BY DATE(check_in)
            ) a GROUP BY label ORDER BY label ASC");
            $dailyAttendanceStmt->execute();
            $dailyAttendance = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (int)($row['total'] ?? 0)];
            }, $dailyAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $genderAttendanceStmt = $db->prepare("SELECT label, SUM(total) AS total FROM (
                SELECT 'Men' AS label, COUNT(*) AS total FROM attendance_men
                UNION ALL
                SELECT 'Women' AS label, COUNT(*) AS total FROM attendance_women
            ) g GROUP BY label ORDER BY label ASC");
            $genderAttendanceStmt->execute();
            $genderAttendance = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (int)($row['total'] ?? 0)];
            }, $genderAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            echo json_encode([
                'success' => true,
                'data' => [
                    'today' => ($todayMen['total_visits'] ?? 0) + ($todayWomen['total_visits'] ?? 0),
                    'today_unique_members' => ($todayMen['unique_members'] ?? 0) + ($todayWomen['unique_members'] ?? 0),
                    'active_sessions' => ($todayMen['active_sessions'] ?? 0) + ($todayWomen['active_sessions'] ?? 0),
                    'this_month' => (int)($monthStats['month_count'] ?? 0),
                    'unique_members_this_month' => (int)($monthStats['unique_members_this_month'] ?? 0),
                    'charts' => [
                        'daily_attendance' => $dailyAttendance,
                        'gender_attendance' => $genderAttendance
                    ]
                ]
            ]);
            break;

        case 'expenses':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');

            $summaryStmt = $db->prepare("SELECT COUNT(*) AS total_expenses, COALESCE(SUM(amount), 0) AS total_amount, COALESCE(AVG(amount), 0) AS avg_amount FROM expenses WHERE expense_date BETWEEN :start_date AND :end_date");
            $summaryStmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
            $summaryStmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
            $summaryStmt->execute();
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $categoryStmt = $db->prepare("SELECT COALESCE(category, 'Uncategorized') AS label, SUM(amount) AS total FROM expenses GROUP BY COALESCE(category, 'Uncategorized') ORDER BY total DESC, label ASC");
            $categoryStmt->execute();
            $categories = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
            }, $categoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $monthlyStmt = $db->prepare("SELECT DATE_FORMAT(expense_date, '%Y-%m') AS label, SUM(amount) AS total FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY) GROUP BY DATE_FORMAT(expense_date, '%Y-%m') ORDER BY DATE_FORMAT(expense_date, '%Y-%m') ASC");
            $monthlyStmt->execute();
            $monthly = array_map(static function ($row) {
                return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
            }, $monthlyStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_expenses' => (int)($summary['total_expenses'] ?? 0),
                    'total_amount' => (float)($summary['total_amount'] ?? 0),
                    'avg_amount' => (float)($summary['avg_amount'] ?? 0),
                    'charts' => [
                        'categories' => $categories,
                        'monthly_expenses' => $monthly
                    ]
                ]
            ]);
            break;

        case 'profit':
            $profitTrendStmt = $db->prepare("SELECT periods.label,
                COALESCE(revenue.total_revenue, 0) AS revenue,
                COALESCE(expenses.total_expense, 0) AS expenses,
                COALESCE(revenue.total_revenue, 0) - COALESCE(expenses.total_expense, 0) AS profit
                FROM (
                    SELECT DATE_FORMAT(day_date, '%Y-%m') AS label FROM (
                        SELECT CURDATE() - INTERVAL seq DAY AS day_date
                        FROM (
                            SELECT 0 AS seq UNION ALL SELECT 30 UNION ALL SELECT 60 UNION ALL SELECT 90 UNION ALL SELECT 120 UNION ALL SELECT 150 UNION ALL SELECT 180 UNION ALL SELECT 210 UNION ALL SELECT 240 UNION ALL SELECT 270 UNION ALL SELECT 300 UNION ALL SELECT 330 UNION ALL SELECT 360
                        ) s
                    ) dates
                    WHERE day_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY)
                    GROUP BY DATE_FORMAT(day_date, '%Y-%m')
                ) periods
                LEFT JOIN (
                    SELECT DATE_FORMAT(payment_date, '%Y-%m') AS label, SUM(amount) AS total_revenue FROM (
                        SELECT payment_date, amount FROM payments_men
                        UNION ALL
                        SELECT payment_date, amount FROM payments_women
                    ) p WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY)
                    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ) revenue ON revenue.label = periods.label
                LEFT JOIN (
                    SELECT DATE_FORMAT(expense_date, '%Y-%m') AS label, SUM(amount) AS total_expense FROM expenses
                    WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL {$rangeDays} DAY)
                    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                ) expenses ON expenses.label = periods.label
                ORDER BY periods.label ASC");
            $profitTrendStmt->execute();
            $profitTrend = array_map(static function ($row) {
                return [
                    'label' => $row['label'],
                    'revenue' => (float)($row['revenue'] ?? 0),
                    'expenses' => (float)($row['expenses'] ?? 0),
                    'profit' => (float)($row['profit'] ?? 0),
                ];
            }, $profitTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            echo json_encode([
                'success' => true,
                'data' => [
                    'range' => $range,
                    'trend' => $profitTrend
                ]
            ]);
            break;

        case 'export':
            // CSV export of members or payments across both genders
            $type = $_GET['type'] ?? 'members'; // 'members' or 'payments'
            $exportGender = $_GET['gender'] ?? 'all'; // 'men', 'women', 'all'

            $genders = ($exportGender === 'all') ? ['men', 'women'] : [in_array($exportGender, ['men', 'women'], true) ? $exportGender : 'men'];

            // Stream CSV directly — bypass JSON header
            header('Content-Type: text/csv; charset=utf-8');
            $filename = $type . '_' . $exportGender . '_' . date('Y-m-d') . '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');

            if ($type === 'members') {
                fputcsv($out, ['Gender', 'Member Code', 'Name', 'Phone', 'Email', 'Status', 'Join Date', 'Monthly Fee', 'Due Amount', 'Next Due Date', 'Address']);
                foreach ($genders as $g) {
                    $tbl = 'members_' . $g;
                    $dc = resolve_member_date_column($db, $tbl);
                    $rows = $db->query("SELECT '{$g}' AS gender, member_code, name, phone, COALESCE(email,'') AS email, status, {$dc} AS join_date, monthly_fee, total_due_amount, next_fee_due_date, COALESCE(address,'') AS address FROM {$tbl} ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        fputcsv($out, $row);
                    }
                }
            } elseif ($type === 'payments') {
                fputcsv($out, ['Gender', 'Member Code', 'Member Name', 'Amount', 'Remaining', 'Payment Date', 'Method', 'Invoice', 'Received By', 'Status']);
                foreach ($genders as $g) {
                    $ptbl = 'payments_' . $g;
                    $mtbl = 'members_' . $g;
                    $rows = $db->query("SELECT '{$g}' AS gender, m.member_code, m.name, p.amount, p.remaining_amount, p.payment_date, p.payment_method, p.invoice_number, p.received_by, p.status FROM {$ptbl} p JOIN {$mtbl} m ON p.member_id = m.id ORDER BY p.payment_date DESC")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        fputcsv($out, $row);
                    }
                }
            }

            fclose($out);
            exit;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid report type']);
    }
} catch (Throwable $e) {
    error_log('Reports API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
