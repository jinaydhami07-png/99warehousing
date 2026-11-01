<?php
/**
 * Listing endpoints.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Middleware\Auth;
use App\Services\PropertyService;
use App\Support\ObjectId;
use App\Support\Pagination;
use App\Support\Validate;
use App\Validators\PropertySchema;

final class PropertyController
{
    /** Every route with an :id runs this first. */
    private static function id(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid property id');
        }
        return $id;
    }

    public static function list(Request $request): void
    {
        $query = Validate::against(PropertySchema::listQuery(), $request->query());
        $page = Pagination::from($query);

        $result = PropertyService::listPublic(array_merge($query, $page));

        Response::paginated($result['items'], $page['page'], $page['limit'], $result['total'], 'Properties retrieved');
    }

    public static function listMine(Request $request): void
    {
        Response::success(
            ['items' => PropertyService::listMine((string) $request->user['id'])],
            'Your listings retrieved'
        );
    }

    public static function getOne(Request $request): void
    {
        $item = PropertyService::getById(self::id($request), $request->user);
        Response::success(['item' => $item], 'Property retrieved');
    }

    public static function getContact(Request $request): void
    {
        $contact = PropertyService::getContact(self::id($request), $request->user);
        Response::success(['contact' => $contact], 'Contact details retrieved');
    }

    public static function create(Request $request): void
    {
        /* Admins get the wider schema — they may name an owner and set a
           status. Chosen from the caller's role, never from the payload. */
        $schema = Auth::isAdmin($request->user) ? PropertySchema::adminCreate() : PropertySchema::create();
        $body = Validate::against($schema, $request->body());

        $item = PropertyService::create($body, $request->user);
        Response::created(['item' => $item]);
    }

    public static function update(Request $request): void
    {
        $schema = Auth::isAdmin($request->user) ? PropertySchema::adminUpdate() : PropertySchema::update();
        $body = Validate::against($schema, $request->body());

        $item = PropertyService::update(self::id($request), $body, $request->user);
        Response::success(['item' => $item], 'Property updated');
    }

    public static function remove(Request $request): void
    {
        PropertyService::remove(self::id($request), $request->user);
        Response::success(null, 'Property deleted');
    }

    /* ── Admin ── */

    public static function listAll(Request $request): void
    {
        $query = $request->query();
        /* The dashboard's "All" tab sends status=all, but the validator only
           accepts real statuses. Treat "all" as no filter rather than
           rejecting it with a 400 the admin would see as an empty table. */
        if (($query['status'] ?? null) === 'all' || ($query['status'] ?? null) === '') {
            unset($query['status']);
        }

        $query = Validate::against(PropertySchema::listQuery(), $query);
        $page = Pagination::from($query);

        $result = PropertyService::listAll(array_merge($query, $page));

        Response::paginated($result['items'], $page['page'], $page['limit'], $result['total'], 'All properties retrieved');
    }

    public static function stats(Request $request): void
    {
        Response::success(PropertyService::stats(), 'Stats retrieved');
    }

    public static function approve(Request $request): void
    {
        $item = PropertyService::approve(self::id($request), $request->user);
        Response::success(['item' => $item], 'Property approved');
    }

    public static function reject(Request $request): void
    {
        $body = Validate::against(PropertySchema::reject(), $request->body());
        $item = PropertyService::reject(self::id($request), $body['reason'], $request->user);
        Response::success(['item' => $item], 'Property rejected');
    }
}
