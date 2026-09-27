<?php
/**
 * Upload and image-delivery endpoints.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\ImageService;
use App\Support\ObjectId;

final class ImageController
{
    public static function upload(Request $request): void
    {
        $kind = ($request->query()['kind'] ?? $request->body()['kind'] ?? '') === 'floorplan'
            ? 'floorplan'
            : 'photo';

        /* The field name is `files`, matching what api.js appends to its
           FormData. Anything else is a client bug, and saying so is more
           useful than "no files were uploaded". */
        $files = $request->files['files'] ?? null;
        if ($files === null) {
            if ($request->files) {
                throw ApiError::unprocessable('Unexpected file field — use the field name "files"');
            }
            throw ApiError::badRequest('No files were uploaded');
        }

        $saved = ImageService::saveMany($files, $request->user, $kind);
        Response::created(['files' => $saved], 'Upload complete');
    }

    /**
     * GET /api/v1/images/:id
     *
     * Answers with a redirect to the file Apache serves. Cached for a day
     * rather than a year: long enough to cost nothing, short enough that a
     * change of media host is not baked into visitors' browsers forever.
     */
    public static function serve(Request $request): void
    {
        $resolved = ImageService::resolve(self::id($request), $request->query()['w'] ?? null);

        header('Cache-Control: public, max-age=86400');
        Response::redirect($resolved['redirect'], 302);
    }

    public static function remove(Request $request): void
    {
        $data = ImageService::remove(self::id($request), $request->user);
        Response::success($data, 'Image deleted');
    }

    private static function id(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid image id');
        }
        return $id;
    }
}
