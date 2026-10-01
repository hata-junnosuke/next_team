<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * 拠点（F-01、docs/01 6.2）。Rails の resources :locations（new / edit を除く）に相当。
 * 他社の ID は Location のグローバルスコープでルートモデルバインディングが見つけられず 404 になる。
 * https://readouble.com/laravel/13.x/ja/controllers.html#api-resource-routes
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LocationResource::collection(Location::query()->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated());

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location);
    }

    public function destroy(Location $location, LocationService $service): Response
    {
        $service->delete($location);

        return response()->noContent();
    }
}
