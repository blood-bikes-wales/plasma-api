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
