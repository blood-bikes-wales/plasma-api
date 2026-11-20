<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AttachUserRoles;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Services\Users\UserSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MeController extends Controller
{
    public function __construct(private readonly UserSettingsService $settings) {}

    public function show(Request $request): JsonResponse
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

    /**
     * Change the caller's own settings. No capability is needed beyond being signed in
     * (this sits beside GET /me, outside the access:* groups), and the target is always
     * $request->user(), so nobody can edit someone else's settings.
     */
    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->settings->updateShowCompletedJobs($user, $request->boolean('showCompletedJobs'));

        Log::info('Updated user settings.', [
            'user_id' => $user->id,
            'show_completed_jobs' => $request->boolean('showCompletedJobs'),
        ]);

        return (new MeResource($user))
            ->response()
            ->setStatusCode(200);
    }
}
