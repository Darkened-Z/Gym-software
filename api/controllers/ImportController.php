<?php
/**
 * Import Controller for Excel Member Import
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/models/Member.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportController {
    private $db;
    private $gender;
    private $member;

    public function __construct($db, $gender = 'men') {
        $this->db = $db;
        $this->gender = in_array($gender, ['men', 'women']) ? $gender : 'men';
        $this->member = new Member($db, $this->gender);
    }

    public function importFromFile($filePath, $gender) {
        $this->gender = $gender;
        $this->member = new Member($this->db, $gender);

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'duplicates' => []
        ];

        try {
            // Start transaction for atomic import
            $this->db->beginTransaction();
            
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows) || count($rows) < 2) {
                throw new Exception('Excel file is empty or has no data rows');
            }

            // Get header row (first row)
            $headers = array_map('strtolower', array_map('trim', $rows[0]));
            
            // Map Excel columns to database fields
            $columnMap = $this->getColumnMap($headers);

            $totalRows = count($rows) - 1; // Exclude header
            $processedRows = 0;

            // Process data rows (skip header) - process ALL rows in one go
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                try {
                    $memberData = $this->mapRowToMemberData($row, $columnMap);
                    
                    // Generate member code if missing
                    if (empty($memberData['member_code'])) {
                        $memberData['member_code'] = 'AUTO_' . date('Ymd') . '_' . str_pad($i, 4, '0', STR_PAD_LEFT);
                    }
                    
                    // Generate name if missing
                    if (empty($memberData['name'])) {
                        $memberData['name'] = 'Member ' . $memberData['member_code'];
                    }
                    
                    // Handle unique constraints - make phone/email unique per row if they conflict
                    $tableName = 'members_' . $this->gender;
                    
                    // If phone conflicts, make it unique
                    if (!empty($memberData['phone'])) {
                        // Check if phone already exists
                        $phoneCheck = $this->db->prepare("SELECT id FROM {$tableName} WHERE phone = :phone AND phone != '' LIMIT 1");
                        $phoneCheck->bindValue(':phone', $memberData['phone'], PDO::PARAM_STR);
                        $phoneCheck->execute();
                        if ($phoneCheck->fetch()) {
                            // Phone conflict - append row number to make it unique
                            $memberData['phone'] = $memberData['phone'] . '_' . $i;
                        }
                    }
                    
                    // If email conflicts, make it unique
                    if (!empty($memberData['email'])) {
                        $emailCheck = $this->db->prepare("SELECT id FROM {$tableName} WHERE email = :email AND email IS NOT NULL LIMIT 1");
                        $emailCheck->bindValue(':email', $memberData['email'], PDO::PARAM_STR);
                        $emailCheck->execute();
                        if ($emailCheck->fetch()) {
                            // Email conflict - append row number to make it unique
                            $memberData['email'] = str_replace('@', '_' . $i . '@', $memberData['email']);
                        }
                    }
                    
                    // Check if member exists by code - if exists, update instead of skip
                    $existing = $this->member->getByCode($memberData['member_code']);
                    if ($existing) {
                        // Update existing member - ignore errors and continue
                        try {
                            $this->member->update($existing['id'], $memberData);
                            $results['success']++;
                        } catch (Exception $e) {
                            // If update fails, try to insert as new with modified code
                            $memberData['member_code'] = $memberData['member_code'] . '_' . $i;
                    $id = $this->member->create($memberData);
                    if ($id) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                                $results['errors'][] = "Row " . ($i + 1) . " (Member Code: {$memberData['member_code']}): " . $e->getMessage();
                            }
                        }
                    } else {
                        // Insert new member (preserving sequence from Excel)
                        // Retry with modified code if unique constraint fails
                        $maxRetries = 3;
                        $retryCount = 0;
                        $inserted = false;
                        
                        while ($retryCount < $maxRetries && !$inserted) {
                            try {
                                $id = $this->member->create($memberData);
                                if ($id) {
                                    $results['success']++;
                                    $inserted = true;
                                } else {
                                    // If create returns false, try with modified code
                                    $memberData['member_code'] = $memberData['member_code'] . '_' . $i . '_' . $retryCount;
                                    $retryCount++;
                                }
                            } catch (PDOException $e) {
                                // Handle unique constraint violations
                                if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
                                    $memberData['member_code'] = $memberData['member_code'] . '_' . $i . '_' . $retryCount;
                                    $retryCount++;
                                } else {
                                    // Other database error
                                    $results['failed']++;
                                    $results['errors'][] = "Row " . ($i + 1) . " (Member Code: {$memberData['member_code']}): " . $e->getMessage();
                                    $inserted = true; // Stop retrying
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                                $results['errors'][] = "Row " . ($i + 1) . " (Member Code: {$memberData['member_code']}): " . $e->getMessage();
                                $inserted = true; // Stop retrying
                            }
                        }
                        
                        if (!$inserted && $retryCount >= $maxRetries) {
                            $results['failed']++;
                            $results['errors'][] = "Row " . ($i + 1) . " (Member Code: {$memberData['member_code']}): Failed after multiple retries";
                        }
                    }
                } catch (Exception $e) {
                    $memberCode = isset($memberData['member_code']) && !empty($memberData['member_code']) 
                        ? $memberData['member_code'] 
                        : 'N/A';
                    $results['failed']++;
                    $results['errors'][] = "Row " . ($i + 1) . " (Member Code: {$memberCode}): " . $e->getMessage();
                }
                
                $processedRows++;
                
                // Progress update every 100 rows (for large files)
                if ($processedRows % 100 === 0) {
                    // Flush output buffer to prevent timeout
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }

            // Commit transaction if all rows processed
            $this->db->commit();
            
            return $results;
        } catch (Exception $e) {
            // Rollback on error
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('Error processing file: ' . $e->getMessage());
        }
    }

    private function getColumnMap($headers) {
        $map = [];
        
        // Map various possible column names
        $mappings = [
            'ac_no' => ['ac_no', 'acno', 'member_code', 'code'],
            'ac_name' => ['ac_name', 'acname', 'name', 'member_name'],
            'address' => ['address', 'addr'],
            'mobile' => ['mobile', 'phone', 'contact'],
            'admission_date' => ['admission_date', 'admissiondate', 'join_date', 'joindate'],
            'admission_fee' => ['admission_fee', 'admissionfee'],
            'monthly_fee' => ['monthly_fee', 'monthlyfee', 'fee'],
            'locker_fee' => ['locker_fee', 'lockerfee'],
            'enable_disable' => ['enable_disable', 'enabledisable', 'status']
        ];

        foreach ($mappings as $dbField => $possibleHeaders) {
            foreach ($possibleHeaders as $header) {
                $index = array_search($header, $headers);
                if ($index !== false) {
                    $map[$dbField] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function mapRowToMemberData($row, $columnMap) {
        // Initialize with default values - all fields except name and code are optional
        $data = [
            'member_code' => '',
            'name' => '',
            'email' => null,
            'phone' => '',  // Optional - use empty string for NOT NULL constraint
            'address' => null,
            'profile_image' => null,
            'membership_type' => 'Basic',  // Default value
            'join_date' => date('Y-m-d'),  // Default to today if not provided
            'admission_fee' => 0.00,  // Default to 0 if not provided
            'monthly_fee' => 0.00,  // Default to 0 if not provided
            'locker_fee' => 0.00,  // Default to 0 if not provided
            'next_fee_due_date' => null,
            'status' => 'active'  // Default to active if not provided
        ];

        // Map Ac_No to member_code (MANDATORY)
        if (isset($columnMap['ac_no'])) {
            $value = trim((string)$row[$columnMap['ac_no']]);
            $data['member_code'] = $value;
        }

        // Map Ac_Name to name (MANDATORY)
        if (isset($columnMap['ac_name'])) {
            $value = trim((string)$row[$columnMap['ac_name']]);
            $data['name'] = $value;
        }

        // Map Address (OPTIONAL)
        if (isset($columnMap['address'])) {
            $value = trim((string)$row[$columnMap['address']]);
            $data['address'] = !empty($value) ? $value : null;
        }

        // Map Mobile to phone (OPTIONAL)
        if (isset($columnMap['mobile'])) {
            $value = trim((string)$row[$columnMap['mobile']]);
            $data['phone'] = !empty($value) ? $value : '';  // Use empty string instead of null for NOT NULL constraint
        }

        // Map Admission_Date - handle Excel date format
        if (isset($columnMap['admission_date'])) {
            $dateValue = $row[$columnMap['admission_date']];
            if (is_numeric($dateValue)) {
                // Excel date serial number
                $data['join_date'] = date('Y-m-d', Date::excelToTimestamp($dateValue));
            } else {
                // Try to parse as date string
                $parsed = date_create($dateValue);
                if ($parsed) {
                    $data['join_date'] = $parsed->format('Y-m-d');
                }
            }
        }

        // Map Admission_fee
        if (isset($columnMap['admission_fee'])) {
            $data['admission_fee'] = (float)$row[$columnMap['admission_fee']];
        }

        // Map Monthly_fee
        if (isset($columnMap['monthly_fee'])) {
            $data['monthly_fee'] = (float)$row[$columnMap['monthly_fee']];
        }

        // Map locker_fee
        if (isset($columnMap['locker_fee'])) {
            $data['locker_fee'] = (float)$row[$columnMap['locker_fee']];
        }

        // Map enable_disable to status
        if (isset($columnMap['enable_disable'])) {
            $statusValue = strtolower(trim((string)$row[$columnMap['enable_disable']]));
            $data['status'] = ($statusValue === 'enable' || $statusValue === 'active' || $statusValue === '1') ? 'active' : 'inactive';
        }

        return $data;
    }
}

