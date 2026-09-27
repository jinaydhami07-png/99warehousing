<?php
/**
 * The incoming request, parsed once.
 *
 * Replaces what Express did with express.json(), cookie-parser and the
 * sanitisation middleware. Everything a controller reads about the request
 * comes from here, so there is one place where the parsing rules live and
 * one place where the input is cleaned.
 */
declare(strict_types=1);

namespace App\Http;

use App\Config\Env;
use App\Config\Logger;

final class Request
{
    public string $method;
    /** Path with the /api/v1 prefix removed: '/properties/abc/reviews'. */
    public string $path;
    /** @var array<string,mixed> */
    public array $query = [];
    /** @var array<string,mixed> */
    public array $body = [];
    /** @var array<string,string> */
    public array $params = [];
    /** @var array<string,array<string,mixed>> Normalised $_FILES. */
    public array $files = [];
    public string $id;
    /** The authenticated user row, or null. Set by Auth middleware. */
    public ?array $user = null;

    private const API_PREFIX = '/api/v1';

    public static function capture(): self
    {
        $r = new self();

        $r->id = bin2hex(random_bytes(8));
        Logger::setRequestId($r->id);

        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        /* The rewrite in public/.htaccess sends every /api/v1/* URL here
           without changing REQUEST_URI, so the original path is still
           readable — which keeps routing independent of how the rewrite is
           written. A host that cannot rewrite can instead point at
           api.php/properties directly, and PATH_INFO covers that. */
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rawurldecode($path);

        if (str_starts_with($path, self::API_PREFIX)) {
            $path = substr($path, strlen(self::API_PREFIX));
        } elseif (isset($_SERVER['PATH_INFO'])) {
            $path = (string) $_SERVER['PATH_INFO'];
        }
        $r->path = '/' . trim($path, '/');

        $r->query = self::clean($_GET);
        $r->body = self::clean(self::readBody());
        $r->files = self::normaliseFiles();

        return $r;
    }

    /* ── Body parsing ── */

    /** @return array<string,mixed> */
    private static function readBody(): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        /* PHP has already parsed a multipart body into $_POST by the time
           this runs; re-reading php://input for one would return nothing. */
        if (str_contains($type, 'multipart/form-data')) {
            return $_POST;
        }

        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            if ($_POST) {
                return $_POST;
            }
            /* PHP only fills $_POST for POST. A urlencoded PATCH or DELETE
               body has to be parsed by hand. */
            parse_str(self::rawInput(), $parsed);
            return is_array($parsed) ? $parsed : [];
        }

        $raw = self::rawInput();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ApiError::badRequest('Malformed JSON in request body');
        }
        /* A bare array or scalar body is not something any route here
           accepts; treating it as an empty object gives the schema a clean
           "field is required" rather than a type error from deep inside. */
        return is_array($decoded) ? $decoded : [];
    }

    private static function rawInput(): string
    {
        $limit = (int) Env::get('bodyLimitBytes', 102400);
        $handle = fopen('php://input', 'rb');
        if ($handle === false) {
            return '';
        }
        /* Read one byte past the limit so an over-sized body is detected
           rather than silently truncated into invalid JSON. The limit is a
           DoS control: without it a single request can buffer an arbitrary
           amount into memory. */
        $raw = (string) stream_get_contents($handle, $limit + 1);
        fclose($handle);

        if (strlen($raw) > $limit) {
            throw new ApiError(413, 'Request body is too large');
        }
        return $raw;
    }

    /* ── Sanitisation ──
       Runs on every string in the query and body, before any handler or
       schema sees them. Two separate jobs:

       1. Strip HTML. These values are written into pages by the front-end,
          so anything that survives as markup here is stored XSS later.
       2. Drop keys that would poison an object's prototype chain in the
          JSON that goes back out, and reject the $-prefixed keys that were
          a NoSQL injection vector in the Mongo build. Nothing in this app
          builds a query from a request key any more — every statement is a
          prepared one — but the keys have no legitimate use either, and
          dropping them keeps a future call site from reintroducing the hole. */

    private const FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];

    /**
     * @param array<mixed> $input
     * @return array<string,mixed>
     */
    private static function clean(array $input, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }
        $out = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::FORBIDDEN_KEYS, true) || str_starts_with($key, '$')) {
                Logger::warn('Dropped unsafe key from request', ['key' => $key]);
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = self::stripTags($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Remove markup without destroying ordinary text.
     *
     * strip_tags() alone would turn "10 < 20" into "10 " by swallowing
     * everything after an unmatched '<'. Escaping first and decoding after
     * keeps a plain angle bracket intact while a real tag is neutralised.
     */
    private static function stripTags(string $value): string
    {
        if (strpbrk($value, '<>') === false) {
            return $value;
        }
        return htmlspecialchars_decode(
            strip_tags(htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8')),
            ENT_NOQUOTES
        );
    }

    /* ── Uploads ──
       $_FILES nests differently for files[] than for a single file, which is
       a long-standing PHP wart. Flatten it once here so the image service
       only ever sees a plain list. */

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function normaliseFiles(): array
    {
        $out = [];
        foreach ($_FILES as $field => $info) {
            if (!is_array($info) || !isset($info['name'])) {
                continue;
            }
            if (is_array($info['name'])) {
                foreach (array_keys($info['name']) as $i) {
                    $out[$field][] = [
                        'name' => $info['name'][$i],
                        'type' => $info['type'][$i] ?? '',
                        'tmp_name' => $info['tmp_name'][$i] ?? '',
                        'error' => (int) ($info['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'size' => (int) ($info['size'][$i] ?? 0),
                    ];
                }
            } else {
                $out[$field][] = [
                    'name' => $info['name'],
                    'type' => $info['type'] ?? '',
                    'tmp_name' => $info['tmp_name'] ?? '',
                    'error' => (int) ($info['error'] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($info['size'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /* ── Accessors ── */

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        // Content-Type and Content-Length arrive without the HTTP_ prefix.
        $bare = str_replace('-', '_', strtoupper($name));
        return isset($_SERVER[$bare]) ? (string) $_SERVER[$bare] : null;
    }

    public function cookie(string $name): ?string
    {
        return isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header === null) {
            /* Some Apache configurations drop the Authorization header
               before PHP sees it; public/.htaccess re-adds it as
               REDIRECT_HTTP_AUTHORIZATION, and this is where that is read. */
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }

    /**
     * The client's address.
     *
     * X-Forwarded-For is only consulted when TRUST_PROXY says a proxy is
     * actually in front of the app. Trusting it unconditionally would let
     * anyone set the header themselves and get a fresh rate-limit bucket per
     * request — the limiter would then protect nothing at all.
     */
    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $hops = (int) Env::get('trustProxy', 0);
        if ($hops <= 0) {
            return $remote;
        }
        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded === null) {
            return $remote;
        }
        /* Rightmost entries are the ones added by proxies we trust; count
           back that many and take the address before them. */
        $chain = array_map('trim', explode(',', $forwarded));
        $index = count($chain) - $hops;
        $candidate = $chain[max(0, $index)] ?? $remote;
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->header('User-Agent') ?? ''), 0, 500);
    }

    public function origin(): ?string
    {
        return $this->header('Origin');
    }

    /** Scheme + host as the browser sees it — used to build OAuth callbacks. */
    public function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) Env::get('trustProxy', 0) > 0 && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https');
        $host = (string) ($this->header('Host') ?? 'localhost');
        return ($https ? 'https://' : 'http://') . $host;
    }
}
