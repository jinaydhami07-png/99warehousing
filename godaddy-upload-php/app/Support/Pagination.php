<?php
/**
 * Page / limit / offset, from a query string.
 *
 * MAX_LIMIT is the important part: without a ceiling, ?limit=100000 asks the
 * server to serialise the whole table into one response.
 */
declare(strict_types=1);

namespace App\Support;

final class Pagination
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    /**
     * @param array<string,mixed> $query
     * @return array{page:int,limit:int,skip:int}
     */
    public static function from(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $requested = (int) ($query['limit'] ?? self::DEFAULT_LIMIT);
        $limit = min(self::MAX_LIMIT, max(1, $requested ?: self::DEFAULT_LIMIT));

        return ['page' => $page, 'limit' => $limit, 'skip' => ($page - 1) * $limit];
    }
}
