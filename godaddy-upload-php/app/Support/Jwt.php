<?php
/**
 * HS256 JSON Web Tokens, without a library.
 *
 * The tokens are byte-for-byte what jsonwebtoken produced in the Node build,
 * including the iss/aud/iat/exp claims — a session started against the Node
 * API stays valid here if the secrets match, and vice versa.
 *
 * Written by hand because the alternative is vendoring a package onto a
 * shared host that may have no Composer, to do two hash_hmac calls.
 *
 * ── The one rule ──────────────────────────────────────────────────────
 * The algorithm is fixed at HS256 and the `alg` header from the token is
 * checked against it, never used to select the algorithm. Reading `alg`
 * from attacker-controlled input is how the classic "alg: none" and
 * "RS256 verified as HS256 with the public key" forgeries work.
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;

final class Jwt
{
    private const ALG = 'HS256';

    /** @param array<string,mixed> $claims */
    public static function sign(array $claims, string $secret, int $ttlSeconds, string $issuer, string $audience): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'iss' => $issuer,
            'aud' => $audience,
        ]);

        $header = self::b64(json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));

        return "$header.$body.$signature";
    }

    /**
     * Verify and decode. Throws ApiError(401) on anything wrong — a bad
     * signature, a wrong issuer, an expired token — so callers have one
     * failure mode rather than several.
     *
     * @return array<string,mixed>
     */
    public static function verify(string $token, string $secret, string $issuer, string $audience): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ApiError::unauthorized('Invalid authentication token');
        }
        [$header64, $body64, $signature64] = $parts;

        $header = json_decode(self::unb64($header64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALG) {
            throw ApiError::unauthorized('Invalid authentication token');
        }

        $expected = self::b64(hash_hmac('sha256', "$header64.$body64", $secret, true));
        /* Constant-time. A byte-by-byte comparison leaks, through timing,
           how much of a forged signature was correct — which is enough to
           reconstruct the rest one byte at a time. */
        if (!hash_equals($expected, $signature64)) {
            throw ApiError::unauthorized('Invalid authentication token');
        }

        $payload = json_decode(self::unb64($body64), true);
        if (!is_array($payload)) {
            throw ApiError::unauthorized('Invalid authentication token');
        }

        $now = time();
        /* 60 seconds of leeway, for clock skew between the browser and the
           server. Applied to exp only — accepting a token from the future
           would be accepting one that was never issued. */
        if (isset($payload['exp']) && $now > ((int) $payload['exp'] + 60)) {
            throw ApiError::unauthorized('Session expired. Please sign in again.');
        }
        if (($payload['iss'] ?? null) !== $issuer || ($payload['aud'] ?? null) !== $audience) {
            /* A token minted for a different service, or by an older
               deployment with different settings. */
            throw ApiError::unauthorized('Invalid authentication token');
        }

        return $payload;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string
    {
        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (ceil(strlen($encoded) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }
}
