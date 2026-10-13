<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceAreaResource;
use App\Services\PostcodeAreas\PostcodeAreaService;
use Illuminate\Http\JsonResponse;

class ServiceAreaController extends Controller
{
    public function __construct(
        private readonly PostcodeAreaService $postcodeAreas,
    ) {}

    public function index(): JsonResponse
    {
        $areas = $this->postcodeAreas->listDistinctActiveServiceAreas();

        return ServiceAreaResource::collection($areas)->response();
    }
}
