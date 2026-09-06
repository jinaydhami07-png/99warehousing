<?php
/**
 * Enquiry endpoints.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\EnquiryService;
use App\Support\ObjectId;
use App\Support\Pagination;
use App\Support\Validate;
use App\Validators\EnquirySchema;

final class EnquiryController
{
    public static function create(Request $request): void
    {
        $body = Validate::against(EnquirySchema::create(), $request->body());

        $item = EnquiryService::create($body, $request->user, [
            'ip' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        Response::created(['item' => $item], 'Enquiry received');
    }

    public static function listMine(Request $request): void
    {
        Response::success(
            ['items' => EnquiryService::listMine((string) $request->user['id'])],
            'Your enquiries retrieved'
        );
    }

    public static function listAll(Request $request): void
    {
        $query = Validate::against(EnquirySchema::listQuery(), $request->query());
        $page = Pagination::from($query);

        $result = EnquiryService::listAll(array_merge($query, $page));

        Response::paginated($result['items'], $page['page'], $page['limit'], $result['total'], 'Enquiries retrieved');
    }

    public static function update(Request $request): void
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid enquiry id');
        }

        $body = Validate::against(EnquirySchema::updateStatus(), $request->body());
        $item = EnquiryService::update($id, $body, $request->user);

        Response::success(['item' => $item], 'Enquiry updated');
    }
}
