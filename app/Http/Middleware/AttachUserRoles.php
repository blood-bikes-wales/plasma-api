<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Services\Roles\UserRoleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach resolved Plasma roles to the request after Google authentication.
 *
 * Roles are never taken from the client. Future endpoints in the auth.google
 * group can read them via $request->attributes->get('roles').
 */
class AttachUserRoles
{
    public const string ATTRIBUTE = 'roles';

    public function __construct(private readonly UserRoleResolver $resolver) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /** @var list<Role> $roles */
        $roles = $user instanceof User
            ? $this->resolver->forUser($user)
            : [];

        $request->attributes->set(self::ATTRIBUTE, $roles);

        $roleValues = array_map(
            static fn (Role $role): string => $role->value,
            $roles,
        );

        Context::add('roles', $roleValues);
        Log::shareContext([
            'roles' => $roleValues,
        ]);

        if ($user instanceof User) {
            Log::info('Resolved Plasma roles for authenticated user.', [
                'user_id' => $user->id,
                'roles' => $roleValues,
            ]);
        }

        return $next($request);
    }
}
