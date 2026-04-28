<?php
/**
 * Dashboard API
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/Member.php';
require_once __DIR__ . '/../app/models/Attendance.php';
require_once __DIR__ . '/../app/models/Expense.php';
require_once __DIR__ . '/../app/helpers/AuthHelper.php';

// Release session lock immediately to prevent blocking parallel requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

AuthHelper::requireAdminOrStaff();

// AUTO-RUN DAILY JOBS
require_once __DIR__ . '/CronHelper.php';
try {
    $cron = new CronHelper();
    $cron->runDailyAutoArchive();
} catch (Exception $e) {
    // Silent fail to not break dashboard load
    error_log("Cron Error: " . $e->getMessage());
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $memberMen = new Member($db, 'men');
    $memberWomen = new Member($db, 'women');
    $attendanceMen = new Attendance($db, 'men');
    $attendanceWomen = new Attendance($db, 'women');
    $expense = new Expense($db);

    // Date range filter (defaults to current month)
    $filterStartDate = $_GET['start_date'] ?? null;
    $filterEndDate   = $_GET['end_date'] ?? null;
    $validDate = fn($d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    $rangeStart = $validDate($filterStartDate) ?? date('Y-m-01');
    $rangeEnd   = $validDate($filterEndDate)   ?? date('Y-m-t');
    // Swap if reversed
    if ($rangeStart > $rangeEnd) [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];

    // Get stats with error handling
    try {
        $statsMen = $memberMen->getStats();
        if (!is_array($statsMen) || !isset($statsMen['total'])) {
            $statsMen = ['total' => 0, 'active' => 0];
        }
    } catch (Exception $e) {
        $statsMen = ['total' => 0, 'active' => 0];
    }
    
    try {
        $statsWomen = $memberWomen->getStats();
        if (!is_array($statsWomen) || !isset($statsWomen['total'])) {
            $statsWomen = ['total' => 0, 'active' => 0];
        }
    } catch (Exception $e) {
        $statsWomen = ['total' => 0, 'active' => 0];
    }
    
    try {
        $recentMen = $memberMen->getRecent(5);
        if (!is_array($recentMen)) {
            $recentMen = [];
        }
    } catch (Exception $e) {
        $recentMen = [];
    }
    
    try {
        $recentWomen = $memberWomen->getRecent(5);
        if (!is_array($recentWomen)) {
            $recentWomen = [];
        }
    } catch (Exception $e) {
        $recentWomen = [];
    }

    try {
        $opsMen = $memberMen->getOperationalSnapshot();
    } catch (Throwable $e) {
        $opsMen = ['checked_in_now' => 0, 'due_today' => 0, 'overdue' => 0, 'new_this_month' => 0, 'total_active_due' => 0.0];
    }

    try {
        $opsWomen = $memberWomen->getOperationalSnapshot();
    } catch (Throwable $e) {
        $opsWomen = ['checked_in_now' => 0, 'due_today' => 0, 'overdue' => 0, 'new_this_month' => 0, 'total_active_due' => 0.0];
    }

    try {
        $attendanceTodayMen = $attendanceMen->getTodaySummary();
    } catch (Throwable $e) {
        $attendanceTodayMen = ['total_visits' => 0, 'unique_members' => 0, 'active_sessions' => 0];
    }

    try {
        $attendanceTodayWomen = $attendanceWomen->getTodaySummary();
    } catch (Throwable $e) {
        $attendanceTodayWomen = ['total_visits' => 0, 'unique_members' => 0, 'active_sessions' => 0];
    }

    // Get revenue for the selected date range
    try {
        $revenueQuery = "SELECT SUM(amount) as total FROM (
            SELECT amount FROM payments_men WHERE payment_date BETWEEN :start1 AND :end1
            UNION ALL
            SELECT amount FROM payments_women WHERE payment_date BETWEEN :start2 AND :end2
        ) as all_payments";
        $revenueStmt = $db->prepare($revenueQuery);
        $revenueStmt->bindValue(':start1', $rangeStart, PDO::PARAM_STR);
        $revenueStmt->bindValue(':end1', $rangeEnd, PDO::PARAM_STR);
        $revenueStmt->bindValue(':start2', $rangeStart, PDO::PARAM_STR);
        $revenueStmt->bindValue(':end2', $rangeEnd, PDO::PARAM_STR);
        $revenueStmt->execute();
        $revenueResult = $revenueStmt->fetch(PDO::FETCH_ASSOC);
        $revenue = floatval($revenueResult['total'] ?? 0.00);
    } catch (Exception $e) {
        $revenue = 0.00;
    }

    // Get expenses for the selected date range
    try {
        $expenses = $expense->getTotalByPeriod($rangeStart, $rangeEnd);
        if (!is_numeric($expenses)) {
            $expenses = 0.00;
        }
    } catch (Exception $e) {
        $expenses = 0.00;
    }

    // Calculate profit
    $profit = $revenue - $expenses;

    // Today's collections and collection trend snapshot
    try {
        $todayRevenueQuery = "SELECT SUM(amount) as total FROM (
            SELECT amount FROM payments_men WHERE payment_date = CURDATE()
            UNION ALL
            SELECT amount FROM payments_women WHERE payment_date = CURDATE()
        ) as today_payments";
        $todayRevenueStmt = $db->prepare($todayRevenueQuery);
        $todayRevenueStmt->execute();
        $todayRevenueResult = $todayRevenueStmt->fetch(PDO::FETCH_ASSOC);
        $todayRevenue = floatval($todayRevenueResult['total'] ?? 0.00);
    } catch (Throwable $e) {
        $todayRevenue = 0.00;
    }

    // Get total revenue (all time - all payments received)
    try {
        $totalRevenueQuery = "SELECT SUM(amount) as total FROM (
            SELECT amount FROM payments_men
            UNION ALL
            SELECT amount FROM payments_women
        ) as all_payments";
        $totalRevenueStmt = $db->prepare($totalRevenueQuery);
        $totalRevenueStmt->execute();
        $totalRevenueResult = $totalRevenueStmt->fetch(PDO::FETCH_ASSOC);
        $totalRevenue = floatval($totalRevenueResult['total'] ?? 0.00);
    } catch (Exception $e) {
        $totalRevenue = 0.00;
    }

    // Get total expenses (all time)
    try {
        $totalExpenses = $expense->getTotalByPeriod();
        if (!is_numeric($totalExpenses)) {
            $totalExpenses = 0.00;
        }
    } catch (Exception $e) {
        $totalExpenses = 0.00;
    }

    // Calculate net profit (all time)
    $netProfit = $totalRevenue - $totalExpenses;

    $checkedInNow = ($opsMen['checked_in_now'] ?? 0) + ($opsWomen['checked_in_now'] ?? 0);
    $dueToday = ($opsMen['due_today'] ?? 0) + ($opsWomen['due_today'] ?? 0);
    $overdue = ($opsMen['overdue'] ?? 0) + ($opsWomen['overdue'] ?? 0);
    $newThisMonth = ($opsMen['new_this_month'] ?? 0) + ($opsWomen['new_this_month'] ?? 0);
    $activeDueAmount = ($opsMen['total_active_due'] ?? 0) + ($opsWomen['total_active_due'] ?? 0);
    $todayVisits = ($attendanceTodayMen['total_visits'] ?? 0) + ($attendanceTodayWomen['total_visits'] ?? 0);
    $todayUniqueMembers = ($attendanceTodayMen['unique_members'] ?? 0) + ($attendanceTodayWomen['unique_members'] ?? 0);
    $activeSessions = ($attendanceTodayMen['active_sessions'] ?? 0) + ($attendanceTodayWomen['active_sessions'] ?? 0);

    try {
        $memberGrowthQuery = "SELECT label, SUM(total) AS total FROM (
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM members_men GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            UNION ALL
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM members_women GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ) mg GROUP BY label ORDER BY label ASC";
        $memberGrowthStmt = $db->prepare($memberGrowthQuery);
        $memberGrowthStmt->execute();
        $memberGrowth = array_map(static function ($row) {
            return ['label' => $row['label'], 'total' => (int)($row['total'] ?? 0)];
        }, $memberGrowthStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $memberGrowth = [];
    }

    try {
        $revenueTrendQuery = "SELECT payment_date AS label, SUM(amount) AS total FROM (
            SELECT payment_date, amount FROM payments_men WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            UNION ALL
            SELECT payment_date, amount FROM payments_women WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ) rt GROUP BY payment_date ORDER BY payment_date ASC";
        $revenueTrendStmt = $db->prepare($revenueTrendQuery);
        $revenueTrendStmt->execute();
        $revenueTrend = array_map(static function ($row) {
            return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
        }, $revenueTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $revenueTrend = [];
    }

    try {
        $attendanceTrendQuery = "SELECT label, SUM(total) AS total FROM (
            SELECT DATE(check_in) AS label, COUNT(*) AS total FROM attendance_men WHERE DATE(check_in) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(check_in)
            UNION ALL
            SELECT DATE(check_in) AS label, COUNT(*) AS total FROM attendance_women WHERE DATE(check_in) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(check_in)
        ) atd GROUP BY label ORDER BY label ASC";
        $attendanceTrendStmt = $db->prepare($attendanceTrendQuery);
        $attendanceTrendStmt->execute();
        $attendanceTrend = array_map(static function ($row) {
            return ['label' => $row['label'], 'total' => (int)($row['total'] ?? 0)];
        }, $attendanceTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $attendanceTrend = [];
    }

    try {
        $expenseTrendQuery = "SELECT expense_date AS label, SUM(amount) AS total FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY expense_date ORDER BY expense_date ASC";
        $expenseTrendStmt = $db->prepare($expenseTrendQuery);
        $expenseTrendStmt->execute();
        $expenseTrend = array_map(static function ($row) {
            return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
        }, $expenseTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $expenseTrend = [];
    }

    // Attendance heatmap — daily check-in counts for current month
    try {
        $heatmapStmt = $db->prepare(
            "SELECT DATE(check_in) AS day, COUNT(*) AS total
             FROM (
                SELECT check_in FROM attendance_men
                  WHERE check_in >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                UNION ALL
                SELECT check_in FROM attendance_women
                  WHERE check_in >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
             ) combined
             GROUP BY DATE(check_in)
             ORDER BY day ASC"
        );
        $heatmapStmt->execute();
        $attendanceHeatmap = $heatmapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $attendanceHeatmap = [];
    }

    // Peak hours today — check-in counts by hour of day
    try {
        $peakHoursStmt = $db->prepare(
            "SELECT HOUR(check_in) AS hour, COUNT(*) AS total
             FROM (
                SELECT check_in FROM attendance_men WHERE DATE(check_in) = CURDATE()
                UNION ALL
                SELECT check_in FROM attendance_women WHERE DATE(check_in) = CURDATE()
             ) combined
             GROUP BY HOUR(check_in)
             ORDER BY hour ASC"
        );
        $peakHoursStmt->execute();
        $rawPeak = $peakHoursStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $peakMap = [];
        foreach ($rawPeak as $row) {
            $peakMap[(int)$row['hour']] = (int)$row['total'];
        }
        $peakHoursToday = [];
        for ($h = 5; $h <= 23; $h++) {
            $label = $h < 12 ? "{$h}am" : ($h === 12 ? '12pm' : ($h - 12) . 'pm');
            $peakHoursToday[] = ['label' => $label, 'total' => $peakMap[$h] ?? 0];
        }
    } catch (Throwable $e) {
        $peakHoursToday = [];
    }

    // Expense breakdown by category (current month)
    try {
        $expBreakdownStmt = $db->prepare(
            "SELECT COALESCE(NULLIF(TRIM(category),''), 'Uncategorized') AS label, SUM(amount) AS total
             FROM expenses WHERE expense_date BETWEEN :start AND :end
             GROUP BY label ORDER BY total DESC"
        );
        $expBreakdownStmt->bindValue(':start', date('Y-m-01'), PDO::PARAM_STR);
        $expBreakdownStmt->bindValue(':end', date('Y-m-t'), PDO::PARAM_STR);
        $expBreakdownStmt->execute();
        $expenseBreakdown = array_map(
            fn($r) => ['label' => $r['label'], 'total' => (float)$r['total']],
            $expBreakdownStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    } catch (Throwable $e) {
        $expenseBreakdown = [];
    }

    try {
        $duesTrendQuery = "SELECT label, SUM(total_due_amount) AS total FROM (
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COALESCE(SUM(total_due_amount), 0) AS total_due_amount FROM members_men GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            UNION ALL
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COALESCE(SUM(total_due_amount), 0) AS total_due_amount FROM members_women GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ) dt GROUP BY label ORDER BY label ASC";
        $duesTrendStmt = $db->prepare($duesTrendQuery);
        $duesTrendStmt->execute();
        $duesTrend = array_map(static function ($row) {
            return ['label' => $row['label'], 'total' => (float)($row['total'] ?? 0)];
        }, $duesTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        $duesTrend = [];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'men' => [
                'stats' => $statsMen,
                'recent' => $recentMen,
                'operations' => $opsMen,
                'attendance_today' => $attendanceTodayMen
            ],
            'women' => [
                'stats' => $statsWomen,
                'recent' => $recentWomen,
                'operations' => $opsWomen,
                'attendance_today' => $attendanceTodayWomen
            ],
            'total' => [
                'members' => $statsMen['total'] + $statsWomen['total'],
                'active' => $statsMen['active'] + $statsWomen['active']
            ],
            'operations' => [
                'checked_in_now' => $checkedInNow,
                'due_today' => $dueToday,
                'overdue' => $overdue,
                'new_this_month' => $newThisMonth,
                'active_due_amount' => $activeDueAmount,
                'today_visits' => $todayVisits,
                'today_unique_members' => $todayUniqueMembers,
                'active_sessions' => $activeSessions
            ],
            'financial' => [
                'date_range' => [
                    'start' => $rangeStart,
                    'end'   => $rangeEnd
                ],
                'current_month' => [
                    'revenue' => $revenue,
                    'expenses' => $expenses,
                    'profit' => $profit
                ],
                'today' => [
                    'revenue' => $todayRevenue
                ],
                'all_time' => [
                    'revenue' => $totalRevenue,
                    'expenses' => $totalExpenses,
                    'net_profit' => $netProfit
                ]
            ],
            'member_growth' => $memberGrowth,
            'revenue_trend' => $revenueTrend,
            'attendance_trend' => $attendanceTrend,
            'expense_trend' => $expenseTrend,
            'dues_trend' => $duesTrend,
            'attendance_heatmap' => $attendanceHeatmap,
            'peak_hours_today' => $peakHoursToday,
            'expense_breakdown' => $expenseBreakdown
        ]
    ]);
} catch (Exception $e) {
    // Log error for debugging
    error_log('Dashboard API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    
    http_response_code(500);
    
    // Only show detailed errors in debug mode
    $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE 
        ? 'Server error: ' . $e->getMessage()
        : 'An error occurred while loading dashboard data. Please try again.';
    
    $response = ['success' => false, 'message' => $errorMessage];
    
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $response['error_details'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response);
} catch (Error $e) {
    // Handle fatal errors (PHP 7+)
    error_log('Dashboard API Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    
    http_response_code(500);
    $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE
        ? 'Fatal error: ' . $e->getMessage()
        : 'A fatal error occurred. Please contact support.';
    
    echo json_encode([
        'success' => false,
        'message' => $errorMessage
    ]);
}

