<?php

namespace App\Rules;

use App\Models\JobContentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A contents name must be unique ignoring case ("milk" clashes with "Milk").
 *
 * LOWER() is applied on both sides in SQL so it behaves the same on PostgreSQL
 * and SQLite; the table also has a unique index on LOWER(name) as a backstop.
 */
class UniqueJobContentName implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreId = null) {}

    /**
     * Trim and collapse inner whitespace so "  Blood   samples " is "Blood samples".
     */
    public static function normalise(mixed $name): mixed
    {
        if (! is_string($name)) {
            return $name;
        }

        $name = preg_replace('/\s+/u', ' ', trim($name));

        return $name === '' || $name === null ? null : $name;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $query = JobContentType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)]);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('There is already a contents entry with that name.');
        }
    }
}
