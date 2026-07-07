<?php
/**
 * Shared gym-access decision — the single source of truth for "may this member
 * come in right now?". Mirrors the rule already used by api/gate.php (RFID/ESP32
 * gate) so the ZKTeco F22 path and the RFID path never disagree:
 *   - membership must be active (calculated status), and
 *   - no outstanding fee (total_due_amount <= 0).
 */
class AccessDecision {
    /**
     * @param array $member A member row (as returned by Member::getById/getAll,
     *                       i.e. including calculated_status + total_due_amount).
     * @return array{allowed:bool, code:string, reason:string, due_amount:float}
     */
    public static function evaluate(array $member): array {
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
