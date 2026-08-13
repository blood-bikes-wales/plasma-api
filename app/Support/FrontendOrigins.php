<?php

namespace App\Support;

final class FrontendOrigins
{
    public const DEFAULT = 'http://localhost:5173';

    /**
     * Parse FRONTEND_URL into a list of browser origins.
     *
     * Null/unset uses the local Vite default. An explicit empty string is
     * fail-closed (no origins). Trailing slashes are stripped so values match
     * the Origin header the browser sends.
     *
     * @return list<string>
     */
    public static function parse(?string $frontendUrl, string $default = self::DEFAULT): array
    {
        if ($frontendUrl === '') {
            return [];
        }

        $value = $frontendUrl ?? $default;

        return array_values(array_filter(
            array_map(
                static fn (string $origin): string => rtrim(trim($origin), '/'),
                explode(',', $value),
            ),
            static fn (string $origin): bool => $origin !== '',
        ));
    }
}
