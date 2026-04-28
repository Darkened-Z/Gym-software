<?php
/**
 * Sync Helper Utilities
 */

class SyncHelper
{
    /**
     * Mark a record so that it will be re-synced during the next sync run.
     * We simply remove any existing sync_log entry for the record, which
     * causes sync-local.php to pick it up again (because LEFT JOIN yields NULL).
     */
    public static function markRecordForSync(PDO $db, string $tableName, int $recordId): void
    {
        if ($recordId <= 0 || $tableName === '') {
            return;
        }

        try {
            $query = "DELETE FROM sync_log WHERE table_name = :table_name AND record_id = :record_id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
            $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('markRecordForSync skipped: ' . $e->getMessage());
        }
    }
}

