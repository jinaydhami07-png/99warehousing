<?php
/**
 * One field's validation rules — the zod replacement.
 *
 * The Node API validated every request against a zod schema before a
 * controller saw it, and the front-end depends on the shape of what comes
 * back when that fails:
 *
 *     { success: false, message: 'Validation failed',
 *       errors: [ { field: 'rate', message: 'Rate must be greater than zero' } ] }
 *
 * assets/api.js prefers errors[0] over the generic message when it puts text
 * on a form, so the user reads something they can act on rather than
 * "Request failed (422)". That contract is why this layer exists instead of
 * a pile of if-statements per controller.
 *
 * Coercion is carried over from z.coerce deliberately: query strings and
 * multipart fields arrive as strings, so number() accepts "42" and yields 42.
 *
 * Builder methods return $this, so a schema reads as one expression:
 *     Rule::string()->trim()->min(3, 'Title must be at least 3 characters')->max(160)
 */
declare(strict_types=1);

namespace App\Support;

class Rule
{
    /** @var array<int,callable> */
    protected array $steps = [];
    protected bool $optional = false;
    protected bool $nullable = false;
    protected string $type = 'string';

    public static function string(): self
    {
        $r = new self();
        $r->type = 'string';
        $r->steps[] = static function ($v, string $field) {
            if (is_int($v) || is_float($v)) {
                $v = (string) $v;
            }
            if (!is_string($v)) {
                throw new FieldError($field, 'Must be text');
            }
            return $v;
        };
        return $r;
    }

    /** Accepts "42" as well as 42 — query and form values are always strings. */
    public static function number(): self
    {
        $r = new self();
        $r->type = 'number';
        $r->steps[] = static function ($v, string $field) {
            if (is_bool($v) || $v === '' || !is_numeric($v)) {
                throw new FieldError($field, 'Must be a number');
            }
            return $v + 0;
        };
        return $r;
    }

    /**
     * Real booleans, plus the strings a form sends for them. A JSON body
     * carries true/false; a multipart form can only carry "true".
     */
    public static function boolean(): self
    {
        $r = new self();
        $r->type = 'boolean';
        $r->steps[] = static function ($v, string $field) {
            if (is_bool($v)) {
                return $v;
            }
            if ($v === 'true' || $v === '1' || $v === 1) {
                return true;
            }
            if ($v === 'false' || $v === '0' || $v === 0) {
                return false;
            }
            throw new FieldError($field, 'Must be true or false');
        };
        return $r;
    }

    /** @param array<int,string> $allowed */
    public static function enum(array $allowed): self
    {
        $r = self::string();
        $r->steps[] = static function ($v, string $field) use ($allowed) {
            if (!in_array($v, $allowed, true)) {
                throw new FieldError($field, 'Must be one of: ' . implode(', ', $allowed));
            }
            return $v;
        };
        return $r;
    }

