<?php
/**
 * Unified attendance write service.
 * Handles idempotent check-in / check-out writes with journaled, transaction-safe updates.
 */

class AttendanceWriteService {
    private PDO $db;
    private string $gender;
    private string $memberTable;
    private string $attendanceTable;
    private string $journalTable = 'attendance_operation_journal';
    private int $retryWindowSeconds = 300;

    public function __construct(PDO $db, string $gender = 'men') {
        $this->db = $db;
        $this->gender = in_array($gender, ['men', 'women'], true) ? $gender : 'men';
        $this->memberTable = 'members_' . $this->gender;
        $this->attendanceTable = 'attendance_' . $this->gender;
    }

    public function recordCheckIn(int $memberId, array $context = []): array {
        $sourceSystem = $this->normalizeSource($context['source'] ?? 'member-profile');
        $gateId = $this->normalizeNullableString($context['gate_id'] ?? null, 20);
        $checkInTime = $this->normalizeDateTime($context['check_in'] ?? null) ?? $this->currentTimestamp();
        $requestPayload = [
            'member_id' => $memberId,
            'gender' => $this->gender,
            'source' => $sourceSystem,
            'gate_id' => $gateId,
            'check_in' => $checkInTime,
        ];

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $member = $this->lockMember($memberId);
            if (!$member) {
                throw $this->notFoundException('Member not found', 404);
            }

            $activeAttendance = $this->lockActiveAttendance($memberId);
            if ($activeAttendance) {
                if ((int)($member['is_checked_in'] ?? 0) !== 1) {
                    $this->setMemberCheckedInState($memberId, 1);
                }

                $response = $this->buildCheckInResponse($member, $activeAttendance, true);
                $this->insertJournal([
                    'operation_type' => 'checkin',
                    'source_system' => $sourceSystem,
                    'gender' => $this->gender,
                    'member_id' => $memberId,
                    'attendance_id' => (int)$activeAttendance['id'],
                    'gate_id' => $gateId,
                    'request_signature' => $this->buildSignature('checkin', $memberId, $sourceSystem, $gateId),
                    'status' => 'duplicate',
                    'request_payload' => $requestPayload,
                    'response_payload' => $response,
                    'error_message' => null,
                ]);

                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return $response;
            }

            $journalId = $this->insertJournal([
                'operation_type' => 'checkin',
                'source_system' => $sourceSystem,
                'gender' => $this->gender,
                'member_id' => $memberId,
                'attendance_id' => null,
                'gate_id' => $gateId,
                'request_signature' => $this->buildSignature('checkin', $memberId, $sourceSystem, $gateId),
                'status' => 'started',
                'request_payload' => $requestPayload,
                'response_payload' => null,
                'error_message' => null,
            ]);

            $firstEntryToday = $this->countAttendanceForDay($memberId, $checkInTime) === 0;
            $attendanceId = $this->insertAttendance([
                'member_id' => $memberId,
                'check_in' => $checkInTime,
                'check_out' => null,
                'duration_minutes' => null,
                'is_first_entry_today' => $firstEntryToday ? 1 : 0,
                'entry_gate_id' => $gateId,
                'exit_gate_id' => null,
                'write_source' => $sourceSystem,
                'write_signature' => $this->buildSignature('checkin', $memberId, $sourceSystem, $gateId),
                'write_journal_id' => $journalId,
            ]);

            $this->setMemberCheckedInState($memberId, 1);

            $attendance = [
                'id' => $attendanceId,
                'check_in' => $checkInTime,
                'check_out' => null,
                'duration_minutes' => null,
                'is_first_entry_today' => $firstEntryToday ? 1 : 0,
                'entry_gate_id' => $gateId,
                'exit_gate_id' => null,
            ];
            $response = $this->buildCheckInResponse($member, $attendance, false);

            $this->updateJournal($journalId, [
                'attendance_id' => $attendanceId,
                'status' => 'success',
                'response_payload' => $response,
            ]);

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $response;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->rollbackQuietly();
            }
            if ($e instanceof RuntimeException && (int)$e->getCode() === 404) {
                return $this->failure($e->getMessage(), 404);
            }
            return $this->failure('Failed to record check-in: ' . $e->getMessage(), 500);
        }
    }

    public function recordCheckoutByAttendanceId(int $attendanceId, array $context = []): array {
        $sourceSystem = $this->normalizeSource($context['source'] ?? 'member-profile');
        $gateId = $this->normalizeNullableString($context['gate_id'] ?? null, 20);
        $checkOutTime = $this->normalizeDateTime($context['check_out'] ?? null) ?? $this->currentTimestamp();

        $initialAttendance = $this->fetchOne(
            "SELECT id, member_id FROM {$this->attendanceTable} WHERE id = :id LIMIT 1",
            [':id' => $attendanceId]
        );

        if (!$initialAttendance) {
            return $this->failure('Attendance record not found', 404);
        }

        $memberId = (int)$initialAttendance['member_id'];
        $requestPayload = [
            'attendance_id' => $attendanceId,
            'member_id' => $memberId,
            'gender' => $this->gender,
            'source' => $sourceSystem,
            'gate_id' => $gateId,
            'check_out' => $checkOutTime,
        ];

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $member = $this->lockMember($memberId);
            if (!$member) {
                throw $this->notFoundException('Member not found', 404);
            }

            $attendance = $this->lockAttendanceById($attendanceId, $memberId);
            if (!$attendance) {
                throw $this->notFoundException('Attendance record not found', 404);
            }

            if (!empty($attendance['check_out'])) {
                $response = $this->buildCheckOutResponse($member, $attendance, true);
                $this->insertJournal([
                    'operation_type' => 'checkout',
                    'source_system' => $sourceSystem,
                    'gender' => $this->gender,
                    'member_id' => $memberId,
                    'attendance_id' => $attendanceId,
                    'gate_id' => $gateId,
                    'request_signature' => $this->buildSignature('checkout', $memberId, $sourceSystem, $gateId, $attendanceId),
                    'status' => 'duplicate',
                    'request_payload' => $requestPayload,
                    'response_payload' => $response,
                    'error_message' => null,
                ]);

                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return $response;
            }

            $journalId = $this->insertJournal([
                'operation_type' => 'checkout',
                'source_system' => $sourceSystem,
                'gender' => $this->gender,
                'member_id' => $memberId,
                'attendance_id' => $attendanceId,
                'gate_id' => $gateId,
                'request_signature' => $this->buildSignature('checkout', $memberId, $sourceSystem, $gateId, $attendanceId),
                'status' => 'started',
                'request_payload' => $requestPayload,
                'response_payload' => null,
                'error_message' => null,
            ]);

            $durationMinutes = $this->calculateDurationMinutes($attendance['check_in'], $checkOutTime);
            $this->updateAttendanceCheckout($attendanceId, $checkOutTime, $durationMinutes, $gateId, $sourceSystem, $journalId);
            $this->setMemberCheckedInState($memberId, 0);

            $attendance['check_out'] = $checkOutTime;
            $attendance['duration_minutes'] = $durationMinutes;
            $attendance['exit_gate_id'] = $gateId;

            $response = $this->buildCheckOutResponse($member, $attendance, false);
            $this->updateJournal($journalId, [
                'status' => 'success',
                'response_payload' => $response,
            ]);

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $response;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->rollbackQuietly();
            }
            if ($e instanceof RuntimeException && (int)$e->getCode() === 404) {
                return $this->failure($e->getMessage(), 404);
            }
            return $this->failure('Failed to record check-out: ' . $e->getMessage(), 500);
        }
    }

    public function recordCheckoutByMemberId(int $memberId, array $context = []): array {
        $sourceSystem = $this->normalizeSource($context['source'] ?? 'gate-exit');
        $gateId = $this->normalizeNullableString($context['gate_id'] ?? null, 20);
        $checkOutTime = $this->normalizeDateTime($context['check_out'] ?? null) ?? $this->currentTimestamp();
        $retryWindowSeconds = max(30, (int)($context['retry_window_seconds'] ?? $this->retryWindowSeconds));
        $requestPayload = [
            'member_id' => $memberId,
            'gender' => $this->gender,
            'source' => $sourceSystem,
            'gate_id' => $gateId,
            'check_out' => $checkOutTime,
        ];

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $member = $this->lockMember($memberId);
            if (!$member) {
                throw $this->notFoundException('Member not found', 404);
            }

            $attendance = $this->lockActiveAttendance($memberId);
            if (!$attendance) {
                $recent = $this->findRecentSuccessfulJournal($memberId, 'checkout', $sourceSystem, $gateId, $retryWindowSeconds);
                if ($recent) {
                    $response = $this->decodeResponsePayload($recent['response_payload'] ?? null);
                    if (!$response) {
                        $response = $this->buildCheckOutResponse($member, [
                            'id' => $recent['attendance_id'] ?? null,
                            'check_in' => null,
                            'check_out' => $this->currentTimestamp(),
                            'duration_minutes' => 0,
                            'exit_gate_id' => $gateId,
                        ], true);
                    }
                    $response['success'] = true;
                    $response['duplicate'] = true;
                    $this->insertJournal([
                        'operation_type' => 'checkout',
                        'source_system' => $sourceSystem,
                        'gender' => $this->gender,
                        'member_id' => $memberId,
                        'attendance_id' => $recent['attendance_id'] ?? null,
                        'gate_id' => $gateId,
                        'request_signature' => $this->buildSignature('checkout', $memberId, $sourceSystem, $gateId, $recent['attendance_id'] ?? null),
                        'status' => 'duplicate',
                        'request_payload' => $requestPayload,
                        'response_payload' => $response,
                        'error_message' => null,
                    ]);
                    if ($ownsTransaction) {
                        $this->db->commit();
                    }
                    return $response;
                }

                throw $this->notFoundException('No active attendance session found', 409);
            }

            $durationMinutes = $this->calculateDurationMinutes($attendance['check_in'], $checkOutTime);
            $journalId = $this->insertJournal([
                'operation_type' => 'checkout',
                'source_system' => $sourceSystem,
                'gender' => $this->gender,
                'member_id' => $memberId,
                'attendance_id' => (int)$attendance['id'],
                'gate_id' => $gateId,
                'request_signature' => $this->buildSignature('checkout', $memberId, $sourceSystem, $gateId, (int)$attendance['id']),
                'status' => 'started',
                'request_payload' => $requestPayload,
                'response_payload' => null,
                'error_message' => null,
            ]);

            $this->updateAttendanceCheckout((int)$attendance['id'], $checkOutTime, $durationMinutes, $gateId, $sourceSystem, $journalId);
            $this->setMemberCheckedInState($memberId, 0);

            $attendance['check_out'] = $checkOutTime;
            $attendance['duration_minutes'] = $durationMinutes;
            $attendance['exit_gate_id'] = $gateId;

            $response = $this->buildCheckOutResponse($member, $attendance, false);
            $this->updateJournal($journalId, [
                'status' => 'success',
                'response_payload' => $response,
            ]);

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $response;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->rollbackQuietly();
            }
            if ($e instanceof RuntimeException && (int)$e->getCode() === 404) {
                return $this->failure($e->getMessage(), 404);
            }
            if ((int)$e->getCode() === 409) {
                return $this->failure($e->getMessage(), 409);
            }
            return $this->failure('Failed to record check-out: ' . $e->getMessage(), 500);
        }
    }

    private function lockMember(int $memberId): ?array {
        $query = "SELECT id, member_code, name, is_checked_in FROM {$this->memberTable} WHERE id = :id FOR UPDATE";
        return $this->fetchOne($query, [':id' => $memberId]);
    }

    private function lockActiveAttendance(int $memberId): ?array {
        $query = "SELECT * FROM {$this->attendanceTable}
                  WHERE member_id = :member_id AND check_out IS NULL
                  ORDER BY check_in DESC, id DESC
                  LIMIT 1 FOR UPDATE";
        return $this->fetchOne($query, [':member_id' => $memberId]);
    }

    private function lockAttendanceById(int $attendanceId, int $memberId): ?array {
        $query = "SELECT * FROM {$this->attendanceTable} WHERE id = :id AND member_id = :member_id FOR UPDATE";
        return $this->fetchOne($query, [':id' => $attendanceId, ':member_id' => $memberId]);
    }

    private function countAttendanceForDay(int $memberId, string $timestamp): int {
        $dayStart = date('Y-m-d 00:00:00', strtotime($timestamp));
        $dayEnd = date('Y-m-d 23:59:59', strtotime($timestamp));
        $query = "SELECT COUNT(*) AS total
                  FROM {$this->attendanceTable}
                  WHERE member_id = :member_id
                    AND check_in >= :day_start
                    AND check_in <= :day_end";
        $row = $this->fetchOne($query, [
            ':member_id' => $memberId,
            ':day_start' => $dayStart,
            ':day_end' => $dayEnd,
        ]);

        return (int)($row['total'] ?? 0);
    }

    private function insertAttendance(array $data): int {
        $query = "INSERT INTO {$this->attendanceTable}
            (member_id, check_in, check_out, duration_minutes, is_first_entry_today, entry_gate_id, exit_gate_id, write_source, write_signature, write_journal_id)
            VALUES
            (:member_id, :check_in, :check_out, :duration_minutes, :is_first_entry_today, :entry_gate_id, :exit_gate_id, :write_source, :write_signature, :write_journal_id)";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':member_id', (int)$data['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':check_in', (string)$data['check_in'], PDO::PARAM_STR);
        $stmt->bindValue(':check_out', $data['check_out'] ?? null, $data['check_out'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':duration_minutes', $data['duration_minutes'] ?? null, $data['duration_minutes'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':is_first_entry_today', (int)($data['is_first_entry_today'] ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(':entry_gate_id', $data['entry_gate_id'] ?? null, $data['entry_gate_id'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':exit_gate_id', $data['exit_gate_id'] ?? null, $data['exit_gate_id'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':write_source', $data['write_source'] ?? null, $data['write_source'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':write_signature', $data['write_signature'] ?? null, $data['write_signature'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':write_journal_id', $data['write_journal_id'] ?? null, $data['write_journal_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);

        if (!$stmt->execute()) {
            throw new RuntimeException('Attendance insert failed');
        }

        return (int)$this->db->lastInsertId();
    }

    private function updateAttendanceCheckout(int $attendanceId, string $checkOutTime, int $durationMinutes, ?string $gateId, string $sourceSystem, int $journalId): void {
        $query = "UPDATE {$this->attendanceTable}
                  SET check_out = :check_out,
                      duration_minutes = :duration_minutes,
                      exit_gate_id = :exit_gate_id,
                      write_source = :write_source,
                      write_signature = :write_signature,
                      write_journal_id = :write_journal_id
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':check_out', $checkOutTime, PDO::PARAM_STR);
        $stmt->bindValue(':duration_minutes', $durationMinutes, PDO::PARAM_INT);
        $stmt->bindValue(':exit_gate_id', $gateId, $gateId !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':write_source', $sourceSystem, PDO::PARAM_STR);
        $stmt->bindValue(':write_signature', $this->buildSignature('checkout', (int)$this->fetchAttendanceMemberId($attendanceId), $sourceSystem, $gateId, $attendanceId), PDO::PARAM_STR);
        $stmt->bindValue(':write_journal_id', $journalId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $attendanceId, PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new RuntimeException('Attendance checkout update failed');
        }
    }

    private function fetchAttendanceMemberId(int $attendanceId): int {
        $row = $this->fetchOne("SELECT member_id FROM {$this->attendanceTable} WHERE id = :id", [':id' => $attendanceId]);
        return (int)($row['member_id'] ?? 0);
    }

    private function setMemberCheckedInState(int $memberId, int $state): void {
        $query = "UPDATE {$this->memberTable} SET is_checked_in = :state WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':state', $state, PDO::PARAM_INT);
        $stmt->bindValue(':id', $memberId, PDO::PARAM_INT);
        if (!$stmt->execute()) {
            throw new RuntimeException('Member status update failed');
        }
    }

    private function insertJournal(array $data): int {
        $query = "INSERT INTO {$this->journalTable}
            (operation_type, source_system, gender, member_id, attendance_id, gate_id, request_signature, status, request_payload, response_payload, error_message)
            VALUES
            (:operation_type, :source_system, :gender, :member_id, :attendance_id, :gate_id, :request_signature, :status, :request_payload, :response_payload, :error_message)";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':operation_type', $data['operation_type'], PDO::PARAM_STR);
        $stmt->bindValue(':source_system', $data['source_system'], PDO::PARAM_STR);
        $stmt->bindValue(':gender', $data['gender'], PDO::PARAM_STR);
        $stmt->bindValue(':member_id', (int)$data['member_id'], PDO::PARAM_INT);
        $stmt->bindValue(':attendance_id', $data['attendance_id'] ?? null, isset($data['attendance_id']) && $data['attendance_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':gate_id', $data['gate_id'] ?? null, $data['gate_id'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':request_signature', $data['request_signature'] ?? null, $data['request_signature'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
        $stmt->bindValue(':request_payload', isset($data['request_payload']) ? $this->encodeJson($data['request_payload']) : null, isset($data['request_payload']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':response_payload', isset($data['response_payload']) ? $this->encodeJson($data['response_payload']) : null, isset($data['response_payload']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':error_message', $data['error_message'] ?? null, $data['error_message'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        if (!$stmt->execute()) {
            throw new RuntimeException('Attendance journal insert failed');
        }

        return (int)$this->db->lastInsertId();
    }

    private function updateJournal(int $journalId, array $data): void {
        $fields = [];
        $params = [':id' => $journalId];

        if (array_key_exists('attendance_id', $data)) {
            $fields[] = 'attendance_id = :attendance_id';
            $params[':attendance_id'] = $data['attendance_id'];
        }
        if (array_key_exists('status', $data)) {
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }
        if (array_key_exists('response_payload', $data)) {
            $fields[] = 'response_payload = :response_payload';
            $params[':response_payload'] = $this->encodeJson($data['response_payload']);
        }
        if (array_key_exists('error_message', $data)) {
            $fields[] = 'error_message = :error_message';
            $params[':error_message'] = $data['error_message'];
        }

        if (!$fields) {
            return;
        }

        $query = "UPDATE {$this->journalTable} SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Attendance journal update failed');
        }
    }

    private function findRecentSuccessfulJournal(int $memberId, string $operationType, string $sourceSystem, ?string $gateId, int $windowSeconds): ?array {
        $clauses = [
            'member_id = :member_id',
            'operation_type = :operation_type',
            'source_system = :source_system',
            "(status = 'success' OR status = 'duplicate')",
            'created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$windowSeconds . ' SECOND)',
        ];
        $params = [
            ':member_id' => $memberId,
            ':operation_type' => $operationType,
            ':source_system' => $sourceSystem,
        ];
        if ($gateId === null || $gateId === '') {
            $clauses[] = "(gate_id IS NULL OR gate_id = '')";
        } else {
            $clauses[] = 'gate_id = :gate_id';
            $params[':gate_id'] = $gateId;
        }

        $query = "SELECT * FROM {$this->journalTable} WHERE " . implode(' AND ', $clauses) . " ORDER BY id DESC LIMIT 1";
        return $this->fetchOne($query, $params);
    }

    private function buildCheckInResponse(array $member, array $attendance, bool $duplicate): array {
        return [
            'success' => true,
            'message' => 'Check-in recorded successfully',
            'attendance_id' => (int)$attendance['id'],
            'check_in' => $attendance['check_in'],
            'duplicate' => $duplicate,
            'attendance' => [
                'id' => (int)$attendance['id'],
                'check_in' => $attendance['check_in'],
                'check_out' => null,
                'duration_minutes' => null,
                'is_first_entry_today' => (int)($attendance['is_first_entry_today'] ?? 1),
                'entry_gate_id' => $attendance['entry_gate_id'] ?? null,
            ],
            'member' => [
                'id' => (int)$member['id'],
                'name' => $member['name'] ?? null,
                'member_code' => $member['member_code'] ?? null,
                'is_checked_in' => 1,
            ],
        ];
    }

    private function buildCheckOutResponse(array $member, array $attendance, bool $duplicate): array {
        $durationMinutes = (int)($attendance['duration_minutes'] ?? 0);
        $durationText = $this->formatDurationText($durationMinutes);

        return [
            'success' => true,
            'message' => 'Check-out recorded successfully',
            'attendance_id' => (int)$attendance['id'],
            'check_out' => $attendance['check_out'],
            'duration_minutes' => $durationMinutes,
            'duplicate' => $duplicate,
            'attendance' => [
                'id' => (int)$attendance['id'],
                'check_in' => $attendance['check_in'] ?? null,
                'check_out' => $attendance['check_out'],
                'duration_minutes' => $durationMinutes,
                'exit_gate_id' => $attendance['exit_gate_id'] ?? null,
            ],
            'member' => [
                'id' => (int)$member['id'],
                'name' => $member['name'] ?? null,
                'member_code' => $member['member_code'] ?? null,
                'check_in_time' => $attendance['check_in'] ?? null,
                'duration' => $durationText,
                'duration_minutes' => $durationMinutes,
            ],
        ];
    }

    private function buildSignature(string $operationType, int $memberId, string $sourceSystem, ?string $gateId = null, ?int $attendanceId = null): string {
        $parts = [$operationType, $this->gender, (string)$memberId, $sourceSystem];
        if ($gateId !== null && $gateId !== '') {
            $parts[] = 'gate:' . $gateId;
        }
        if ($attendanceId !== null) {
            $parts[] = 'attendance:' . $attendanceId;
        }
        return implode('|', $parts);
    }

    private function calculateDurationMinutes(string $checkIn, string $checkOut): int {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);
        if ($in === false || $out === false) {
            return 0;
        }

        return max(0, (int)floor(($out - $in) / 60));
    }

    private function formatDurationText(int $minutes): string {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0 && $remaining === 0) {
            return '0 minutes';
        }

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        if ($remaining > 0) {
            $parts[] = $remaining . ' minute' . ($remaining === 1 ? '' : 's');
        }

        return implode(' ', $parts);
    }

    private function normalizeSource(string $source): string {
        $source = trim($source);
        if ($source === '') {
            return 'member-profile';
        }
        return substr($source, 0, 50);
    }

    private function normalizeNullableString($value, int $maxLength): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return substr($value, 0, $maxLength);
    }

    private function normalizeDateTime($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string)$value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function currentTimestamp(): string {
        return date('Y-m-d H:i:s');
    }

    private function encodeJson($value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeResponsePayload($value): ?array {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    private function fetchOne(string $query, array $params = []): ?array {
        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function rollbackQuietly(): void {
        if ($this->db->inTransaction()) {
            try {
                $this->db->rollBack();
            } catch (Throwable $ignored) {
            }
        }
    }

    private function notFoundException(string $message, int $code): RuntimeException {
        return new RuntimeException($message, $code);
    }

    private function failure(string $message, int $statusCode): array {
        return [
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
        ];
    }
}
