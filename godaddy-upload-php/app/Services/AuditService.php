<?php
/**
 * Reading the audit log for the admin dashboard.
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\AuditLog;

final class AuditService
{
    /** The log is unbounded, so limit=100000 must not pull it all into memory. */
    private const MAX_LIMIT = 200;

    /**
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $options = []): array
    {
        $where = [];
        $params = [];

        if (!empty($options['entityId'])) {
            $where[] = 'entity_id = :entity_id';
            $params['entity_id'] = $options['entityId'];
        }
        if (!empty($options['action'])) {
            $where[] = 'action = :action';
            $params['action'] = $options['action'];
        }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = min(self::MAX_LIMIT, max(1, (int) ($options['limit'] ?? 50)));

        // Matches the idx_audit_created index, so this is a scan-free sort.
        $rows = Database::all("SELECT * FROM audit_logs$sql ORDER BY created_at DESC LIMIT $limit", $params);

        return array_map([AuditLog::class, 'toJson'], $rows);
    }
}
