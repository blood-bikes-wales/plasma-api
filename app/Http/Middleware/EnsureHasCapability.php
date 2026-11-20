<?php

namespace App\Http\Middleware;

use App\Authorization\Capability;
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

        if (! RequestRoles::hasCapability($request, $required)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
