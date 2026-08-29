<?php

namespace App\Http\Middleware;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Authorization\RequestRoles;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a capability from the server-side role matrix.
 *
 * Client headers such as X-Active-Role are ignored.
 */
class EnsureHasCapability
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $required = Capability::tryFrom($capability);

        if ($required === null) {
            throw new InvalidArgumentException("Unknown capability [{$capability}].");
        }

        if (! $this->allows($request, $required)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }

    private function allows(Request $request, Capability $capability): bool
    {
        $allowed = collect(CapabilityMatrix::roles($capability))
            ->map(static fn ($role): string => $role->value);

        return collect(RequestRoles::expanded($request))
            ->map(static fn ($role): string => $role->value)
            ->intersect($allowed)
            ->isNotEmpty();
    }
}
