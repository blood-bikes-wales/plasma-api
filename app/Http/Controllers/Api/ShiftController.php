<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogoffShiftRequest;
use App\Http\Requests\LogonShiftRequest;
use App\Http\Resources\ActiveShiftResource;
use App\Models\OperationalShift;
use App\Models\User;
use App\Services\Shifts\OperationalShiftService;
use Illuminate\Http\JsonResponse;

class ShiftController extends Controller
{
    public function __construct(private readonly OperationalShiftService $shifts) {}

    public function active(): JsonResponse
    {
        return ActiveShiftResource::collection($this->shifts->listActive())
            ->response();
    }

    public function logon(LogonShiftRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $shift = $this->shifts->logon(
            actor: $user,
            riderId: (int) $request->validated('riderId'),
            bikeId: (string) $request->validated('bikeId'),
            startMileage: (int) $request->validated('startMileage'),
            mileageVarianceReason: $request->validated('mileageVarianceReason'),
        );

        return (new ActiveShiftResource($shift))
            ->response()
            ->setStatusCode(201);
    }

    public function logoff(LogoffShiftRequest $request, OperationalShift $shift): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $shift = $this->shifts->logoff(
            actor: $user,
            shift: $shift,
            endMileage: (int) $request->validated('endMileage'),
            faults: $request->validated('faults'),
        );

        return (new ActiveShiftResource($shift))->response();
    }
}
