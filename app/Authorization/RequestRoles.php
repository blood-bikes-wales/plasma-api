<?php

namespace App\Authorization;

use App\Enums\Role;
use App\Http\Middleware\AttachUserRoles;
use Illuminate\Http\Request;

final class RequestRoles
{
    /**
     * Roles attached after Google auth, with admin hierarchy expanded.
     *
     * Never read from client headers.
     *
     * @return list<Role>
     */
    public static function expanded(Request $request): array
    {
        return Role::expand(self::assigned($request));
    }

    /**
     * Whether any of the caller's expanded roles holds the capability.
     *
     * Reads roles already attached to the request, so it never queries.
     */
    public static function hasCapability(Request $request, Capability $capability): bool
    {
        $allowed = array_map(
            static fn (Role $role): string => $role->value,
            CapabilityMatrix::roles($capability),
        );

        foreach (self::expanded($request) as $role) {
            if (in_array($role->value, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Role>
     */
    public static function assigned(Request $request): array
    {
        $assigned = $request->attributes->get(AttachUserRoles::ATTRIBUTE, []);

        if (! is_array($assigned)) {
            return [];
        }

        return collect($assigned)
            ->whereInstanceOf(Role::class)
            ->values()
            ->all();
    }
}
