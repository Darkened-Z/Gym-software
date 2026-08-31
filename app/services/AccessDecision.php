<?php
/**
 * Shared gym-access decision — the single source of truth for "may this member
 * come in right now?". Mirrors the rule already used by api/gate.php (RFID/ESP32
 * gate) so the ZKTeco F22 path and the RFID path never disagree:
 *   - gym subscription must not be locked (LicenseHelper — checked only when a
 *     PDO is passed so old callers still work),
 *   - membership must be active (calculated status), and
 *   - no outstanding fee (total_due_amount <= 0).
 *
 * The gym-subscription check turns every allow/deny answer into a denial once
 * the operator panel marks the gym as past-grace, so the bridge (api/f22.php
 * sync_list) yanks every fingerprint off the terminal instead of just some.
 */

require_once __DIR__ . '/../helpers/LicenseHelper.php';

class AccessDecision {
    /**
     * @param array    $member A member row (as returned by Member::getById/getAll,
     *                          i.e. including calculated_status + total_due_amount).
     * @param ?PDO     $db     Optional. When passed, the gym's own subscription
     *                          is checked first: a locked (past-grace) gym denies
     *                          everyone. Kept optional so callers that were
     *                          already reading the license status somewhere else
     *                          don't have to.
     * @return array{allowed:bool, code:string, reason:string, due_amount:float}
     */
    public static function evaluate(array $member, ?PDO $db = null): array {
        if ($db !== null) {
            $lic = (new LicenseHelper($db))->getStatus();
            if (!empty($lic['locked'])) {
                return [
                    'allowed' => false,
                    'code' => 'GYM_LOCKED',
                    'reason' => 'Gym subscription expired — access is blocked. Please contact the gym owner.',
                    'due_amount' => 0.0,
                ];
            }
        }

        $status = $member['calculated_status'] ?? $member['status'] ?? 'inactive';
        $due = round((float)($member['total_due_amount'] ?? 0), 2);

        if ($status !== 'active') {
            return [
                'allowed' => false,
                'code' => 'INACTIVE',
                'reason' => 'Membership inactive — please renew at reception.',
                'due_amount' => $due,
            ];
        }
        if ($due > 0) {
            return [
                'allowed' => false,
                'code' => 'FEE_DUE',
                'reason' => 'Fee payment pending: Rs. ' . number_format($due, 0) . '. Please pay at reception.',
                'due_amount' => $due,
            ];
        }

        // Optional STRICT DOOR POLICY (per gym, opt-in).
        //
        // The default model treats a member as active until they accumulate a
        // recorded debt (total_due_amount) or fall 2 months past due. Some gyms
        // want the door to enforce the fee due date directly: fee date passed by
        // more than N grace days -> no entry, even if no debt amount was keyed in.
        //
        // Controlled by gym_settings.door_block_overdue_days:
        //   absent / empty  -> OFF (unchanged behaviour — other gyms untouched)
        //   integer N >= 0  -> block members whose next_fee_due_date is more than
        //                      N days in the past.
        // Fails OPEN: if the setting can't be read, we do NOT block.
        if ($db !== null) {
            $graceDays = self::overdueGraceDays($db);
            if ($graceDays !== null) {
                $dueDate = $member['next_fee_due_date'] ?? null;
                if ($dueDate) {
                    $cutoff = date('Y-m-d', strtotime("-{$graceDays} days"));
                    if ($dueDate < $cutoff) {
                        return [
                            'allowed' => false,
                            'code' => 'OVERDUE',
                            'reason' => 'Fee overdue since ' . $dueDate . ' — please renew at reception.',
                            'due_amount' => $due,
                        ];
                    }
                }
            }
        }

        return [
            'allowed' => true,
            'code' => 'OK',
            'reason' => 'Access granted.',
            'due_amount' => 0.0,
        ];
    }

    /** Per-request cache: false = not yet read, null = off, int = grace days. */
    private static $graceCache = false;

    /** Read gym_settings.door_block_overdue_days once per request. Fails open. */
    private static function overdueGraceDays(PDO $db): ?int {
        if (self::$graceCache !== false) {
            return self::$graceCache;
        }
        self::$graceCache = null;
        try {
            $stmt = $db->query("SELECT setting_value FROM gym_settings WHERE setting_key = 'door_block_overdue_days' LIMIT 1");
            $val = $stmt ? $stmt->fetchColumn() : false;
            if ($val !== false && $val !== null && trim((string)$val) !== '') {
                $n = (int)$val;
                if ($n >= 0) {
                    self::$graceCache = $n;
                }
            }
        } catch (Throwable $e) {
            // gym_settings missing or unreadable -> stay lenient (never block on error).
            error_log('AccessDecision::overdueGraceDays: ' . $e->getMessage());
        }
        return self::$graceCache;
    }
}
