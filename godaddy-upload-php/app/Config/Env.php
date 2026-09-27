<?php
/**
 * Environment configuration — the PHP counterpart of server/src/config/env.js.
 *
 * Same contract as the Node version: parse once, validate hard, expose a
 * frozen structure, and refuse to start in production with settings that
 * would be a live vulnerability. A misconfiguration should stop the app at
 * boot with a readable message, not surface later as a subtle security hole.
 */
declare(strict_types=1);

namespace App\Config;

final class Env
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @var string[] Collected during load; fatal in production. */
    private static array $productionProblems = [];

    /**
     * The parsed configuration. Reads config/.env on first call.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = self::load();
        return self::$cache;
    }

    /** Dot-path lookup: Env::get('jwt.accessSecret'). */
    public static function get(string $path, $default = null)
    {
        $node = self::all();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function isProd(): bool
    {
        return self::get('env') === 'production';
    }

    /**
     * Parse config/.env into $_ENV-style values.
     *
     * Deliberately minimal: KEY=value, # comments, optional surrounding
     * quotes. No variable interpolation — a ${...} in a database password is
     * far more likely than a deliberate reference, and expanding it would
     * silently corrupt the credential.
     *
     * @return array<string,string>
     */
    private static function readEnvFile(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $len = strlen($value);
            if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function load(): array
    {
        $file = APP_ROOT . '/config/.env';
        $values = self::readEnvFile($file);

        /* Real environment variables win over the file. That is how a host
           with a secret manager (or cPanel's own environment settings) is
           meant to supply these, and it lets .env stay a development
           convenience rather than the only supported path. */
        $get = static function (string $key, ?string $default = null) use ($values): ?string {
            $raw = getenv($key);
            if ($raw === false || $raw === '') {
                $raw = $values[$key] ?? null;
            }
            if ($raw === null) {
                return $default;
            }
            $raw = trim((string) $raw);
            /* Placeholders from .env.example read as "not set" rather than as
               a literal value, so a half-filled file behaves like an empty
               one instead of, say, connecting to a host called
               "<your-db-host>". */
            if ($raw === '' || str_contains($raw, '<<') || (str_starts_with($raw, '<') && str_ends_with($raw, '>'))) {
                return $default;
            }
            return $raw;
        };

        $bool = static function (?string $v, bool $default = false): bool {
            if ($v === null) {
                return $default;
            }
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        };

        $int = static function (?string $v, int $default): int {
            return $v === null || !is_numeric($v) ? $default : (int) $v;
        };

        $csv = static function (?string $v): array {
            return array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn($s) => $s !== ''));
        };

        $appEnv = $get('APP_ENV', 'development');
        $isProd = $appEnv === 'production';

        $required = static function (string $key, ?string $value) use ($isProd): string {
            if ($value === null || $value === '') {
                self::fail("$key is required. Copy config/.env.example to config/.env and fill it in.");
            }
            return $value;
        };

        $accessSecret = $required('JWT_ACCESS_SECRET', $get('JWT_ACCESS_SECRET'));
        $refreshSecret = $required('JWT_REFRESH_SECRET', $get('JWT_REFRESH_SECRET'));

        if (hash_equals($accessSecret, $refreshSecret)) {
            self::fail('JWT_ACCESS_SECRET and JWT_REFRESH_SECRET must differ — sharing one key lets a stolen access token be replayed as a refresh token.');
        }

        $config = [
            'env' => $appEnv,
            'isProd' => $isProd,
            'appUrl' => rtrim((string) $get('APP_URL', 'http://localhost:8080'), '/'),
            'logLevel' => $get('LOG_LEVEL', 'info'),
            'logFile' => $get('LOG_FILE', APP_ROOT . '/storage/logs/app.log'),

            /* ── Where the web root actually is ───────────────────────────
               Uploaded images are written under it and served from it by
               Apache, so the app has to know the real path — and it is NOT
               always APP_ROOT/public.

               In the recommended cPanel layout the application sits OUTSIDE
               the web root, as a sibling of public_html rather than its
               parent, so nothing but the site itself is reachable over HTTP.
               Assuming APP_ROOT/public there writes uploads into a directory
               Apache never serves: the upload succeeds, the row is written,
               and every photo 404s.

               Resolved in three steps, most explicit first:
                 1. PUBLIC_DIR in the environment — set by the deploy package
                 2. the PUBLIC_ROOT constant, which api.php defines from its
                    own location, so the web path is right even unconfigured
                 3. APP_ROOT/public, the development layout
               ───────────────────────────────────────────────────────────── */
            'publicDir' => self::resolvePublicDir($get('PUBLIC_DIR')),
            /* Number of reverse proxies in front of the app. Never a boolean:
               trusting a client-supplied X-Forwarded-For would let anyone
               spoof their IP and walk straight through the rate limiter. */
            'trustProxy' => $int($get('TRUST_PROXY'), 0),

            'db' => [
                'host' => $get('DB_HOST', '127.0.0.1'),
                'port' => $int($get('DB_PORT'), 3306),
                'name' => $required('DB_NAME', $get('DB_NAME')),
                'user' => $required('DB_USER', $get('DB_USER')),
                'password' => (string) $get('DB_PASSWORD', ''),
                'charset' => $get('DB_CHARSET', 'utf8mb4'),
                'socket' => $get('DB_SOCKET'),
            ],

            'corsOrigins' => $csv($get('CORS_ORIGINS', 'http://localhost:8080')),

            'jwt' => [
                'accessSecret' => $accessSecret,
                'refreshSecret' => $refreshSecret,
                /* Seconds, not "15m": PHP has no ms/duration parser and a
                   second unit format is one more thing to get wrong. */
                'accessTtl' => $int($get('JWT_ACCESS_TTL'), 900),
                'refreshTtl' => $int($get('JWT_REFRESH_TTL'), 604800),
                'issuer' => 'bpsf-api',
                'audience' => 'bpsf-client',
            ],

            'rateLimit' => [
                'windowSeconds' => $int($get('RATE_LIMIT_WINDOW_MIN'), 15) * 60,
                'max' => $int($get('RATE_LIMIT_MAX'), 300),
                'authMax' => $int($get('AUTH_RATE_LIMIT_MAX'), 10),
            ],

            /* PHP's bcrypt cost. Same meaning as BCRYPT_ROUNDS in the Node
               app, and the hashes are interchangeable — a user row migrated
               from MongoDB verifies here without a reset. */
            'bcryptCost' => max(10, min(15, $int($get('BCRYPT_COST'), 12))),

            'adminPasskey' => $get('ADMIN_PASSKEY'),

            'bodyLimitBytes' => $int($get('BODY_LIMIT_KB'), 100) * 1024,

            'media' => [
                /* Where uploaded bytes go, relative to public/. Apache serves
                   them directly — no PHP process per image, which is the
                   whole reason for choosing disk over a BLOB column here. */
                'dir' => trim((string) $get('MEDIA_DIR', 'uploads'), '/'),
                'widths' => array_values(array_unique(array_filter(
                    array_map('intval', $csv($get('MEDIA_WIDTHS', '320,640,1280,1920'))),
                    static fn(int $w) => $w >= 64 && $w <= 4096
                ))),
                'webpQuality' => max(40, min(95, $int($get('MEDIA_WEBP_QUALITY'), 78))),
                'maxBytes' => $int($get('MEDIA_MAX_MB'), 10) * 1024 * 1024,
                'maxFiles' => $int($get('MEDIA_MAX_FILES'), 12),
                /* Optional CDN or bucket domain in front of public/uploads.
                   Stored per image at upload time, so changing it later does
                   not rewrite URLs already saved onto listings. */
                'publicBaseUrl' => rtrim((string) $get('MEDIA_PUBLIC_BASE_URL', ''), '/') ?: null,
            ],

            'google' => [
                'clientId' => $get('GOOGLE_CLIENT_ID'),
                'clientSecret' => $get('GOOGLE_CLIENT_SECRET'),
                'callbackUrl' => $get('GOOGLE_CALLBACK_URL', 'http://localhost:8080/api/v1/auth/google/callback'),
                'callbackUrls' => [],
                'enabled' => false,
            ],
        ];

        $config['media']['widths'] = $config['media']['widths'] ?: [320, 640, 1280, 1920];
        sort($config['media']['widths']);

        $config['google']['enabled'] = (bool) ($config['google']['clientId'] && $config['google']['clientSecret']);
        $config['google']['callbackUrls'] = array_values(array_unique(array_filter(array_merge(
            [$config['google']['callbackUrl']],
            $csv($get('GOOGLE_CALLBACK_URLS'))
        ))));

        if ($isProd) {
            self::assertProductionSafe($config);
        }

        return $config;
    }

    /**
     * The checks that only matter once the app is reachable from the
     * internet. Each is a mistake that is easy to make by copying
     * .env.example and moving on, and survivable in development but not in
     * production — so they stop the app rather than warn.
     *
     * @param array<string,mixed> $c
     */
    private static function assertProductionSafe(array $c): void
    {
        $problems = [];

        foreach (['accessSecret' => 'JWT_ACCESS_SECRET', 'refreshSecret' => 'JWT_REFRESH_SECRET'] as $key => $name) {
            if (strlen($c['jwt'][$key]) < 32) {
                $problems[] = "$name must be at least 32 characters.";
            }
            if (preg_match('/^(change|changeme|placeholder|dev[_-]|test[_-]|secret$)/i', $c['jwt'][$key])) {
                $problems[] = "$name still looks like a development placeholder. Generate a real one:\n"
                    . "        php -r \"echo bin2hex(random_bytes(48)), PHP_EOL;\"";
            }
        }

        if ($c['adminPasskey'] !== null && strlen($c['adminPasskey']) < 12) {
            $problems[] = 'ADMIN_PASSKEY must be at least 12 characters — it is the only thing standing between the internet and the moderation dashboard.';
        }

        if (in_array('*', $c['corsOrigins'], true)) {
            $problems[] = 'CORS_ORIGINS cannot be "*" — credentials are sent with requests.';
        }
        foreach ($c['corsOrigins'] as $origin) {
            if (preg_match('#^https?://localhost#i', $origin)) {
                $problems[] = 'CORS_ORIGINS still contains localhost. Set it to the real domain.';
                break;
            }
        }

        /* An OAuth callback on http, or on localhost, means Google would send
           the authorization code somewhere that is not this server — or send
           it in clear. */
        if ($c['google']['enabled']) {
            if (preg_match('/localhost|127\.0\.0\.1/', (string) $c['google']['callbackUrl'])) {
                $problems[] = 'GOOGLE_CALLBACK_URL still points at localhost. Set it to the live domain.';
            } elseif (!preg_match('#^https://#i', (string) $c['google']['callbackUrl'])) {
                $problems[] = 'GOOGLE_CALLBACK_URL must use https in production.';
            }
        }

        if ($problems) {
            self::fail("Refusing to start in production:\n    • " . implode("\n    • ", $problems));
        }
    }

    /**
     * Absolute path to the directory the web server serves.
     *
     * A relative PUBLIC_DIR is taken as relative to APP_ROOT, so a config
     * file can say `PUBLIC_DIR=../public_html` without hardcoding the
     * account's home directory — which differs between hosts and would have
     * to be edited on every move.
     */
    private static function resolvePublicDir(?string $configured): string
    {
        if ($configured !== null && $configured !== '') {
            $path = rtrim($configured, '/');
            if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
                $path = APP_ROOT . '/' . $path;
            }
            /* realpath() collapses the ../ and confirms it exists. If it
               does not, keep the literal path: the health check reports a
               missing upload directory far more usefully than a silent
               fallback to a directory that happens to exist. */
            $real = realpath($path);
            return $real !== false ? $real : $path;
        }

        if (defined('PUBLIC_ROOT')) {
            return rtrim((string) PUBLIC_ROOT, '/');
        }

        return APP_ROOT . '/public';
    }

    /**
     * Boot-time configuration failure.
     *
     * On the CLI this prints and exits, like the Node app. Over HTTP it
     * throws, so the request ends as a JSON 500 through the normal error
     * path rather than half a page of PHP output — api.js reads the content
     * type to decide whether a backend exists at all, and an HTML error page
     * would make it fall back to demo data.
     */
    private static function fail(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "\n  ✖ $message\n\n");
            exit(1);
        }
        throw new \RuntimeException('Configuration error: ' . $message);
    }
}