    /** Anything DateTimeImmutable understands; yields 'Y-m-d H:i:s' in UTC. */
    public static function date(): self
    {
        $r = new self();
        $r->type = 'date';
        $r->steps[] = static function ($v, string $field) {
            if ($v instanceof \DateTimeInterface) {
                return $v->format('Y-m-d H:i:s');
            }
            if (!is_string($v) || trim($v) === '') {
                throw new FieldError($field, 'Must be a date');
            }
            try {
                return (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                throw new FieldError($field, 'Must be a valid date');
            }
        };
        return $r;
    }

    public static function objectId(string $message = 'Invalid id'): self
    {
        $r = self::string();
        $r->steps[] = static function ($v, string $field) use ($message) {
            if (!ObjectId::isValid($v)) {
                throw new FieldError($field, $message);
            }
            return $v;
        };
        return $r;
    }

    /** Any value, unchecked. */
    public static function any(): self
    {
        $r = new self();
        $r->type = 'any';
        return $r;
    }

    /* ── Modifiers ── */

    public function trim(): self
    {
        $this->steps[] = static fn($v) => is_string($v) ? trim($v) : $v;
        return $this;
    }

    public function lower(): self
    {
        $this->steps[] = static fn($v) => is_string($v) ? mb_strtolower($v) : $v;
        return $this;
    }

    public function min($limit, ?string $message = null): self
    {
        $type = $this->type;
        $this->steps[] = static function ($v, string $field) use ($limit, $message, $type) {
            if ($type === 'number') {
                if ($v < $limit) {
                    throw new FieldError($field, $message ?? "Must be at least $limit");
                }
            } elseif (mb_strlen((string) $v) < $limit) {
                throw new FieldError($field, $message ?? "Must be at least $limit characters");
            }
            return $v;
        };
        return $this;
    }

    public function max($limit, ?string $message = null): self
    {
        $type = $this->type;
        $this->steps[] = static function ($v, string $field) use ($limit, $message, $type) {
            if ($type === 'number') {
                if ($v > $limit) {
                    throw new FieldError($field, $message ?? "Must be at most $limit");
                }
            } elseif (mb_strlen((string) $v) > $limit) {
                throw new FieldError($field, $message ?? "Cannot exceed $limit characters");
            }
            return $v;
        };
        return $this;
    }

    public function int(?string $message = null): self
    {
        $this->steps[] = static function ($v, string $field) use ($message) {
            if (floor((float) $v) != $v) {
                throw new FieldError($field, $message ?? 'Must be a whole number');
            }
            return (int) $v;
        };
        return $this;
    }

    public function positive(?string $message = null): self
    {
        $this->steps[] = static function ($v, string $field) use ($message) {
            if ($v <= 0) {
                throw new FieldError($field, $message ?? 'Must be greater than zero');
            }
            return $v;
        };
        return $this;
    }

    public function nonNegative(?string $message = null): self
    {
        $this->steps[] = static function ($v, string $field) use ($message) {
            if ($v < 0) {
                throw new FieldError($field, $message ?? 'Cannot be negative');
            }
            return $v;
        };
        return $this;
    }

    public function email(string $message = 'Please provide a valid email address'): self
    {
        $this->trim();
        $this->lower();
        $this->steps[] = static function ($v, string $field) use ($message) {
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new FieldError($field, $message);
            }
            return $v;
        };
        return $this;
    }

    public function pattern(string $regex, string $message): self
    {
        $this->steps[] = static function ($v, string $field) use ($regex, $message) {
            if (preg_match($regex, (string) $v) !== 1) {
                throw new FieldError($field, $message);
            }
            return $v;
        };
        return $this;
    }

    /** Arbitrary predicate — zod's .refine(). */
    public function check(callable $predicate, string $message): self
    {
        $this->steps[] = static function ($v, string $field) use ($predicate, $message) {
            if (!$predicate($v)) {
                throw new FieldError($field, $message);
            }
            return $v;
        };
        return $this;
    }

    /** Absent is fine; an explicit null is not. Use nullish() for that. */
    public function optional(): self
    {
        $this->optional = true;
        return $this;
    }

    /**
     * Absent or null, both fine.
     *
     * Matters for the admin edit form, which submits every field on every
     * save and sends null for a value the admin has cleared. Without null in
     * the type, clearing a field would fail the whole request — and omitting
     * the key instead would leave the old value in place, which reads to the
     * admin as the edit being silently ignored.
     */
    public function nullish(): self
    {
        $this->optional = true;
        $this->nullable = true;
        return $this;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    /**
     * Run the chain. $field is the dotted path reported on failure.
     *
     * @throws FieldError
     */
    public function parse($value, string $field)
    {
        if ($value === null) {
            if ($this->nullable) {
                return null;
            }
            throw new FieldError($field, 'This field is required');
        }
        foreach ($this->steps as $step) {
            $value = $step($value, $field);
        }
        return $value;
    }
}
