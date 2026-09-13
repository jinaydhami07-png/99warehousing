<?php
/**
 * The response envelope.
 *
 * Every endpoint answers in the same shape, because assets/api.js unwraps it
 * that way and nothing else:
 *
 *     { success, message, data?, meta? }
 *
 * unwrap() in api.js merges `data` and `meta` into one object for the
 * caller, and turns an array `data` into { items: [...] } — so a list route
 * MUST put the array in `data` and its counts in `meta`, and a single-object
 * route MUST nest under a key ({ item: … }, { user: … }). Getting that wrong
 * does not error anywhere; the page just renders nothing.
 */
declare(strict_types=1);

namespace App\Http;

use App\Config\Env;
use App\Config\Logger;

final class Response
{
    private static bool $sent = false;

    /** @param array<string,mixed>|null $data */
    public static function success($data = null, string $message = 'OK', int $status = 200, ?array $meta = null): void
    {
        $payload = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    public static function created($data = null, string $message = 'Created'): void
    {
        self::success($data, $message, 201);
    }

    public static function noContent(): void
    {
        self::$sent = true;
        http_response_code(204);
    }

    /**
     * A page of results. `items` goes in data, the counts in meta — see the
     * class docblock for why that split is load-bearing.
     *
     * @param array<int,mixed> $items
     */
    public static function paginated(array $items, int $page, int $limit, int $total, string $message = 'OK'): void
    {
        self::success($items, $message, 200, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / max(1, $limit))),
            'hasNext' => $page * $limit < $total,
            'hasPrev' => $page > 1,
        ]);
    }

    public static function redirect(string $url, int $status = 302): void
    {
        self::$sent = true;
        http_response_code($status);
        header('Location: ' . $url);
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Turn any throwable into the error envelope.
     *
     * The client message for a non-operational failure is deliberately
     * generic in production: an uncaught PDOException carries the SQL and
     * sometimes the schema with it, and that belongs in the log, not in a
     * browser.
     */
    public static function error(\Throwable $e, ?Request $request = null): void
    {
        $isApi = $e instanceof ApiError;
        $status = $isApi ? $e->status : 500;
        $operational = $isApi ? $e->operational : false;

        $context = [
            'err' => get_class($e) . ': ' . $e->getMessage(),
            'method' => $request->method ?? null,
            'path' => $request->path ?? null,
            'ip' => $request ? $request->ip() : null,
            'userId' => $request->user['id'] ?? null,
        ];
        if (!Env::isProd()) {
            $context['at'] = $e->getFile() . ':' . $e->getLine();
        }

        if ($status >= 500 || !$operational) {
            Logger::error('Unhandled error', $context);
        } else {
            Logger::warn('Request error', $context);
        }

        $payload = [
            'success' => false,
            'message' => (!$operational && Env::isProd()) ? 'Something went wrong on our end' : $e->getMessage(),
        ];
        if ($isApi && $e->details) {
            $payload['errors'] = $e->details;
        }
        if ($request !== null) {
            $payload['requestId'] = $request->id;
        }
        if (!Env::isProd()) {
            $payload['stack'] = $e->getFile() . ':' . $e->getLine();
        }

        self::json($payload, $status);
    }
}
