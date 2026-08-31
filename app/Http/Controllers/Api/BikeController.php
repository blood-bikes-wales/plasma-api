<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Authorization\RequestRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBikeRequest;
use App\Http\Requests\UpdateBikeRequest;
use App\Http\Resources\BikeResource;
use App\Models\Bike;
use App\Models\User;
use App\Services\Bikes\BikeFleetService;
use App\Services\Bikes\BikeLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BikeController extends Controller
{
    public function __construct(
        private readonly BikeLogService $bikes,
        private readonly BikeFleetService $fleet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $area = $request->query('area');

        if ($this->hasManagedFilters($status, $area)) {
            if (! $this->canManageBikes($request)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $bikes = $this->bikes->listManaged(
                is_string($status) ? $status : null,
                is_string($area) ? $area : null,
            );

            return BikeResource::collection($bikes)->response();
        }

        $bikes = $this->bikes->listActive();

        return BikeResource::collection($bikes)->response();
    }

    public function store(StoreBikeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $bike = $this->fleet->create($user, $request->validated());

        return (new BikeResource($bike))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateBikeRequest $request, Bike $bike): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $bike = $this->fleet->update($user, $bike, $request->validated());

        return (new BikeResource($bike))->response();
    }

    public function retire(Request $request, Bike $bike): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $bike = $this->fleet->retire($user, $bike);

        return (new BikeResource($bike))->response();
    }

    private function hasManagedFilters(mixed $status, mixed $area): bool
    {
        if (is_string($status) && trim($status) !== '') {
            return true;
        }

        if (is_string($area) && trim($area) !== '') {
            return true;
        }

        return false;
    }

    private function canManageBikes(Request $request): bool
    {
        $allowed = collect(CapabilityMatrix::roles(Capability::ManageBikes))
            ->map(static fn ($role): string => $role->value);

        return collect(RequestRoles::expanded($request))
            ->map(static fn ($role): string => $role->value)
            ->intersect($allowed)
            ->isNotEmpty();
    }
}
