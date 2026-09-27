<?php
/**
 * Review endpoints — public reading, authenticated writing, admin moderation.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\ReviewService;
use App\Support\ObjectId;
use App\Support\Pagination;
use App\Support\Validate;
use App\Validators\ReviewSchema;

final class ReviewController
{
    public static function listForProperty(Request $request): void
    {
        $query = Validate::against(ReviewSchema::listQuery(), $request->query());
        $page = Pagination::from($query);

        $result = ReviewService::listForProperty(self::propertyId($request), $page['page'], $page['limit']);

        /* The average and count go in `data`, not `meta`. They are content —
           the page prints "4.6 out of 5 from 12 reviews" above the list —
           and the shared paginated helper would put them where the client
           would have to know to look for them. */
        Response::success($result, 'Reviews retrieved');
    }

    public static function create(Request $request): void
    {
        $body = Validate::against(ReviewSchema::create(), $request->body());
        $result = ReviewService::create(self::propertyId($request), $body, $request->user);

        if ($result['replaced']) {
            Response::success(['item' => $result['review']], 'Your review was updated and is awaiting moderation');
            return;
        }
        Response::created(['item' => $result['review']], 'Thank you — your review is awaiting moderation');
    }

    public static function mine(Request $request): void
    {
        $item = ReviewService::mineFor(self::propertyId($request), $request->user);
        Response::success(['item' => $item], 'Your review retrieved');
    }

    public static function listPending(Request $request): void
    {
        $query = Validate::against(ReviewSchema::listQuery(), $request->query());
        $page = Pagination::from($query);

        Response::success(ReviewService::listPending($page['page'], $page['limit']), 'Reviews retrieved');
    }

    public static function moderate(Request $request): void
    {
        $body = Validate::against(ReviewSchema::moderate(), $request->body());
        $item = ReviewService::moderate(self::reviewId($request), $body, $request->user);

        Response::success(['item' => $item], 'Review ' . $item['status']);
    }

    public static function remove(Request $request): void
    {
        ReviewService::remove(self::reviewId($request), $request->user);
        Response::success(null, 'Review deleted');
    }

    private static function propertyId(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid property id');
        }
        return $id;
    }

    private static function reviewId(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid review id');
        }
        return $id;
    }
}
