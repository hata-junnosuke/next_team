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
        // withCount は拠点の SELECT にサブクエリで件数を足し、各行に job_postings_count を付ける。
        // Rails なら locations.left_joins(:job_postings).select('locations.*, COUNT(job_postings.id)').group(...) を 1 本で書くのに当たる
        // https://readouble.com/laravel/13.x/ja/eloquent-relationships.html#counting-related-models
        return LocationResource::collection(
            Location::query()->withCount('jobPostings')->orderBy('id')->paginate($this->perPage($request))
        );
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated())->loadCount('jobPostings');

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->loadCount('jobPostings'));
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location->loadCount('jobPostings'));
    }

    public function destroy(Location $location, LocationService $service): Response
    {
        $service->delete($location);

        return response()->noContent();
    }
}
