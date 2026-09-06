<?php
/**
 * Saved listings.
 *
 * Signed-out visitors keep theirs in localStorage (public/assets/bpsf-store.js)
 * and those are pushed up on sign-in, which is why `add` has to be idempotent.
 */
declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Models\Favorite;
use App\Models\Property;

final class FavoriteService
{
    /** @return array<int,array<string,mixed>> */
    public static function list(string $userId): array
    {
        $rows = Favorite::listFor($userId);

        return array_map(static function (array $row): array {
            $card = Property::toCard($row);
            $card['favoritedAt'] = iso8601($row['favorited_at'] ?? null);
            return $card;
        }, $rows);
    }

    /** @return array{propertyId:string} */
    public static function add(string $userId, string $propertyId): array
    {
        if (Property::findById($propertyId) === null) {
            throw ApiError::notFound('Property not found');
        }
        Favorite::add($userId, $propertyId);
        return ['propertyId' => $propertyId];
    }

    /**
     * Remove. Deliberately does not check that the row existed — a heart
     * un-tapped twice, or a retried request, should report success rather
     * than a 404 the user cannot act on.
     *
     * @return array{propertyId:string}
     */
    public static function remove(string $userId, string $propertyId): array
    {
        Favorite::remove($userId, $propertyId);
        return ['propertyId' => $propertyId];
    }
}
