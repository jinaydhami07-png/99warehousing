<?php
/**
 * Saved-listing endpoints. Every route here is per-account and sits behind
 * an authenticated guard.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\FavoriteService;
use App\Support\ObjectId;

final class FavoriteController
{
    public static function list(Request $request): void
    {
        Response::success(
            ['items' => FavoriteService::list((string) $request->user['id'])],
            'Favourites retrieved'
        );
    }

    public static function add(Request $request): void
    {
        $data = FavoriteService::add((string) $request->user['id'], self::id($request));
        Response::success($data, 'Added to favourites');
    }

    public static function remove(Request $request): void
    {
        $data = FavoriteService::remove((string) $request->user['id'], self::id($request));
        Response::success($data, 'Removed from favourites');
    }

    private static function id(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid property id');
        }
        return $id;
    }
}
