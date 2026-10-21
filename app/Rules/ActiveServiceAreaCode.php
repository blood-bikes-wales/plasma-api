<?php

namespace App\Rules;

use App\Models\OutboundPostcodeArea;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ActiveServiceAreaCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid service area code.');

            return;
        }

        $exists = OutboundPostcodeArea::query()
            ->active()
            ->whereHas('serviceArea', static function ($query) use ($value): void {
                $query->where('code', strtoupper($value));
            })
            ->exists();

        if (! $exists) {
            $fail('The :attribute must be an active service area code.');
        }
    }
}
