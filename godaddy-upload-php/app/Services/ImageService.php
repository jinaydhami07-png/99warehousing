<?php
/**
 * Uploads: sniffing, resizing, storing and deleting property photos.
 *
 * ── Where the bytes go ────────────────────────────────────────────────
 * Files on disk under public/uploads, served by Apache directly. The
 * MongoDB build stored image bytes in the database when no S3 bucket was
 * configured; on a cPanel account that is the worst of both worlds — every
 * image read becomes a PHP process and a query, and the nightly backup
 * grows without bound. A file is what a web server is for.
 *
 * The `images` table still holds a row per upload, because "who uploaded
 * this, and which listing is it on?" has to survive, and a directory
 * listing cannot answer it.
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Config\Logger;
use App\Http\ApiError;
use App\Middleware\Auth;
use App\Models\Image;
use App\Support\ObjectId;

final class ImageService
{
    /** Wider than any screen can use it; floor plans get more headroom
     *  because they are opened full-screen and read, not glanced at. */
    private const MAX_WIDTH = ['photo' => 1920, 'floorplan' => 2560];

    /**
     * Save a batch of uploaded files.
     *
     * @param array<int,array<string,mixed>> $files Normalised $_FILES entries.
     * @param array<string,mixed> $user
     * @return array<int,array<string,mixed>> Descriptors for the client.
     */
    public static function saveMany(array $files, array $user, string $kind = 'photo'): array
    {
        $kind = $kind === 'floorplan' ? 'floorplan' : 'photo';

        $files = array_values(array_filter($files, static fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (!$files) {
            throw ApiError::badRequest('No files were uploaded');
        }

        $maxFiles = (int) Env::get('media.maxFiles', 12);
        if (count($files) > $maxFiles) {
            throw ApiError::unprocessable("You can upload at most $maxFiles images at once");
        }

        $saved = [];
        foreach ($files as $file) {
            $saved[] = self::storeOne($file, $user, $kind);
        }

        Logger::info('Images uploaded', ['count' => count($saved), 'userId' => $user['id'], 'kind' => $kind]);
        return $saved;
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private static function storeOne(array $file, array $user, string $kind): array
    {
        self::assertUploadOk($file);

        $tmp = (string) $file['tmp_name'];
        /* The file must be one PHP itself received as an upload. Without
           this check a path in the request could name any file the web
           user can read — /etc/passwd, config/.env — and have it copied
           into a public directory. */
        if (!is_uploaded_file($tmp)) {
            throw ApiError::badRequest('Invalid upload');
        }

        $maxBytes = (int) Env::get('media.maxBytes');
        if ((int) $file['size'] > $maxBytes) {
            $mb = (int) round($maxBytes / 1024 / 1024);
            throw ApiError::unprocessable("Each image must be $mb MB or smaller");
        }

        /* ── The type is sniffed, never believed ──────────────────────
           The browser's Content-Type is a claim: anything can be uploaded
           as "image/jpeg". These bytes are served back under our own
           domain, so trusting the claim would let someone store HTML here
           and have it execute as if we had written it. The leading bytes
           decide, and the sniffed type is what gets persisted.
           ───────────────────────────────────────────────────────────── */
        $contentType = self::sniff($tmp);
        if ($contentType === null) {
            throw ApiError::unprocessable(sprintf(
                '"%s" is not a readable image (JPEG, PNG, WebP, GIF or AVIF only)',
                basename((string) $file['name'])
            ));
        }

        $id = ObjectId::generate();
        $folder = sprintf(
            '%s/%s/%s/%s',
            (string) Env::get('media.dir', 'uploads'),
            $kind === 'floorplan' ? 'floorplans' : 'photos',
            gmdate('Y-m-d'),                 // keeps the directory browsable
            $id
        );

        $absolute = APP_ROOT . '/public/' . $folder;
        if (!is_dir($absolute) && !@mkdir($absolute, 0755, true) && !is_dir($absolute)) {
            Logger::error('Could not create upload directory', ['dir' => $absolute]);
            throw ApiError::internal('Could not store the upload');
        }

        /* Anything that goes wrong from here on leaves half-written files in
           a directory no database row points at. They would sit there
           forever, taking up the account's disk quota and belonging to
           nothing, so the partial output is removed before the error is
           re-thrown. */
        try {
            $rendered = self::renderVariants($tmp, $contentType, $kind, $absolute, $folder);
        } catch (\Throwable $e) {
            self::removeDirectory($absolute);
            throw $e;
        }

        if ($rendered === null) {
            self::removeDirectory($absolute);
            throw ApiError::unprocessable(sprintf(
                '"%s" could not be read as an image — it may be damaged or incomplete.',
                basename((string) $file['name'])
            ));
        }

        $full = $rendered['variants'][count($rendered['variants']) - 1];

        try {
            $row = Image::create([
                'id' => $id,
                'storage' => 'local',
                'path' => $full['path'],
                'url' => $full['url'],
                'variants' => array_map(
                    static fn(array $v) => ['w' => $v['w'], 'h' => $v['h'], 'url' => $v['url'], 'size' => $v['size']],
                    $rendered['variants']
                ),
                'blur' => $rendered['blur'],
                'contentType' => $rendered['outputType'],
                'size' => array_sum(array_column($rendered['variants'], 'size')),
                'width' => $full['w'],
                'height' => $full['h'],
                'originalName' => basename((string) $file['name']),
                'kind' => $kind,
                'uploadedBy' => $user['id'],
            ]);
        } catch (\Throwable $e) {
            self::removeDirectory($absolute);
            throw $e;
        }

        return Image::toDescriptor($row);
    }

    /**
     * Delete a directory and the files directly inside it.
     *
     * Not recursive, and deliberately so: every upload gets its own flat
     * folder named after the image id, so there is never a subdirectory to
     * descend into — and a recursive delete is not something to have lying
     * around near a path that started life in a request.
     */
    private static function removeDirectory(string $absolute): void
    {
        if (!is_dir($absolute)) {
            return;
        }
        foreach ((array) glob($absolute . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($absolute);
    }

    /** Translate PHP's upload error codes into messages a user can act on. */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $messages = [
            UPLOAD_ERR_INI_SIZE => 'That file is larger than this server accepts. Try a smaller image.',
            UPLOAD_ERR_FORM_SIZE => 'That file is larger than the form allows.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory configured for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'The upload was stopped by a server extension.',
        ];

        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            Logger::error('Upload rejected by PHP', ['code' => $error]);
        }
        throw ApiError::unprocessable($messages[$error] ?? 'Upload failed');
    }

    /**
     * The image type, from the leading bytes.
     *
     * getimagesize() would do most of this, but it also decodes enough of
     * the file to be a denial-of-service surface on a malformed one, and it
     * happily reports a type for formats a browser cannot show. Reading the
     * magic bytes is both cheaper and stricter.
     */
    public static function sniff(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 16);
        fclose($handle);

        if (strlen($head) < 12) {
            return null;
        }

        if (substr($head, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        if (substr($head, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }
        if (preg_match('/^GIF8[79]a$/', substr($head, 0, 6)) === 1) {
            return 'image/gif';
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            return 'image/avif';
        }

        return null;
    }

    /**
     * Decode once, then write one WebP per target width.
     *
     * @return array{variants:array<int,array<string,mixed>>,blur:?string,outputType:string}|null
     */
    private static function renderVariants(string $tmp, string $contentType, string $kind, string $absoluteDir, string $relativeDir): ?array
    {
        $source = self::decode($tmp, $contentType);
        if ($source === null) {
            return null;
        }

        try {
            /* A JPEG straight off a phone is often stored landscape with a
               "rotate me" flag in its EXIF. GD's output carries no EXIF, so
               the rotation has to be baked into the pixels here or every
               portrait photo appears on its side. */
            $source = self::applyExifRotation($source, $tmp, $contentType);

            $srcWidth = imagesx($source);
            $srcHeight = imagesy($source);
            $quality = (int) Env::get('media.webpQuality', 78);

            $variants = [];
            foreach (self::targetWidths($srcWidth, $kind) as $width) {
                $height = max(1, (int) round($srcHeight * ($width / $srcWidth)));
                $resized = self::resample($source, $width, $height);

                $file = "w$width.webp";
                $path = "$absoluteDir/$file";

                if (!imagewebp($resized, $path, $quality)) {
                    self::release($resized);
                    Logger::error('WebP encode failed', ['path' => $path]);
                    return null;
                }
                self::release($resized);

                $variants[] = [
                    'w' => $width,
                    'h' => $height,
                    'size' => (int) filesize($path),
                    'path' => "$relativeDir/$file",
                    'url' => self::publicUrl("$relativeDir/$file"),
                ];
            }

            return [
                'variants' => $variants,
                'blur' => self::blurPlaceholder($source, $srcWidth, $srcHeight),
                'outputType' => 'image/webp',
            ];
        } finally {
            self::release($source);
        }
    }

    /**
     * Widths to render.
     *
     * Never larger than the source: upscaling a 400px photo to 1920 makes a
     * bigger file that looks worse. The full size is always included, so
     * even a small image gets one rendition.
     *
     * @return array<int,int>
     */
    public static function targetWidths(int $srcWidth, string $kind): array
    {
        $cap = self::MAX_WIDTH[$kind] ?? self::MAX_WIDTH['photo'];
        $full = min($srcWidth > 0 ? $srcWidth : $cap, $cap);

        $widths = array_filter((array) Env::get('media.widths', []), static fn(int $w) => $w < $full);
        $widths[] = $full;

        $widths = array_values(array_unique($widths));
        sort($widths);
        return $widths;
    }

    private static function decode(string $path, string $contentType)
    {
        /* AVIF support depends on how GD was built, so it is checked rather
           than assumed — imagecreatefromavif simply does not exist on a
           build without it, and calling it is a fatal error. */
        $decoders = [
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            'image/avif' => 'imagecreatefromavif',
        ];

        $fn = $decoders[$contentType] ?? null;
        if ($fn === null || !function_exists($fn)) {
            Logger::warn('No decoder available for image type', ['type' => $contentType]);
            return null;
        }

        try {
            $image = @$fn($path);
        } catch (\Throwable $e) {
            return null;
        }
        return $image === false ? null : $image;
    }

    private static function applyExifRotation($image, string $path, string $contentType)
    {
        if ($contentType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $angles = [3 => 180, 6 => -90, 8 => 90];
        if (!isset($angles[$orientation])) {
            return $image;
        }

        $rotated = @imagerotate($image, $angles[$orientation], 0);
        if ($rotated === false) {
            return $image;
        }
        self::release($image);
        return $rotated;
    }

    private static function resample($source, int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);

        /* Preserve transparency. Without these two calls a PNG's alpha
           channel is flattened to black, which is very visible on a logo. */
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        return $canvas;
    }

    /**
     * A ~20px WebP as a data URI: what the browser paints in the fraction of
     * a second before the real photo arrives, instead of an empty grey box.
     * It ships inside JSON that had to be fetched anyway, so it costs no
     * extra request.
     */
    private static function blurPlaceholder($source, int $srcWidth, int $srcHeight): ?string
    {
        $width = 20;
        $height = max(1, (int) round($srcHeight * ($width / max(1, $srcWidth))));

        $tiny = self::resample($source, $width, $height);
        $tmp = tempnam(sys_get_temp_dir(), 'blur');
        if ($tmp === false) {
            self::release($tiny);
            return null;
        }

        try {
            if (!imagewebp($tiny, $tmp, 28)) {
                return null;
            }
            $bytes = (string) file_get_contents($tmp);
            /* Capped so a malformed value cannot bloat the listing feed —
               every card in a 20-listing page carries one of these. */
            if ($bytes === '' || strlen($bytes) > 2800) {
                return null;
            }
            return 'data:image/webp;base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            /* Cosmetic. A missing placeholder must never cost the upload. */
            return null;
        } finally {
            self::release($tiny);
            @unlink($tmp);
        }
    }

    /**
     * Free a GD image.
     *
     * Only meaningful on PHP 7, where a GD image is a resource that has to
     * be released by hand. Since PHP 8.0 it is a GdImage object freed by the
     * garbage collector, imagedestroy() does nothing, and as of 8.5 calling
     * it raises a deprecation. Guarding on the version keeps the memory
     * behaviour correct on the old hosts this has to run on without
     * generating noise on the new ones.
     */
    private static function release($image): void
    {
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    private static function publicUrl(string $relativePath): string
    {
        $base = Env::get('media.publicBaseUrl');
        return is_string($base) && $base !== '' ? "$base/$relativePath" : "/$relativePath";
    }

    /* ── Serving ── */

    /**
     * Resolve /api/v1/images/:id to a file on disk.
     *
     * Apache serves the /uploads URL directly, so this route exists for the
     * ids saved on older listings and for anything that only kept a
     * publicId. It answers with a redirect rather than streaming the bytes:
     * one extra round trip, then the file comes from the web server at full
     * speed with no PHP process held open.
     *
     * @return array{redirect:string}
     */
    public static function resolve(string $id, ?string $requestedWidth): array
    {
        $row = Image::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Image not found');
        }

        $variants = json_column($row['variants'] ?? null, []);
        $url = (string) $row['url'];

        /* Honour ?w= by picking the smallest rendition at least that wide,
           so a card asking for 320 does not receive the 1920px file. With
           no width, the largest — a bare /images/:id keeps meaning "the
           full-size one", as it always did. */
        if (is_array($variants) && $variants) {
            usort($variants, static fn(array $a, array $b) => $a['w'] <=> $b['w']);
            $want = (int) $requestedWidth;
            $chosen = null;
            if ($want > 0) {
                foreach ($variants as $variant) {
                    if ((int) $variant['w'] >= $want) {
                        $chosen = $variant;
                        break;
                    }
                }
            }
            $chosen = $chosen ?? $variants[count($variants) - 1];
            $url = (string) $chosen['url'];
        }

        if ($url === '') {
            throw ApiError::notFound('Image not found');
        }
        return ['redirect' => $url];
    }

    /* ── Attaching and deleting ── */

    /**
     * Tag the images referenced by a listing payload with that listing.
     *
     * The floor plan goes through the same call: it is uploaded through the
     * same endpoint and was previously left untagged, which meant deleting
     * the listing never cleaned it up.
     *
     * @param array<string,mixed> $payload
     */
    public static function attachToProperty(array $payload, string $propertyId): void
    {
        $ids = [];
        foreach ((array) ($payload['images'] ?? []) as $image) {
            if (is_array($image) && !empty($image['publicId'])) {
                $ids[] = (string) $image['publicId'];
            }
        }
        $floorPlan = $payload['floorPlan'] ?? null;
        if (is_array($floorPlan) && !empty($floorPlan['publicId'])) {
            $ids[] = (string) $floorPlan['publicId'];
        }

        try {
            Image::attachToProperty($ids, $propertyId);
        } catch (\Throwable $e) {
            Logger::warn('Could not tag images with their property', ['propertyId' => $propertyId]);
        }
    }

    /** @param array<string,mixed> $user */
    public static function remove(string $id, array $user): array
    {
        $row = Image::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Image not found');
        }

        if ((string) $row['uploaded_by'] !== (string) $user['id'] && !Auth::isAdmin($user)) {
            throw ApiError::forbidden('You can only delete your own uploads');
        }

        /* Take it off the listing before deleting the files, or the gallery
           is left pointing at a URL that now 404s. Both the gallery and the
           floor plan can hold it, and clearing only one would leave a dead
           image on the page it was not removed from. */
        if ($row['property_id']) {
            self::detachFromProperty((string) $row['property_id'], $id);
        }

        self::deleteFiles($row);
        Image::delete($id);

        return ['id' => $id];
    }

    /** Strip one image out of a listing's images array and floor plan. */
    private static function detachFromProperty(string $propertyId, string $imageId): void
    {
        $property = \App\Models\Property::findById($propertyId);
        if ($property === null) {
            return;
        }

        $images = json_column($property['images'] ?? null, []);
        $images = array_values(array_filter(
            is_array($images) ? $images : [],
            static fn($i) => !is_array($i) || (string) ($i['publicId'] ?? '') !== $imageId
        ));

        $floorPlan = json_column($property['floor_plan'] ?? null, null);
        if (is_array($floorPlan) && (string) ($floorPlan['publicId'] ?? '') === $imageId) {
            $floorPlan = null;
        }

        \App\Config\Database::update('properties', $propertyId, [
            'images' => json_store($images),
            'floor_plan' => json_store($floorPlan),
            'updated_at' => now_utc(),
        ]);
    }

    /**
     * Delete every rendition of one image, then its directory.
     *
     * Each upload has its own folder named after the image id, so removing
     * the folder cannot take anything else with it.
     *
     * @param array<string,mixed> $row
     */
    private static function deleteFiles(array $row): void
    {
        $paths = [];
        if (!empty($row['path'])) {
            $paths[] = (string) $row['path'];
        }
        foreach ((array) json_column($row['variants'] ?? null, []) as $variant) {
            if (is_array($variant) && !empty($variant['url'])) {
                $paths[] = ltrim(parse_url((string) $variant['url'], PHP_URL_PATH) ?: '', '/');
            }
        }

        $mediaDir = (string) Env::get('media.dir', 'uploads');
        $directories = [];

        foreach (array_unique($paths) as $path) {
            /* Everything this deletes must sit under the media directory.
               A path traversal in a stored value would otherwise reach the
               application's own files. */
            if ($path === '' || !str_starts_with($path, $mediaDir . '/') || str_contains($path, '..')) {
                Logger::warn('Refused to delete a file outside the media directory', ['path' => $path]);
                continue;
            }
            $absolute = APP_ROOT . '/public/' . $path;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $directories[dirname($absolute)] = true;
        }

        foreach (array_keys($directories) as $dir) {
            @rmdir($dir); // only succeeds once it is empty, which is what we want
        }
    }

    /**
     * Remove every image belonging to a listing.
     *
     * Called before the listing is deleted, while the rows still point at
     * the files. Failures are logged and swallowed: a leftover file is a
     * housekeeping problem, not a reason to refuse the deletion.
     */
    public static function removeForProperty(string $propertyId): void
    {
        try {
            $rows = Image::forProperty($propertyId);
            foreach ($rows as $row) {
                self::deleteFiles($row);
                Image::delete((string) $row['id']);
            }
            if ($rows) {
                Logger::info('Removed images for deleted property', ['propertyId' => $propertyId, 'removed' => count($rows)]);
            }
        } catch (\Throwable $e) {
            Logger::warn('Could not clean up listing images', ['propertyId' => $propertyId, 'err' => $e->getMessage()]);
        }
    }
}
