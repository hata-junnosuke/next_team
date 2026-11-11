<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Worker\Concerns\ResolvesWorker;
use App\Http\Requests\Worker\IndexJobPostingRequest;
use App\Http\Resources\Worker\WorkerJobPostingResource;
use App\Models\JobPosting;
use App\Services\WorkerPortalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ワーカーの募集の検索・詳細（F-30・F-31。docs/01 の 6.11）。
 * 他社の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。締切・中止・過去の募集の可否は WorkerPortalService::findPosting() が見る。
 */
class JobPostingController extends Controller
{
    use ResolvesWorker;

    public function __construct(private readonly WorkerPortalService $portal) {}

    /**
     * GET /api/worker/job-postings（date_from・date_to・location_id・page・per_page）
     */
    public function index(IndexJobPostingRequest $request): AnonymousResourceCollection
    {
        return WorkerJobPostingResource::collection(
            $this->portal->searchPostings($this->worker($request), $request->validated(), $this->perPage($request))
        );
    }

    /**
     * GET /api/worker/job-postings/{job_posting}
     */
    public function show(Request $request, JobPosting $jobPosting): WorkerJobPostingResource
    {
        return new WorkerJobPostingResource($this->portal->findPosting($this->worker($request), $jobPosting));
    }
}
