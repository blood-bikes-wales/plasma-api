<?php

namespace App\Authorization;

use App\Enums\Role;

/**
 * Section 11 capability matrix for the current HTTP surface.
 *
 * Admin is not listed here; {@see Role::expand()} grants admin every role.
 */
final class CapabilityMatrix
{
    /**
     * @return list<Role>
     */
    public static function roles(Capability $capability): array
    {
        return match ($capability) {
            Capability::ViewActiveShifts => [
                Role::Controller,
                Role::Driver,
                Role::Rider,
                Role::Trustee,
            ],
            Capability::ManageShifts,
            Capability::ViewBikes,
            Capability::ViewVolunteers => [
                Role::Controller,
            ],
        };
    }
}
