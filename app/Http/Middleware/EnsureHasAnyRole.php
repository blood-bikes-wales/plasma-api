<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the authenticated user to hold at least one of the given Plasma roles.
 *
 * Roles are resolved by {@see AttachUserRoles} and never taken from the client.
 */
class EnsureHasAnyRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $this->hasAnyRequiredRole($request, $roles)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $required
     */
    private function hasAnyRequiredRole(Request $request, array $required): bool
    {
        return collect($this->assignedRoleValues($request))
            ->intersect($required)
            ->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    private function assignedRoleValues(Request $request): array
    {
        $assigned = $request->attributes->get(AttachUserRoles::ATTRIBUTE, []);

        if (! is_array($assigned)) {
            return [];
        }

        return collect($assigned)
            ->whereInstanceOf(Role::class)
            ->map(static fn (Role $role): string => $role->value)
            ->values()
            ->all();
    }
}
