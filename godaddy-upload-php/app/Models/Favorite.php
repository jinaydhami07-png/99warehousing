<?php
/**
 * Favourite — a saved listing, one row per (user, property).
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Support\ObjectId;

final class Favorite
{
    /**
     * Insert, or do nothing if it is already there.
     *
     * INSERT IGNORE rather than check-then-insert: the check version races
     * with a double-tap on the heart and the loser hits the unique index
     * with a confusing 500. This simply returns success either way.
     */
    public static function add(string $userId, string $propertyId): void
    {
        Database::run(
            'INSERT IGNORE INTO favorites (id, user_id, property_id, created_at)
             VALUES (:id, :user_id, :property_id, :created_at)',
            [
                'id' => ObjectId::generate(),
                'user_id' => $userId,
                'property_id' => $propertyId,
                'created_at' => now_utc(),
            ]
        );
    }

    public static function remove(string $userId, string $propertyId): void
    {
        Database::run(
            'DELETE FROM favorites WHERE user_id = :user_id AND property_id = :property_id',
            ['user_id' => $userId, 'property_id' => $propertyId]
        );
    }

    /**
     * A user's saved listings, newest save first.
     *
     * Joined rather than fetched in a loop: a list of 50 favourites would
     * otherwise be 51 queries. Unapproved listings are filtered out here —
     * a listing can be taken down after it was saved, and the saver should
     * not keep a private window onto it.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listFor(string $userId): array
    {
        return Database::all(
            "SELECT p.*, f.created_at AS favorited_at
               FROM favorites f
               JOIN properties p ON p.id = f.property_id
              WHERE f.user_id = :user_id AND p.status = 'approved'
              ORDER BY f.created_at DESC",
            ['user_id' => $userId]
        );
    }
}
