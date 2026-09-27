<?php
/**
 * Free functions used across the app.
 *
 * Kept to things that genuinely have no object to belong to. Anything with
 * state or configuration behind it lives in a class.
 */
declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    // PHP < 8.0 — cPanel accounts are routinely still on 7.4.
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/**
 * The one place a timestamp is formatted for the wire.
 *
 * The front-end parses these with `new Date(...)`, which only guarantees
 * ISO 8601. MySQL's own "Y-m-d H:i:s" is parsed as LOCAL time by some
 * browsers and as invalid by others, so every date leaves through here.
 */
function iso8601(?string $mysqlDateTime): ?string
{
    if ($mysqlDateTime === null || $mysqlDateTime === '' || str_starts_with($mysqlDateTime, '0000')) {
        return null;
    }
    try {
        return (new DateTimeImmutable($mysqlDateTime, new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.v\Z');
    } catch (Exception $e) {
        return null;
    }
}

/** Current UTC time in the format every DATETIME column in this schema uses. */
function now_utc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/** Same, offset by a number of seconds. Negative values go backwards. */
function utc_offset(int $seconds): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')
        ->format('Y-m-d H:i:s');
}

/**
 * JSON column → PHP value.
 *
 * Returns the fallback for NULL, empty strings and anything that does not
 * parse, so a corrupted row degrades to an empty gallery rather than a 500
 * on the whole listing feed.
 */
function json_column($raw, $fallback = null)
{
    if ($raw === null || $raw === '') {
        return $fallback;
    }
    $decoded = json_decode((string) $raw, true);
    return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $fallback : $decoded;
}

/** PHP value → JSON column. NULL stays NULL rather than becoming "null". */
function json_store($value): ?string
{
    if ($value === null) {
        return null;
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
