<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobAction;
use App\Enums\JobScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\AllocateDeliveryJobRequest;
use App\Http\Requests\CancelDeliveryJobRequest;
use App\Http\Requests\CollectDeliveryJobRequest;
use App\Http\Requests\DeliverDeliveryJobRequest;
use App\Http\Requests\RelayDeliveryJobRequest;
use App\Http\Requests\StoreDeliveryJobRequest;
use App\Http\Resources\DeliveryJobResource;
use App\Models\DeliveryJob;
use App\Models\User;
use App\Services\Jobs\DeliveryJobService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function __construct(private readonly DeliveryJobService $jobs) {}

    public function index(string $scope): JsonResponse
    {
        $jobScope = JobScope::tryFrom($scope);
        if ($jobScope === null) {
            abort(404);
        }

        return response()->json([
            'data' => DeliveryJobResource::collection($this->jobs->listByScope($jobScope)),
        ]);
    }

    public function store(StoreDeliveryJobRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $job = $this->jobs->create($user, $request->validated());

        return (new DeliveryJobResource($job))
            ->response()
            ->setStatusCode(201);
    }

    public function relay(RelayDeliveryJobRequest $request, DeliveryJob $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->jobs->relay($user, $job, $request->validated());

        return (new DeliveryJobResource($updated))->response();
    }

    public function action(Request $request, DeliveryJob $job, string $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $jobAction = JobAction::tryFrom($action);
        if ($jobAction === null || $jobAction === JobAction::Cancel) {
            abort(404);
        }

        $validated = $this->validatedActionPayload($request, $jobAction);

        $updated = match ($jobAction) {
            JobAction::Allocate => $this->jobs->allocate($user, $job, $validated),
            JobAction::Collect => $this->jobs->collect($user, $job, $validated),
            JobAction::Deliver => $this->jobs->deliver($user, $job, $validated),
        };

        return (new DeliveryJobResource($updated))->response();
    }

    public function cancel(CancelDeliveryJobRequest $request, DeliveryJob $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->jobs->cancel($user, $job, $request->validated());

        return (new DeliveryJobResource($updated))->response();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedActionPayload(Request $request, JobAction $action): array
    {
        $formRequestClass = match ($action) {
            JobAction::Allocate => AllocateDeliveryJobRequest::class,
            JobAction::Collect => CollectDeliveryJobRequest::class,
            JobAction::Deliver => DeliverDeliveryJobRequest::class,
            JobAction::Cancel => throw new \LogicException('Cancel uses a dedicated endpoint.'),
        };

        /** @var FormRequest $formRequest */
        $formRequest = $formRequestClass::createFrom($request);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->setRouteResolver(fn () => $request->route());
        $formRequest->validateResolved();

        return $formRequest->validated();
    }
}
