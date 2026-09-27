<?php
/**
 * Audit log — an append-only record of who changed what.
 *
 * Exists so moderation decisions are reviewable after the fact: which admin
 * approved a listing, the values before and after, and when.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Config\Logger;
use App\Support\ObjectId;

final class AuditLog
{
    /**
     * Write an entry.
     *
     * ── Never throws ─────────────────────────────────────────────────
     * A logging failure must not roll back or fail the business operation
     * that triggered it. Approving a listing has to succeed even if the
     * audit table is full, locked or missing.
     * ────────────────────────────────────────────────────────────────
     *
     * @param array<string,mixed>|null $actor A user row, or null for the system.
     */
    public static function record(
        ?array $actor,
        string $action,
        ?string $entityId = null,
        string $entity = 'property',
        $before = null,
        $after = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            Database::insert('audit_logs', [
                'id' => ObjectId::generate(),
                'actor_id' => $actor['id'] ?? null,
                'actor_email' => $actor['email'] ?? null,
                'actor_role' => $actor['role'] ?? null,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'before' => json_store($before),
                'after' => json_store($after),
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 500),
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Audit log write failed', ['action' => $action, 'err' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $row */
    public static function toJson(array $row): array
    {
        return [
            'id' => $row['id'],
            'action' => $row['action'],
            'entity' => $row['entity'],
            'entityId' => $row['entity_id'],
            'actorEmail' => $row['actor_email'],
            'actorRole' => $row['actor_role'],
            'before' => json_column($row['before'] ?? null, null),
            'after' => json_column($row['after'] ?? null, null),
            'createdAt' => iso8601($row['created_at'] ?? null),
        ];
    }
}
