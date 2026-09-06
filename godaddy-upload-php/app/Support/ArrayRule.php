<?php
/**
 * A homogeneous list.
 *
 * maxItems is a real limit, not politeness: without it a single request can
 * ask the server to validate and store an unbounded array — twelve photos
 * per listing is the product rule, and it is enforced here as well as by the
 * upload endpoint.
 */
declare(strict_types=1);

namespace App\Support;

final class ArrayRule extends Rule
{
    private Rule $item;
    private ?int $maxItems = null;

    public function __construct(Rule $item)
    {
        $this->item = $item;
        $this->type = 'array';
    }

    public function maxItems(int $n): self
    {
        $this->maxItems = $n;
        return $this;
    }

    public function parse($value, string $field)
    {
        if ($value === null) {
            if ($this->nullable) {
                return null;
            }
            throw new FieldError($field, 'This field is required');
        }
        /* A JSON object decodes to a PHP associative array, so "is this a
           list?" has to be asked explicitly rather than assumed from is_array. */
        if (!is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            throw new FieldError($field, 'Must be a list');
        }
        if ($this->maxItems !== null && count($value) > $this->maxItems) {
            throw new FieldError($field, "Cannot contain more than {$this->maxItems} items");
        }

        $out = [];
        foreach ($value as $i => $entry) {
            $out[] = $this->item->parse($entry, "$field.$i");
        }
        return $out;
    }
}
