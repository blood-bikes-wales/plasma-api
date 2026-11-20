<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Capability;
use App\Authorization\RequestRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobContentTypeRequest;
use App\Http\Requests\UpdateJobContentTypeRequest;
use App\Http\Resources\JobContentTypeResource;
use App\Models\JobContentType;
use App\Models\User;
use App\Services\JobContents\JobContentTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobContentTypeController extends Controller
{
    public function __construct(
        private readonly JobContentTypeService $contents,
    ) {}

    /**
     * Active entries for pickers; managers also get retired ones (with `active`).
     */
    public function index(Request $request): JsonResponse
    {
        $includeInactive = RequestRoles::hasCapability($request, Capability::ManageJobContents);

        return JobContentTypeResource::collection($this->contents->list($includeInactive))->response();
    }

    public function store(StoreJobContentTypeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entry = $this->contents->create($user, $request->validated());

        return (new JobContentTypeResource($entry))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateJobContentTypeRequest $request, JobContentType $jobContent): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entry = $this->contents->update($user, $jobContent, $request->validated());

        return (new JobContentTypeResource($entry))->response();
    }
}
