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
            Capability::ViewActiveShifts,
            Capability::ViewDirectory,
            Capability::ViewJobs => [
                Role::Controller,
                Role::Driver,
                Role::Rider,
                Role::Trustee,
            ],
            Capability::ManageShifts,
            Capability::ViewVolunteers,
            Capability::CreateJob => [
                Role::Controller,
            ],
            Capability::ViewBikes => [
                Role::Controller,
                Role::Trustee,
            ],
            Capability::ManageBikes => [
                Role::Trustee,
            ],
        };
    }
}
