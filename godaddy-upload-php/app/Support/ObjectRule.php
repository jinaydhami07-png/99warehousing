<?php
/**
 * An object with a fixed set of known keys.
 *
 * ── Strict by default ─────────────────────────────────────────────────
 * An unknown key is a 422, not a silent strip. Silent stripping is how the
 * admin form lost its Owner and Status fields in the original app: the
 * request succeeded, and the values simply never arrived. A loud rejection
 * makes that a five-minute bug instead of a six-month one.
 *
 * It is also a security property. The create schema has no `status` key, so
 * a submitter who adds one to the body is refused rather than quietly
 * ignored — and cannot discover that the field is being dropped and go
 * looking for another way in.
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Support;

final class ObjectRule extends Rule
{
    /** @var array<string,Rule> */
    private array $shape;
    private bool $strict = true;
    private ?string $nonEmptyMessage = null;
    /** @var array<int,array{0:callable,1:string,2:string}> */
    private array $refinements = [];
    /** @var array<string,string> */
    private array $requiredMessages = [];

    /** @param array<string,Rule> $shape */
    public function __construct(array $shape)
    {
        $this->shape = $shape;
        $this->type = 'object';
    }

    /** Allow and drop unknown keys instead of rejecting them. */
    public function loose(): self
    {
        $this->strict = false;
        return $this;
    }

    /** zod's .refine(v => Object.keys(v).length > 0). */
    public function nonEmpty(string $message = 'Provide at least one field to update'): self
    {
        $this->nonEmptyMessage = $message;
        return $this;
    }

    /** Cross-field rule — e.g. a rejection must carry a reason. */
    public function refine(callable $predicate, string $message, string $field = 'body'): self
    {
        $this->refinements[] = [$predicate, $message, $field];
        return $this;
    }

    /** Override the generated "X is required" text for one key. */
    public function requires(string $key, string $message): self
    {
        $this->requiredMessages[$key] = $message;
        return $this;
    }

    /** @return array<string,Rule> */
    public function shape(): array
    {
        return $this->shape;
    }

    public function parse($value, string $field)
    {
        if ($value === null) {
            if ($this->nullable) {
                return null;
            }
            $value = [];
        }
        if (!is_array($value)) {
            throw new FieldError($field !== '' ? $field : 'body', 'Must be an object');
        }

        $prefix = $field === '' ? '' : "$field.";

        if ($this->strict) {
            $unknown = array_diff(array_keys($value), array_keys($this->shape));
            if ($unknown) {
                throw new FieldError($prefix . (string) reset($unknown), 'Unexpected field');
            }
        }

        $out = [];
        foreach ($this->shape as $key => $rule) {
            if (!array_key_exists($key, $value)) {
                if (!$rule->isOptional()) {
                    throw new FieldError($prefix . $key, $this->requiredMessages[$key] ?? self::requiredMessage($key));
                }
                continue;
            }
            $out[$key] = $rule->parse($value[$key], $prefix . $key);
        }

        if ($this->nonEmptyMessage !== null && !$out) {
            throw new FieldError($field !== '' ? $field : 'body', $this->nonEmptyMessage);
        }

        foreach ($this->refinements as [$predicate, $message, $target]) {
            if (!$predicate($out)) {
                throw new FieldError($target, $message);
            }
        }

        return $out;
    }

    /** "City is required" reads better than "city: This field is required". */
    private static function requiredMessage(string $key): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $key);
        return ucfirst(strtolower((string) $spaced)) . ' is required';
    }
}
