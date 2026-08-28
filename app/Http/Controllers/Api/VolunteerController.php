<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerResource;
use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsException;
use App\Services\ThreeRings\ThreeRingsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class VolunteerController extends Controller
{
    public function __construct(private readonly ThreeRingsClient $threeRings) {}

    public function index(): JsonResponse
    {
        return VolunteerResource::collection($this->listVolunteers())->response();
    }

    /**
     * @return Collection<int, Volunteer>
     */
    private function listVolunteers(): Collection
    {
        try {
            return $this->threeRings->volunteers()->sortBy('name')->values();
        } catch (Throwable $exception) {
            $this->throwDirectoryUnavailable($exception);
        }
    }

    private function throwDirectoryUnavailable(Throwable $exception): never
    {
        if ($exception instanceof ThreeRingsException) {
            Log::warning('Three Rings directory unavailable while listing volunteers.', [
                'exception' => $exception,
            ]);

            throw new HttpException(503, 'Volunteer directory is unavailable.');
        }

        Log::error('Unexpected failure listing volunteers.', [
            'exception' => $exception,
        ]);

        throw new HttpException(503, 'Volunteer directory is unavailable.');
    }
}
