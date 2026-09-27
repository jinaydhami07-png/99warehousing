<?php
/**
 * Image — the record of a property photo or floor plan.
 *
 * The bytes are files under public/uploads, served straight by Apache. This
 * row holds the text: where the file is, what shape it is, who uploaded it
 * and which listing it belongs to.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Support\ObjectId;

final class Image
{
    /** Raster formats a browser can display.
     *
     * SVG is deliberately excluded. It is an executable document — it can
     * carry a <script> — and serving user-supplied SVG from our own origin
     * would be a stored-XSS hole dressed up as a picture. */
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public static function findById(?string $id): ?array
    {
        if ($id === null || !ObjectId::isValid($id)) {
            return null;
        }
        return Database::first('SELECT * FROM images WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): array
    {
        $id = $data['id'] ?? ObjectId::generate();
        $now = now_utc();

        Database::insert('images', [
            'id' => $id,
            'storage' => $data['storage'] ?? 'local',
            'path' => $data['path'] ?? null,
            'url' => $data['url'] ?? null,
            'variants' => json_store($data['variants'] ?? null),
            'blur' => $data['blur'] ?? null,
            'content_type' => $data['contentType'],
            'size' => (int) $data['size'],
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'original_name' => $data['originalName'] ?? null,
            'kind' => $data['kind'] ?? 'photo',
            'uploaded_by' => $data['uploadedBy'],
            'property_id' => $data['propertyId'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::findById($id) ?? [];
    }

    public static function delete(string $id): void
    {
        Database::run('DELETE FROM images WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forProperty(string $propertyId): array
    {
        return Database::all('SELECT * FROM images WHERE property_id = :p', ['p' => $propertyId]);
    }

    /**
     * Tag images with the listing they ended up on.
     *
     * @param array<int,string> $ids
     */
    public static function attachToProperty(array $ids, string $propertyId): void
    {
        $ids = array_values(array_filter($ids, [ObjectId::class, 'isValid']));
        if (!$ids) {
            return;
        }
        /* Built from a validated id list, and the only interpolation is the
           placeholder string itself — the ids are still bound. */
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::connection()
            ->prepare("UPDATE images SET property_id = ?, updated_at = ? WHERE id IN ($placeholders)")
            ->execute(array_merge([$propertyId, now_utc()], $ids));
    }

    /**
     * The descriptor the API hands back and a listing stores — text only.
     *
     * One method so the uploader and the property service produce identical
     * shapes; three near-identical object literals in three files is how
     * the two halves of a record drift apart.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toDescriptor(array $row): array
    {
        $variants = json_column($row['variants'] ?? null, []);

        return array_filter([
            'url' => $row['url'] ?: '/api/v1/images/' . $row['id'],
            'publicId' => (string) $row['id'],
            'variants' => array_map(
                static fn(array $v) => ['w' => (int) $v['w'], 'h' => (int) ($v['h'] ?? 0), 'url' => $v['url']],
                is_array($variants) ? $variants : []
            ),
            'blur' => $row['blur'] ?: null,
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'contentType' => $row['content_type'],
            'size' => (int) $row['size'],
            'originalName' => $row['original_name'],
        ], static fn($v) => $v !== null);
    }
}
