<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryJobRequest;
use App\Http\Resources\DeliveryJobResource;
use App\Models\User;
use App\Services\Jobs\DeliveryJobService;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    public function __construct(private readonly DeliveryJobService $jobs) {}

    public function store(StoreDeliveryJobRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $job = $this->jobs->create($user, $request->validated());

        return (new DeliveryJobResource($job))
            ->response()
            ->setStatusCode(201);
    }
}
