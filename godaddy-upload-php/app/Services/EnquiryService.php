<?php
/**
 * Enquiries — the contact form and the admin inbox behind it.
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Logger;
use App\Http\ApiError;
use App\Models\AuditLog;
use App\Models\Enquiry;
use App\Models\Property;

final class EnquiryService
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $actor The sender, if signed in.
     * @param array<string,mixed> $meta
     */
    public static function create(array $payload, ?array $actor, array $meta = []): array
    {
        $data = [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'mobile' => $payload['mobile'] ?? null,
            'company' => $payload['company'] ?? null,
            'subject' => $payload['subject'] ?? 'General enquiry',
            'message' => $payload['message'],
            'ip' => $meta['ip'] ?? null,
            'userAgent' => $meta['userAgent'] ?? null,
            'userId' => $actor['id'] ?? null,
        ];

        /* Resolve the listing before storing, so a bad id is a clean 404
           rather than a dangling reference. The name is denormalised so the
           admin inbox still reads correctly if the listing is later deleted. */
        if (!empty($payload['property'])) {
            $property = Property::findById((string) $payload['property']);
            if ($property === null) {
                throw ApiError::notFound('Property not found');
            }
            $data['propertyId'] = $property['id'];
            $data['propertyName'] = $property['name'];
        }

        $row = Enquiry::create($data);

        /* Best-effort: a counter that lags is cosmetic and must not fail
           the visitor's enquiry. */
        if (!empty($data['propertyId'])) {
            Property::incrementEnquiries((string) $data['propertyId']);
        }

        Logger::info('Enquiry received', ['enquiryId' => $row['id'], 'property' => $data['propertyId'] ?? null]);
        return Enquiry::toJson($row);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listMine(string $userId): array
    {
        $rows = Database::all(
            'SELECT * FROM enquiries WHERE user_id = :user ORDER BY created_at DESC',
            ['user' => $userId]
        );
        return array_map([Enquiry::class, 'toJson'], $rows);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function listAll(array $options): array
    {
        $where = [];
        $params = [];

        if (!empty($options['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $options['status'];
        }
        if (!empty($options['q'])) {
            $where[] = Database::likeClause(['name', 'email', 'company', 'subject'], 'q', (string) $options['q'], $params);
        }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Database::scalar("SELECT COUNT(*) FROM enquiries$sql", $params);

        $limit = (int) $options['limit'];
        $offset = (int) $options['skip'];

        $rows = Database::all(
            "SELECT * FROM enquiries$sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return ['items' => array_map([Enquiry::class, 'toJson'], $rows), 'total' => $total];
    }

    /**
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $admin
     */
    public static function update(string $id, array $patch, array $admin): array
    {
        $row = Enquiry::findById($id);
        if ($row === null) {
            throw ApiError::notFound('Enquiry not found');
        }

        $updated = Enquiry::update($id, $patch);
        if ($updated === null) {
            throw ApiError::notFound('Enquiry not found');
        }

        AuditLog::record($admin, 'enquiry.updated', $id, 'enquiry', ['status' => $row['status']], ['status' => $updated['status']]);
        return Enquiry::toJson($updated);
    }
}
