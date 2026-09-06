<?php
/**
 * Validation entry point.
 *
 *     $body = Validate::against(PropertySchema::create(), $req->body());
 *
 * Returns the parsed, coerced data, or throws the 422 envelope the
 * front-end knows how to display. Controllers never see a raw request body.
 */
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;

final class Validate
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function against(ObjectRule $schema, array $data): array
    {
        try {
            return $schema->parse($data, '');
        } catch (FieldError $e) {
            throw ApiError::unprocessable('Validation failed', [
                ['field' => $e->field, 'message' => $e->getMessage()],
            ]);
        }
    }

    /** @param array<string,Rule> $shape */
    public static function object(array $shape): ObjectRule
    {
        return new ObjectRule($shape);
    }

    public static function arrayOf(Rule $item): ArrayRule
    {
        return new ArrayRule($item);
    }
}
