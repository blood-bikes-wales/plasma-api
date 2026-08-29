<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BikeDetailResource;
use App\Http\Resources\BikeResource;
use App\Http\Resources\DirectoryVolunteerResource;
use App\Models\Bike;
use App\Services\Bikes\BikeLogService;
use App\Services\Directory\VolunteerDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    public function __construct(
        private readonly VolunteerDirectoryService $volunteers,
        private readonly BikeLogService $bikes,
    ) {}

    public function volunteers(Request $request): JsonResponse
    {
        $results = $this->volunteers->search(
            query: $request->query('q'),
            role: $request->query('role'),
            area: $request->query('area'),
        );

        return DirectoryVolunteerResource::collection($results)->response();
    }

    public function bikes(Request $request): JsonResponse
    {
        $results = $this->bikes->search($request->query('q'));

        return BikeResource::collection($results)->response();
    }

    public function showBike(Bike $bike): JsonResponse
    {
        $detail = $this->bikes->findWithHistory($bike->id);

        return (new BikeDetailResource($detail))->response();
    }
}
