<?php
/**
 * PDF Export Helper
 * Simple PDF generation for reports
 * Requires: composer require mpdf/mpdf
 */

class PDFExport {
    /**
     * Generate PDF from HTML content
     * @param string $html HTML content
     * @param string $filename Output filename
     * @param bool $download Whether to force download
     * @return bool|string True on success, error message on failure
     */
    public static function generateFromHTML($html, $filename = 'report.pdf', $download = true) {
        // Check if mPDF is available
        if (!class_exists('\Mpdf\Mpdf')) {
            return 'mPDF library not installed. Run: composer require mpdf/mpdf';
        }
        
        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 16,
                'margin_bottom' => 16,
                'margin_header' => 9,
                'margin_footer' => 9
            ]);
            
            $mpdf->WriteHTML($html);
            
            if ($download) {
                $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
            } else {
                $mpdf->Output($filename, \Mpdf\Output\Destination::FILE);
            }
            
            return true;
        } catch (Exception $e) {
            return 'PDF generation error: ' . $e->getMessage();
        }
    }
    
    /**
     * Generate simple report HTML template
     * @param string $title Report title
     * @param array $data Report data
     * @param array $headers Table headers
     * @return string HTML content
     */
    public static function generateReportHTML($title, $data, $headers = []) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background-color: #667eea; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <h1>' . htmlspecialchars($title) . '</h1>
    <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
    
    <table>
        <thead><tr>';
        
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        
        $html .= '</tr></thead><tbody>';
        
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>
    
    <div class="footer">
        <p>Gym Management System - Generated Report</p>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Export members report to PDF
     * @param array $members Array of member data
     * @param string $gender Gender (men/women/all)
     * @return bool|string True on success, error message on failure
     */
    public static function exportMembersReport($members, $gender = 'all') {
        $title = 'Members Report - ' . ucfirst($gender);
        $headers = ['#', 'Member Code', 'Name', 'Phone', 'Join Date', 'Status'];
        
        $data = [];
        $index = 1;
        foreach ($members as $member) {
            $data[] = [
                $index++,
                $member['member_code'] ?? 'N/A',
                $member['name'] ?? 'N/A',
                $member['phone'] ?? 'N/A',
                $member['join_date'] ?? 'N/A',
                $member['status'] ?? 'N/A'
            ];
        }
        
        $html = self::generateReportHTML($title, $data, $headers);
        $filename = 'members_report_' . $gender . '_' . date('Y-m-d') . '.pdf';
        
        return self::generateFromHTML($html, $filename);
    }
    
    /**
     * Export payments report to PDF
     * @param array $payments Array of payment data
     * @param string $period Period description
     * @return bool|string True on success, error message on failure
     */
    public static function exportPaymentsReport($payments, $period = '') {
        $title = 'Payments Report' . ($period ? ' - ' . $period : '');
        $headers = ['#', 'Member Code', 'Member Name', 'Amount', 'Payment Type', 'Date'];
        
        $data = [];
        $index = 1;
        $total = 0;
        
        foreach ($payments as $payment) {
            $amount = floatval($payment['amount'] ?? 0);
            $total += $amount;
            $data[] = [
                $index++,
                $payment['member_code'] ?? 'N/A',
                $payment['member_name'] ?? 'N/A',
                'Rs. ' . number_format($amount, 2),
                $payment['payment_type'] ?? 'N/A',
                $payment['payment_date'] ?? 'N/A'
            ];
        }
        
        // Add total row
        $data[] = ['', '', '', 'Total: Rs. ' . number_format($total, 2), '', ''];
        
        $html = self::generateReportHTML($title, $data, $headers);
        $filename = 'payments_report_' . date('Y-m-d') . '.pdf';
        
        return self::generateFromHTML($html, $filename);
    }
    
    /**
     * Export attendance report to PDF
     * @param array $attendance Array of attendance data
     * @param string $period Period description
     * @return bool|string True on success, error message on failure
     */
    public static function exportAttendanceReport($attendance, $period = '') {
        $title = 'Attendance Report' . ($period ? ' - ' . $period : '');
        $headers = ['#', 'Member Code', 'Member Name', 'Check-in', 'Check-out', 'Duration'];
        
        $data = [];
        $index = 1;
        
        foreach ($attendance as $record) {
            $data[] = [
                $index++,
                $record['member_code'] ?? 'N/A',
                $record['name'] ?? 'N/A',
                $record['check_in'] ?? 'N/A',
                $record['check_out'] ?? 'N/A',
                isset($record['duration_minutes']) ? floor($record['duration_minutes'] / 60) . 'h ' . ($record['duration_minutes'] % 60) . 'm' : 'N/A'
            ];
        }
        
        $html = self::generateReportHTML($title, $data, $headers);
        $filename = 'attendance_report_' . date('Y-m-d') . '.pdf';
        
        return self::generateFromHTML($html, $filename);
    }
}
