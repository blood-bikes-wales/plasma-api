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
use App\Http\Requests\ScheduleDeliveryJobRequest;
use App\Http\Requests\StoreDeliveryJobRequest;
use App\Http\Resources\DeliveryJobResource;
use App\Models\User;
use App\Services\Jobs\DeliveryJobService;
use App\Services\Users\UserSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function __construct(
        private readonly DeliveryJobService $jobs,
        private readonly UserSettingsService $settings,
    ) {}

    public function index(Request $request, string $scope): JsonResponse
    {
        $jobScope = JobScope::tryFrom($scope);
        if ($jobScope === null) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        $includeOlderCompleted = $jobScope !== JobScope::Completed
            || $this->settings->showCompletedJobs($user);

        return response()->json([
            'data' => DeliveryJobResource::collection(
                $this->jobs->listByScope($jobScope, $includeOlderCompleted),
            ),
        ]);
    }

    public function show(string $job): JsonResponse
    {
        return (new DeliveryJobResource($this->jobs->resolve($job)))->response();
    }

    public function store(StoreDeliveryJobRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $created = $this->jobs->create($user, $request->validated());

        return (new DeliveryJobResource($created))
            ->response()
            ->setStatusCode(201);
    }

    public function schedule(ScheduleDeliveryJobRequest $request, string $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resolved = $this->jobs->resolve($job);
        $updated = $this->jobs->schedule($user, $resolved, $request->validated());

        return (new DeliveryJobResource($updated))->response();
    }

    public function relay(RelayDeliveryJobRequest $request, string $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resolved = $this->jobs->resolve($job);
        $updated = $this->jobs->relay($user, $resolved, $request->validated());

        return (new DeliveryJobResource($updated))->response();
    }

    public function action(Request $request, string $job, string $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $jobAction = JobAction::tryFrom($action);
        if ($jobAction === null || $jobAction === JobAction::Cancel) {
            abort(404);
        }

        $validated = $this->validatedActionPayload($request, $jobAction);
        $resolved = $this->jobs->resolve($job);

        $updated = match ($jobAction) {
            JobAction::Allocate => $this->jobs->allocate($user, $resolved, $validated),
            JobAction::Collect => $this->jobs->collect($user, $resolved, $validated),
            JobAction::Deliver => $this->jobs->deliver($user, $resolved, $validated),
        };

        return (new DeliveryJobResource($updated))->response();
    }

    public function cancel(CancelDeliveryJobRequest $request, string $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resolved = $this->jobs->resolve($job);
        $updated = $this->jobs->cancel($user, $resolved, $request->validated());

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
