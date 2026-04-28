<?php
/**
 * Member Consent Model
 */

class MemberConsent {
    private $conn;
    private $table = 'member_consent';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getByMember($memberTable, $memberId) {
        $query = "SELECT * FROM {$this->table} WHERE member_table = :member_table AND member_id = :member_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':member_table', $memberTable, PDO::PARAM_STR);
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function hasGrantedConsent($memberTable, $memberId) {
        $record = $this->getByMember($memberTable, $memberId);
        return $record && ($record['consent_status'] === 'granted');
    }
}
