<?php
/**
 * One failed field, carrying the path so the client can highlight it.
 *
 * Thrown inside the rule chain and caught once, at the top of Validate, to
 * become the 422 envelope the front-end reads.
 */
declare(strict_types=1);

namespace App\Support;

final class FieldError extends \RuntimeException
{
    public string $field;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);
        $this->field = $field;
    }
}
