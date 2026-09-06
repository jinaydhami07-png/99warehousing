<?php
/**
 * The only exception the application throws on purpose.
 *
 * Carries the HTTP status with it, so a service can say "this is a 404"
 * without knowing anything about the transport, and the error handler has
 * one type to recognise rather than a mapping table of exception classes.
 *
 * `operational` separates "the user did something we anticipated" from "the
 * code broke". The second kind never has its message shown in production.
 */
declare(strict_types=1);

namespace App\Http;

use RuntimeException;

class ApiError extends RuntimeException
{
    public int $status;
    /** @var array<int,array{field:string,message:string}>|null */
    public ?array $details;
    public bool $operational;

    public function __construct(int $status, string $message, ?array $details = null, bool $operational = true)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
        $this->operational = $operational;
    }

    public static function badRequest(string $m = 'Bad request', ?array $d = null): self
    {
        return new self(400, $m, $d);
    }

    public static function unauthorized(string $m = 'Authentication required'): self
    {
        return new self(401, $m);
    }

    public static function forbidden(string $m = 'You do not have permission to perform this action'): self
    {
        return new self(403, $m);
    }

    public static function notFound(string $m = 'Resource not found'): self
    {
        return new self(404, $m);
    }

    public static function conflict(string $m = 'Resource already exists'): self
    {
        return new self(409, $m);
    }

    public static function unprocessable(string $m = 'Validation failed', ?array $d = null): self
    {
        return new self(422, $m, $d);
    }

    public static function tooMany(string $m = 'Too many requests'): self
    {
        return new self(429, $m);
    }

    public static function internal(string $m = 'Internal server error'): self
    {
        return new self(500, $m, null, false);
    }
}
