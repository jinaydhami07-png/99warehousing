<?php
/**
 * Enquiry — a message from a visitor, about a specific listing or through
 * the general contact form.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Support\ObjectId;

final class Enquiry
{
    public const STATUSES = ['new', 'contacted', 'closed'];

    public static function findById(?string $id): ?array
    {
        if ($id === null || !ObjectId::isValid($id)) {
            return null;
        }
        return Database::first('SELECT * FROM enquiries WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): array
    {
        $id = ObjectId::generate();
        $now = now_utc();

        Database::insert('enquiries', [
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'mobile' => $data['mobile'] ?? null,
            'company' => $data['company'] ?? null,
            'subject' => $data['subject'] ?? 'General enquiry',
            'message' => $data['message'],
            'property_id' => $data['propertyId'] ?? null,
            'property_name' => $data['propertyName'] ?? null,
            'user_id' => $data['userId'] ?? null,
            'status' => 'new',
            'ip' => $data['ip'] ?? null,
            'user_agent' => $data['userAgent'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::findById($id) ?? [];
    }

    /** @param array<string,mixed> $patch */
    public static function update(string $id, array $patch): ?array
    {
        $row = [];
        if (array_key_exists('status', $patch)) {
            $row['status'] = $patch['status'];
        }
        if (array_key_exists('adminNote', $patch)) {
            $row['admin_note'] = $patch['adminNote'];
        }
        if ($row) {
            $row['updated_at'] = now_utc();
            Database::update('enquiries', $id, $row);
        }
        return self::findById($id);
    }

    /**
     * Row → JSON.
     *
     * `ip` and `user_agent` are captured for abuse triage and never
     * returned — they are not the admin inbox's business and would be
     * personal data on the wire for no gain.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toJson(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'mobile' => $row['mobile'],
            'company' => $row['company'],
            'subject' => $row['subject'],
            'message' => $row['message'],
            'property' => $row['property_id'],
            'propertyName' => $row['property_name'],
            'status' => $row['status'],
            'adminNote' => $row['admin_note'],
            'createdAt' => iso8601($row['created_at'] ?? null),
            'updatedAt' => iso8601($row['updated_at'] ?? null),
        ];
    }
}
