<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AttachUserRoles;
use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        /** @var list<Role>|null $roles */
        $roles = $request->attributes->get(AttachUserRoles::ATTRIBUTE);

        Log::info('Served authenticated user profile.', [
            'user_id' => $user?->id,
            'roles' => array_map(
                static fn (Role $role): string => $role->value,
                is_array($roles) ? $roles : [],
            ),
        ]);

        // Always 200: JsonResource would otherwise emit 201 when the user
        // was provisioned on this request (wasRecentlyCreated).
        return (new MeResource($user))
            ->response()
            ->setStatusCode(200);
    }
}
