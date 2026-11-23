<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobRecurrenceRequest;
use App\Http\Requests\UpdateJobRecurrenceRequest;
use App\Http\Resources\JobRecurrenceResource;
use App\Models\JobRecurrence;
use App\Models\User;
use App\Services\Jobs\JobRecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobRecurrenceController extends Controller
{
    public function __construct(private readonly JobRecurrenceService $recurrences) {}

    public function index(Request $request): JsonResponse
    {
        $active = $request->query('active');
        $activeOnly = null;
        if ($active === '1' || $active === 'true') {
            $activeOnly = true;
        }
        if ($active === '0' || $active === 'false') {
            $activeOnly = false;
        }

        return response()->json([
            'data' => JobRecurrenceResource::collection(
                $this->recurrences->list($activeOnly),
            ),
        ]);
    }

    public function show(JobRecurrence $jobRecurrence): JsonResponse
    {
        return (new JobRecurrenceResource($this->recurrences->find($jobRecurrence)))->response();
    }

    public function store(StoreJobRecurrenceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $created = $this->recurrences->create($user, $request->validated());

        return (new JobRecurrenceResource($created))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateJobRecurrenceRequest $request, JobRecurrence $jobRecurrence): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->recurrences->update($user, $jobRecurrence, $request->validated());

        return (new JobRecurrenceResource($updated))->response();
    }

    public function pause(Request $request, JobRecurrence $jobRecurrence): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new JobRecurrenceResource(
            $this->recurrences->pause($user, $jobRecurrence),
        ))->response();
    }

    public function resume(Request $request, JobRecurrence $jobRecurrence): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new JobRecurrenceResource(
            $this->recurrences->resume($user, $jobRecurrence),
        ))->response();
    }

    public function destroy(Request $request, JobRecurrence $jobRecurrence): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new JobRecurrenceResource(
            $this->recurrences->stop($user, $jobRecurrence),
        ))->response();
    }
}
