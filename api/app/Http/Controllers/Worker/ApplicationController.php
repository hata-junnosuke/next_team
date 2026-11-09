<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Worker\Concerns\ResolvesWorker;
use App\Http\Resources\Worker\WorkerApplicationResource;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ワーカー本人の応募と、自分の応募の一覧（F-32・F-33。docs/01 の 6.11）。
 * 他社・存在しない募集の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。応募の可否は ApplicationService::applyAsWorker() の 422。
 */
class ApplicationController extends Controller
{
    use ResolvesWorker;

    /**
     * GET /api/worker/applications（issue #37 の T13 で実装する）
     */
    public function index(): JsonResponse
    {
        return $this->notImplemented();
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
