<?php
/**
 * Reviews: writing, reading the published feed, and moderation.
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Http\ApiError;
use App\Middleware\Auth;
use App\Models\AuditLog;
use App\Models\Property;
use App\Models\Review;

final class ReviewService
{
    /**
     * Write or replace this user's review of a listing.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $author
     * @return array{review:array<string,mixed>,replaced:bool}
     */
    public static function create(string $propertyId, array $payload, array $author): array
    {
        $property = Property::findById($propertyId);
        if ($property === null) {
            throw ApiError::notFound('Property not found');
        }

        /* Only a live listing can be reviewed. Otherwise an unapproved or
           rejected one could quietly collect ratings that appear the moment
           it goes live. */
        if ($property['status'] !== 'approved') {
            throw ApiError::badRequest('This listing is not open for reviews yet');
        }
        if ((string) $property['owner_id'] === (string) $author['id']) {
            throw ApiError::forbidden('You cannot review your own listing');
        }

        $data = [
            'propertyId' => $propertyId,
            'authorId' => $author['id'],
            'authorName' => $author['name'] ?: 'Verified user',
            'authorRole' => $payload['authorRole'] ?? null,
            'rating' => $payload['rating'],
            'comment' => $payload['comment'],
        ];

        $existing = Review::findByAuthor($propertyId, (string) $author['id']);
        $review = $existing !== null
            ? Review::replace((string) $existing['id'], $data)
            : Review::create($data);

        return ['review' => Review::toJson($review), 'replaced' => $existing !== null];
    }

    /**
     * The published reviews for one listing, with the summary the page
     * prints above them.
     *
     * The average is computed by the database over ALL published reviews,
     * not over the page being returned — averaging one page of ten would
     * show a different number on every page of the same listing.
     *
     * @return array<string,mixed>
     */
    public static function listForProperty(string $propertyId, int $page, int $limit): array
    {
        $params = ['property' => $propertyId];

        $summary = Database::first(
            "SELECT COUNT(*) AS total, AVG(rating) AS average
               FROM reviews
              WHERE property_id = :property AND status = 'published'",
            $params
        );

        $total = (int) ($summary['total'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Database::all(
            "SELECT * FROM reviews
              WHERE property_id = :property AND status = 'published'
              ORDER BY created_at DESC
              LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items' => array_map([Review::class, 'toJson'], $rows),
            /* One decimal place, and null rather than 0 when there are no
               reviews — "0.0 out of 5" beside an empty list reads as a
               terrible rating rather than as no ratings at all. */
            'average' => $total > 0 ? round((float) $summary['average'], 1) : null,
            'count' => $total,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / max(1, $limit))),
        ];
    }

    /** @param array<string,mixed> $author */
    public static function mineFor(string $propertyId, array $author): ?array
    {
        $row = Review::findByAuthor($propertyId, (string) $author['id']);
        return $row === null ? null : Review::toJson($row);
    }

    /**
     * The moderation queue.
     *
     * Joined to properties so the admin sees which listing each review is
     * about without a second request per row.
     *
     * @return array<string,mixed>
     */
    public static function listPending(int $page, int $limit): array
    {
        $total = (int) Database::scalar("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
        $offset = ($page - 1) * $limit;

        $rows = Database::all(
            "SELECT r.*, p.name AS property_title, p.city AS property_city
               FROM reviews r
               JOIN properties p ON p.id = r.property_id
              WHERE r.status = 'pending'
              ORDER BY r.created_at DESC
              LIMIT $limit OFFSET $offset"
        );

        $items = array_map(static function (array $row): array {
            $json = Review::toJson($row);
            $json['property'] = [
                'id' => $row['property_id'],
                'name' => $row['property_title'],
                'city' => $row['property_city'],
            ];
            return $json;
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / max(1, $limit))),
        ];
    }

    /**
     * Publish or reject.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $actor
     */
    public static function moderate(string $id, array $patch, array $actor): array
    {
        $row = Review::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Review not found');
        }

        $status = (string) $patch['status'];

        Database::update('reviews', $id, [
            'status' => $status,
            'rejection_reason' => $status === 'rejected' ? ($patch['rejectionReason'] ?? null) : null,
            'updated_at' => now_utc(),
        ]);

        AuditLog::record($actor, "review.$status", $id, 'review', null, [
            'rating' => (int) $row['rating'],
            'property' => $row['property_id'],
        ]);

        return Review::toJson(Review::findById($id) ?? $row);
    }

    /** @param array<string,mixed> $actor */
    public static function remove(string $id, array $actor): void
    {
        $row = Review::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Review not found');
        }

        $isOwnReview = (string) $row['author_id'] === (string) $actor['id'];
        if (!Auth::isAdmin($actor) && !$isOwnReview) {
            throw ApiError::forbidden('You can only delete your own review');
        }

        Review::delete($id);
    }
}
