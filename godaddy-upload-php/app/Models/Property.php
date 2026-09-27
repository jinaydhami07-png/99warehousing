<?php
/**
 * Property model — warehouses, land, logistics parks, cold storage.
 *
 * The row → JSON mapping here is the API's contract with the pages, and it
 * has two rules worth keeping in mind when adding a field:
 *
 *   • camelCase on the wire, snake_case in the table. The front-end reads
 *     `depositMonths` and `isVerified`; nothing in it knows about columns.
 *   • `owner_id` never leaves the server. It appears on the public feed
 *     otherwise, which ties a listing to an account and hands out a real id
 *     to aim at other endpoints. Ownership is decided server-side from the
 *     row itself, so nothing needs it in the browser.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Support\ObjectId;

final class Property
{
    public const TYPES = [
        'Warehouse',
        'Cold Storage',
        'Industrial Shed',
        'Logistics Park',
        'Dark Store',
        'Industrial Land',
    ];

    public const GRADES = ['Grade A', 'Grade B', 'Grade C', 'Cold Chain', 'Land'];
    public const STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    /** camelCase request field → table column, for create and update alike. */
    private const COLUMNS = [
        'name' => 'name',
        'description' => 'description',
        'type' => 'type',
        'grade' => 'grade',
        'city' => 'city',
        'locality' => 'locality',
        'address' => 'address',
        'pincode' => 'pincode',
        'mapsUrl' => 'maps_url',
        'rate' => 'rate',
        'area' => 'area',
        'depositMonths' => 'deposit_months',
        'availableFrom' => 'available_from',
        'ownerName' => 'owner_name',
        'status' => 'status',
        'isVerified' => 'is_verified',
    ];

    /** Stored as JSON — see the note at the top of database/schema.sql. */
    private const JSON_COLUMNS = [
        'specs' => 'specs',
        'images' => 'images',
        'floorPlan' => 'floor_plan',
        'distances' => 'distances',
    ];

    public static function findById(?string $id): ?array
    {
        if ($id === null || !ObjectId::isValid($id)) {
            return null;
        }
        return Database::first('SELECT * FROM properties WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $payload Validated, camelCase.
     * @return array<string,mixed> The stored row.
     */
    public static function create(array $payload, string $ownerId): array
    {
        $id = ObjectId::generate();
        $now = now_utc();

        $row = [
            'id' => $id,
            'owner_id' => $ownerId,
            'slug' => self::slugify((string) ($payload['name'] ?? '')),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (self::COLUMNS as $field => $column) {
            if (array_key_exists($field, $payload)) {
                $row[$column] = self::castOut($field, $payload[$field]);
            }
        }
        foreach (self::JSON_COLUMNS as $field => $column) {
            if (array_key_exists($field, $payload)) {
                $row[$column] = json_store($payload[$field]);
            }
        }

        Database::insert('properties', $row);

        $created = self::findById($id);
        if ($created === null) {
            throw \App\Http\ApiError::internal('Property could not be created');
        }
        return $created;
    }

    /**
     * Apply a validated patch. Only keys actually present are written, so a
     * partial update cannot blank a field the client did not mention.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $forced  Server-decided columns that override
     *                                     anything in the payload.
     */
    public static function update(string $id, array $payload, array $forced = []): ?array
    {
        $row = [];
        foreach (self::COLUMNS as $field => $column) {
            if (array_key_exists($field, $payload)) {
                $row[$column] = self::castOut($field, $payload[$field]);
            }
        }
        foreach (self::JSON_COLUMNS as $field => $column) {
            if (array_key_exists($field, $payload)) {
                $row[$column] = json_store($payload[$field]);
            }
        }

        $row = array_merge($row, $forced);
        $row['updated_at'] = now_utc();

        Database::update('properties', $id, $row);
        return self::findById($id);
    }

    public static function delete(string $id): void
    {
        Database::run('DELETE FROM properties WHERE id = :id', ['id' => $id]);
    }

    /** Fire-and-forget counter. A failure here must never fail the read. */
    public static function incrementViews(string $id): void
    {
        try {
            Database::run('UPDATE properties SET views = views + 1 WHERE id = :id', ['id' => $id]);
        } catch (\Throwable $e) {
            \App\Config\Logger::warn('View counter increment failed', ['id' => $id]);
        }
    }

    public static function incrementEnquiries(string $id): void
    {
        try {
            Database::run('UPDATE properties SET enquiry_count = enquiry_count + 1 WHERE id = :id', ['id' => $id]);
        } catch (\Throwable $e) {
            \App\Config\Logger::warn('Enquiry counter increment failed', ['id' => $id]);
        }
    }

    /**
     * Type conversion on the way into the database.
     *
     * MySQL in strict mode rejects `true` for a TINYINT and an empty string
     * for a DATE, and the validator hands both through as PHP types.
     */
    private static function castOut(string $field, $value)
    {
        if ($value === null) {
            return null;
        }
        if ($field === 'isVerified') {
            return (int) (bool) $value;
        }
        if ($field === 'availableFrom') {
            // The column is a DATE; the validator produced 'Y-m-d H:i:s'.
            return substr((string) $value, 0, 10);
        }
        return $value;
    }

    /**
     * A URL-safe title with a short random suffix.
     *
     * The suffix is what makes it unique: two owners listing "Warehouse in
     * Bhiwandi" is entirely normal, and without it the second INSERT would
     * fail on the unique index with an error about a slug the user never saw.
     */
    public static function slugify(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $base = substr($base, 0, 60);
        return ($base !== '' ? $base : 'listing') . '-' . substr(ObjectId::generate(), -6);
    }

    /**
     * Row → the JSON the API returns.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toJson(array $row): array
    {
        $locality = $row['locality'] ?? null;
        $city = $row['city'] ?? null;

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'type' => $row['type'],
            'grade' => $row['grade'],
            'city' => $city,
            'locality' => $locality,
            'address' => $row['address'],
            'pincode' => $row['pincode'],
            'mapsUrl' => $row['maps_url'],
            /* A virtual field the cards print directly: "Bhiwandi, Mumbai". */
            'location' => implode(', ', array_filter([$locality, $city])),
            /* Cast out of DECIMAL, which PDO hands back as a string. The
               pages do arithmetic on these — the rent calculator multiplies
               rate by area — and "12.50" * 1000 is a silent coercion in JS
               that works until a value is missing and it becomes NaN. */
            'rate' => (float) $row['rate'],
            'area' => (float) $row['area'],
            'depositMonths' => (int) $row['deposit_months'],
            'specs' => json_column($row['specs'] ?? null, null),
            'images' => json_column($row['images'] ?? null, []),
            'floorPlan' => json_column($row['floor_plan'] ?? null, null),
            'distances' => json_column($row['distances'] ?? null, []),
            'availableFrom' => $row['available_from'] ?? null,
            'ownerName' => $row['owner_name'],
            'status' => $row['status'],
            'isVerified' => (bool) $row['is_verified'],
            'rejectionReason' => $row['rejection_reason'],
            'views' => (int) $row['views'],
            'enquiryCount' => (int) $row['enquiry_count'],
            'createdAt' => iso8601($row['created_at'] ?? null),
            'updatedAt' => iso8601($row['updated_at'] ?? null),
        ];
    }

    /**
     * The lighter shape the listing feed uses.
     *
     * A feed of 20 listings carrying every photo, floor plan and distance
     * is several hundred kilobytes of JSON to render a grid of cards that
     * shows one image each. So: the cover image only, a count, and the
     * detail-page fields dropped.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toCard(array $row): array
    {
        $json = self::toJson($row);
        $images = is_array($json['images']) ? $json['images'] : [];

        /* The primary image if one is marked, otherwise the first — the
           same rule the cards used to apply client-side. */
        $cover = null;
        foreach ($images as $image) {
            if (is_array($image) && !empty($image['isPrimary']) && !empty($image['url'])) {
                $cover = $image;
                break;
            }
        }
        if ($cover === null) {
            foreach ($images as $image) {
                if (is_array($image) && !empty($image['url'])) {
                    $cover = $image;
                    break;
                }
            }
        }

        $json['images'] = $cover ? [$cover] : [];
        $json['imageCount'] = count($images);
        unset($json['floorPlan'], $json['distances']);

        return $json;
    }
}
