<?php
/**
 * Rate limiting, backed by a database table.
 *
 * ── Why a table and not APCu or a file ────────────────────────────────
 * PHP has no long-lived process to hold counters in, so express-rate-limit's
 * in-memory store has no equivalent. APCu is not installed on most shared
 * hosts and is per-process under FastCGI anyway. Files need their own
 * locking and leave litter to sweep up.
 *
 * The table is the one thing already guaranteed to exist, shared across
 * every PHP worker, and durable. The cost is one INSERT..ON DUPLICATE KEY
 * per request — a primary-key upsert, which is about as cheap as a query
 * gets.
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Config\Env;
use App\Http\ApiError;
use App\Http\Request;

final class RateLimit
{
    /**
     * Count one hit against a bucket and throw 429 once it is over the limit.
     *
     * @param string $key what is being limited: an IP, a user id, or an
     *                    IP+email pair — see the callers below.
     */
    public static function hit(string $key, int $max, int $windowSeconds, string $message = 'Too many requests. Please slow down.'): void
    {
        $bucket = substr($key, 0, 190);
        $now = now_utc();
        $resetAt = utc_offset($windowSeconds);

        /* One statement, no read-then-write, so two concurrent requests
           cannot both see "0 hits" and both be allowed through.

           The CASE resets the window in the same breath: if the stored
           reset_at is in the past this is a new window, so hits goes back to
           1 rather than continuing to climb from the previous one. */
        Database::run(
            'INSERT INTO rate_limits (bucket, hits, reset_at)
                  VALUES (:bucket, 1, :reset_at)
             ON DUPLICATE KEY UPDATE
                  hits     = IF(reset_at <= :now, 1, hits + 1),
                  reset_at = IF(reset_at <= :now2, :reset_at2, reset_at)',
            [
                'bucket' => $bucket,
                'reset_at' => $resetAt,
                'now' => $now,
                'now2' => $now,
                'reset_at2' => $resetAt,
            ]
        );

        $row = Database::first('SELECT hits, reset_at FROM rate_limits WHERE bucket = :bucket', ['bucket' => $bucket]);
        $hits = (int) ($row['hits'] ?? 1);
        $reset = (string) ($row['reset_at'] ?? $resetAt);

        if (!headers_sent()) {
            header('RateLimit-Limit: ' . $max);
            header('RateLimit-Remaining: ' . max(0, $max - $hits));
            header('RateLimit-Reset: ' . max(0, strtotime($reset . ' UTC') - time()));
        }

        if ($hits > $max) {
            throw ApiError::tooMany($message);
        }
    }

    /**
     * Undo one hit.
     *
     * The sign-in limiter counts failed attempts only — a user who signs in
     * successfully ten times in a row must not be locked out. The handler
     * calls this after a successful authentication, which is the PHP
     * equivalent of express-rate-limit's skipSuccessfulRequests.
     */
    public static function forgive(string $key): void
    {
        Database::run(
            'UPDATE rate_limits SET hits = GREATEST(0, hits - 1) WHERE bucket = :bucket',
            ['bucket' => substr($key, 0, 190)]
        );
    }

    /** Everything under /api, minus the health check a monitor polls. */
    public static function global(Request $request): void
    {
        if (str_starts_with($request->path, '/health')) {
            return;
        }
        self::hit(
            'global:' . $request->ip(),
            (int) Env::get('rateLimit.max', 300),
            (int) Env::get('rateLimit.windowSeconds', 900)
        );
    }

    /**
     * Credential endpoints — the routes an attacker points a password list
     * at. Keyed by IP *and* email so one attacker cannot lock out a real
     * user by burning their bucket from elsewhere.
     */
    public static function auth(Request $request): void
    {
        self::hit(
            self::authKey($request),
            (int) Env::get('rateLimit.authMax', 10),
            (int) Env::get('rateLimit.windowSeconds', 900),
            'Too many failed attempts. Try again in a few minutes.'
        );
    }

    public static function authKey(Request $request): string
    {
        $email = strtolower(trim((string) ($request->body['email'] ?? '')));
        return 'auth:' . $request->ip() . ':' . $email;
    }

    /** Writes: creating listings, posting reviews, sending enquiries. */
    public static function write(Request $request): void
    {
        $who = $request->user['id'] ?? $request->ip();
        self::hit('write:' . $who, 40, 3600);
    }

    /**
     * Owner contact details.
     *
     * Requiring a session is what stops an anonymous scraper; this is what
     * stops one that signed up. Keyed by account first so a scraper cannot
     * simply rotate IPs.
     */
    public static function contact(Request $request): void
    {
        $who = isset($request->user['id']) ? 'u:' . $request->user['id'] : 'ip:' . $request->ip();
        self::hit(
            'contact:' . $who,
            30,
            3600,
            'You have viewed a lot of contact details. Please try again later.'
        );
    }

    /**
     * Delete expired buckets.
     *
     * Called opportunistically (roughly 1 request in 200) rather than from
     * cron, because a shared host may not offer one. The table would work
     * correctly without this — an expired row is reset on its next hit — but
     * would grow one row per IP that ever visited.
     */
    public static function sweep(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }
        try {
            Database::run('DELETE FROM rate_limits WHERE reset_at < :cutoff', ['cutoff' => utc_offset(-86400)]);
        } catch (\Throwable $e) {
            // Housekeeping. Never fails a request.
        }
    }
}
