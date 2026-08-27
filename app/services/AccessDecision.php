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
        return [
            'allowed' => true,
            'code' => 'OK',
            'reason' => 'Access granted.',
            'due_amount' => 0.0,
        ];
    }
}
