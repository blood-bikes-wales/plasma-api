<?php

namespace App\Support;

final class UkOutwardPostcode
{
    private const OUTWARD_PATTERN = '/^[A-Z]{1,2}[0-9][0-9A-Z]?$/';

    public static function normalise(string $value): ?string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
        if ($compact === '') {
            return null;
        }

        if (preg_match(self::OUTWARD_PATTERN, $compact) === 1) {
            return $compact;
        }

        // Full UK postcode: outward + 3-char inward (digit + 2 letters).
        if (strlen($compact) >= 5) {
            $outward = substr($compact, 0, -3);
            if (preg_match(self::OUTWARD_PATTERN, $outward) === 1) {
                return $outward;
            }
        }

        return null;
    }

    public static function isValid(string $value): bool
    {
        return self::normalise($value) !== null;
    }
}
