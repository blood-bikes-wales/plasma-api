<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BikeResource;
use App\Models\Bike;
use Illuminate\Http\JsonResponse;

class BikeController extends Controller
{
    public function index(): JsonResponse
    {
        $bikes = Bike::query()->orderBy('registration')->get();

        return BikeResource::collection($bikes)->response();
    }
}
