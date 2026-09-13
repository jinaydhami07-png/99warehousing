<?php
/**
 * Listings: the public feed, the detail page, submission, moderation.
 *
 * Ownership and visibility decisions all live here rather than in the
 * controllers, so a new route cannot accidentally skip them.
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Logger;
use App\Http\ApiError;
use App\Middleware\Auth;
use App\Models\AuditLog;
use App\Models\Property;
use App\Models\User;

final class PropertyService
{
    /**
     * Sort options, as a fixed map.
     *
     * The value from the query string selects a key here; it is never
     * interpolated into the SQL. An allow-list is the only safe way to build
     * an ORDER BY from user input — it is the one clause a placeholder
     * cannot stand in for.
     */
    private const SORTS = [
        'rate-asc' => 'rate ASC',
        'rate-desc' => 'rate DESC',
        'area-asc' => 'area ASC',
        'area-desc' => 'area DESC',
        'newest' => 'created_at DESC',
        'oldest' => 'created_at ASC',
    ];

    /**
     * Build the WHERE clause shared by the public feed and the admin list.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildFilter(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['city'])) {
            // Exact match, case-insensitive by the column's collation.
            $where[] = 'city = :city';
            $params['city'] = trim((string) $filters['city']);
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['grade'])) {
            $where[] = 'grade = :grade';
            $params['grade'] = $filters['grade'];
        }
        if (isset($filters['minRate'])) {
            $where[] = 'rate >= :minRate';
            $params['minRate'] = $filters['minRate'];
        }
        if (isset($filters['maxRate'])) {
            $where[] = 'rate <= :maxRate';
            $params['maxRate'] = $filters['maxRate'];
        }
        if (isset($filters['minArea'])) {
            $where[] = 'area >= :minArea';
            $params['minArea'] = $filters['minArea'];
        }
        if (isset($filters['maxArea'])) {
            $where[] = 'area <= :maxArea';
            $params['maxArea'] = $filters['maxArea'];
        }
        if (!empty($filters['q'])) {
            /* The Mongo build used a $text index. LIKE across the same four
               fields gives the same results for a catalogue this size, and
               unlike FULLTEXT it needs no minimum word length, no stopword
               list, and works on every MySQL and MariaDB version a shared
               host might be running. */
            $where[] = Database::likeClause(['name', 'city', 'locality', 'description'], 'q', (string) $filters['q'], $params);
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function listPublic(array $options): array
    {
        // Forced, not defaulted: the public feed shows approved listings only.
        $options['status'] = 'approved';
        return self::query($options, true);
    }

    /** Every status — the admin queue. */
    public static function listAll(array $options): array
    {
        return self::query($options, false);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    private static function query(array $options, bool $asCards): array
    {
        [$sql, $params] = self::buildFilter($options);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM properties$sql", $params);

        $order = self::SORTS[$options['sort'] ?? ''] ?? self::SORTS['newest'];
        $limit = (int) $options['limit'];
        $offset = (int) $options['skip'];

        $rows = Database::all(
            "SELECT * FROM properties$sql ORDER BY $order LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items' => array_map($asCards ? [Property::class, 'toCard'] : [Property::class, 'toJson'], $rows),
            'total' => $total,
        ];
    }

    /**
     * One listing.
     *
     * An unapproved listing is a 404 for everyone but its owner and an
     * admin — a 403 would confirm that a listing with that id exists, which
     * is exactly what a rejected submission should not advertise.
     */
    public static function getById(string $id, ?array $viewer): array
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }

        if ($row['status'] !== 'approved') {
            $isOwner = $viewer !== null && (string) $row['owner_id'] === (string) $viewer['id'];
            if (!$isOwner && !Auth::isAdmin($viewer)) {
                throw ApiError::notFound('Property not found');
            }
        }

        Property::incrementViews($id);

        return Property::toJson($row);
    }

    /**
     * The owner's contact details.
     *
     * The one thing a signed-out visitor must never obtain, which is why the
     * route requires a session rather than degrading — and why every call is
     * written to the audit log.
     */
    public static function getContact(string $id, array $viewer): array
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }

        $isAdmin = Auth::isAdmin($viewer);
        $isOwner = (string) $row['owner_id'] === (string) $viewer['id'];
        if ($row['status'] !== 'approved' && !$isAdmin && !$isOwner) {
            throw ApiError::notFound('Property not found');
        }

        $owner = User::findById((string) $row['owner_id']);

        AuditLog::record($viewer, 'property.contact_viewed', $id, 'property', null, ['property' => $row['name']]);

        return [
            'name' => $owner['name'] ?? $row['owner_name'] ?? 'Listing owner',
            'company' => $owner['company'] ?? null,
            /* Falls back to the listing's own owner_name when the account
               has no number, so the panel is never a dead end. */
            'mobile' => $owner['mobile'] ?? null,
            'email' => $owner['email'] ?? null,
        ];
    }

    /**
     * Submit a listing.
     *
     * @param array<string,mixed> $payload Already validated.
     * @param array<string,mixed> $owner   The authenticated user row.
     */
    public static function create(array $payload, array $owner): array
    {
        $isAdmin = ($owner['role'] ?? '') === 'admin';

        /* Derived from the caller's role, never from the payload, and
           applied after it — so a crafted body carrying status or
           isVerified is overwritten rather than honoured. The create schema
           rejects those keys outright; this is the second lock on the same
           door. */
        $payload['status'] = $isAdmin ? ($payload['status'] ?? 'approved') : 'pending';
        $payload['isVerified'] = $isAdmin ? (($payload['isVerified'] ?? true) !== false) : false;
        /* An admin entering a listing on behalf of a real owner may name
           them. For everyone else the account name wins, so a submitter
           cannot attribute a listing to someone else. */
        $payload['ownerName'] = ($isAdmin && !empty($payload['ownerName'])) ? $payload['ownerName'] : $owner['name'];

        $row = Property::create($payload, (string) $owner['id']);

        /* Tag the uploaded images with the listing, so deleting it can find
           and remove them and no photo is left as an orphan. Best-effort:
           the listing is already saved, and losing the tag is housekeeping,
           not a reason to fail the submission. */
        ImageService::attachToProperty($payload, (string) $row['id']);

        AuditLog::record(
            $owner,
            $isAdmin ? 'property.created' : 'property.submitted',
            (string) $row['id'],
            'property',
            null,
            ['name' => $row['name'], 'rate' => $row['rate'], 'status' => $row['status']]
        );

        Logger::info('Property submitted', ['propertyId' => $row['id'], 'ownerId' => $owner['id']]);
        return Property::toJson($row);
    }

    /**
     * Edit a listing.
     *
     * An owner's edit sends the listing back to pending: the version an
     * admin approved is not the version that would then be live.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $actor
     */
    public static function update(string $id, array $patch, array $actor): array
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }

        $isAdmin = Auth::isAdmin($actor);
        if (!$isAdmin && (string) $row['owner_id'] !== (string) $actor['id']) {
            throw ApiError::forbidden('You can only edit your own listings');
        }

        $before = ['name' => $row['name'], 'rate' => $row['rate'], 'status' => $row['status']];

        $forced = [];
        if (!$isAdmin) {
            $forced['status'] = 'pending';
            $forced['is_verified'] = 0;
        }

        /* Clear a stale rejection note whenever the listing is no longer
           rejected. Without this, an owner who fixes the problem and
           resubmits leaves "documents incomplete" attached to a listing
           that is not rejected any more — and the detail page shows it. */
        $nextStatus = $forced['status'] ?? ($patch['status'] ?? $row['status']);
        if ($nextStatus !== 'rejected') {
            $forced['rejection_reason'] = null;
        }

        $updated = Property::update($id, $patch, $forced);
        if ($updated === null) {
            throw ApiError::notFound('Property not found');
        }

        if (isset($patch['images']) || isset($patch['floorPlan'])) {
            ImageService::attachToProperty($patch, $id);
        }

        AuditLog::record($actor, 'property.edited', $id, 'property', $before, [
            'name' => $updated['name'],
            'rate' => $updated['rate'],
            'status' => $updated['status'],
        ]);

        return Property::toJson($updated);
    }

    /** @param array<string,mixed> $actor */
    public static function remove(string $id, array $actor): void
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }
        if (!Auth::isAdmin($actor) && (string) $row['owner_id'] !== (string) $actor['id']) {
            throw ApiError::forbidden('You can only delete your own listings');
        }

        /* Files first, while the image rows still point at them. Deleting
           the listing first would cascade the rows away and leave the files
           on disk forever, taking up space and attached to nothing. */
        ImageService::removeForProperty($id);

        Property::delete($id);

        AuditLog::record($actor, 'property.deleted', $id, 'property', ['name' => $row['name']]);
    }

    /** @param array<string,mixed> $admin */
    public static function approve(string $id, array $admin): array
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }

        $updated = Property::update($id, [], [
            'status' => 'approved',
            'is_verified' => 1,
            'rejection_reason' => null,
        ]);

        AuditLog::record($admin, 'property.approved', $id, 'property', ['status' => $row['status']], ['status' => 'approved']);
        Logger::info('Property approved', ['propertyId' => $id]);

        return Property::toJson($updated ?? $row);
    }

    /** @param array<string,mixed> $admin */
    public static function reject(string $id, string $reason, array $admin): array
    {
        $row = Property::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Property not found');
        }

        $updated = Property::update($id, [], [
            'status' => 'rejected',
            'is_verified' => 0,
            'rejection_reason' => $reason,
        ]);

        AuditLog::record($admin, 'property.rejected', $id, 'property', ['status' => $row['status']], ['status' => 'rejected', 'reason' => $reason]);
        Logger::info('Property rejected', ['propertyId' => $id]);

        return Property::toJson($updated ?? $row);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listMine(string $ownerId): array
    {
        $rows = Database::all(
            'SELECT * FROM properties WHERE owner_id = :owner ORDER BY created_at DESC',
            ['owner' => $ownerId]
        );
        return array_map([Property::class, 'toJson'], $rows);
    }

    /**
     * The dashboard's four counters.
     *
     * One grouped query rather than four COUNT(*)s: the same numbers, a
     * quarter of the round trips.
     *
     * @return array<string,int>
     */
    public static function stats(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM properties GROUP BY status');

        $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $stats['total'] += $n;
            if (array_key_exists($row['status'], $stats)) {
                $stats[$row['status']] = $n;
            }
        }
        return $stats;
    }
}
