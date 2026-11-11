<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Worker\Concerns\ResolvesWorker;
use App\Http\Requests\Worker\IndexApplicationRequest;
use App\Http\Resources\Worker\WorkerApplicationResource;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use App\Services\WorkerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ワーカー本人の応募と、自分の応募の一覧（F-32・F-33。docs/01 の 6.11）。
 * 他社・存在しない募集の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。応募の可否は ApplicationService::applyAsWorker() の 422。
 */
class ApplicationController extends Controller
{
    use ResolvesWorker;

    /**
     * GET /api/worker/applications（status・page・per_page）
     */
    public function index(IndexApplicationRequest $request, WorkerPortalService $portal): AnonymousResourceCollection
    {
        return WorkerApplicationResource::collection(
            $portal->applications($this->worker($request), $request->status(), $this->perPage($request))
        );
    }

    /**
     * POST /api/worker/job-postings/{job_posting}/applications（入力なし）→ 201
     */
    public function store(Request $request, JobPosting $jobPosting, ApplicationService $service): JsonResponse
    {
        $application = $service->applyAsWorker($jobPosting, $this->worker($request));

        return (new WorkerApplicationResource($application->load('jobPosting.location')))
            ->response()
            ->setStatusCode(201);
    }
}
