<?php

namespace App\Http\Middleware;

use App\Authorization\RequestRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the authenticated user to hold at least one of the given Plasma roles.
 *
 * Roles are resolved by {@see AttachUserRoles}, expanded for admin hierarchy,
 * and never taken from the client.
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
        $assigned = collect(RequestRoles::expanded($request))
            ->map(static fn ($role): string => $role->value);

        if ($required === []) {
            return $assigned->isNotEmpty();
        }

        return $assigned->intersect($required)->isNotEmpty();
    }
}
