<?php
/**
 * Review — a rating and comment left by a signed-in user against one
 * property.
 *
 * The detail page used to carry two reviews written into the HTML,
 * attributed by name and job title to staff at named logistics companies.
 * They appeared on every property, said the same thing about all of them,
 * and named businesses that never wrote them. Reviews are rows now, or they
 * are not shown.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Support\ObjectId;

final class Review
{
    public const STATUSES = ['pending', 'published', 'rejected'];

    public static function findById(?string $id): ?array
    {
        if ($id === null || !ObjectId::isValid($id)) {
            return null;
        }
        return Database::first('SELECT * FROM reviews WHERE id = :id', ['id' => $id]);
    }

    public static function findByAuthor(string $propertyId, string $authorId): ?array
    {
        return Database::first(
            'SELECT * FROM reviews WHERE property_id = :p AND author_id = :a',
            ['p' => $propertyId, 'a' => $authorId]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): array
    {
        $id = ObjectId::generate();
        $now = now_utc();

        Database::insert('reviews', [
            'id' => $id,
            'property_id' => $data['propertyId'],
            'author_id' => $data['authorId'],
            'author_name' => $data['authorName'],
            'author_role' => $data['authorRole'] ?? null,
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'],
            /* Always pending on write, never taken from the payload — the
               moderation gate is the whole point. */
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::findById($id) ?? [];
    }

    /** @param array<string,mixed> $data */
    public static function replace(string $id, array $data): array
    {
        Database::update('reviews', $id, [
            'author_name' => $data['authorName'],
            'author_role' => $data['authorRole'] ?? null,
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'],
            /* An edited review goes back through moderation. Otherwise a
               published review could be swapped for anything after the fact. */
            'status' => 'pending',
            'rejection_reason' => null,
            'updated_at' => now_utc(),
        ]);

        return self::findById($id) ?? [];
    }

    public static function delete(string $id): void
    {
        Database::run('DELETE FROM reviews WHERE id = :id', ['id' => $id]);
    }

    /**
     * Row → JSON.
     *
     * `author_id` is dropped: it has no use in the browser and would tie a
     * public opinion to a real user record.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toJson(array $row): array
    {
        return [
            'id' => $row['id'],
            'property' => $row['property_id'],
            'authorName' => $row['author_name'],
            'authorRole' => $row['author_role'],
            'rating' => (int) $row['rating'],
            'comment' => $row['comment'],
            'status' => $row['status'],
            'rejectionReason' => $row['rejection_reason'],
            'createdAt' => iso8601($row['created_at'] ?? null),
            'updatedAt' => iso8601($row['updated_at'] ?? null),
        ];
    }
}
