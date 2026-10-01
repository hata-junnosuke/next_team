<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicateJobPostingRequest;
use App\Http\Requests\IndexJobPostingRequest;
use App\Http\Requests\StoreJobPostingRequest;
use App\Http\Requests\UpdateJobPostingRequest;
use App\Http\Resources\JobPostingResource;
use App\Models\JobPosting;
use App\Services\JobPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 募集（F-05〜F-08、docs/01 6.4）。Rails の resources :job_postings + member ルート（close / cancel / duplicate）に相当。
 * 削除はしない（中止する。要件 7 章）ので destroy はない。
 * 他社の ID は JobPosting のグローバルスコープでルートモデルバインディングが見つけられず 404 になる。
 */
class JobPostingController extends Controller
{
    /**
     * Resource が使う拠点と件数（応募数・確定数）。一覧では with / withCount で 1 回ずつにまとめる（N+1 にしない）
     *
     * @var array<int, string>
     */
    private const COUNTS = ['applications', 'confirmedApplications'];

    // GET /api/job-postings（date_from・date_to・location_id・status・page・per_page。勤務日の昇順）
    public function index(IndexJobPostingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // work_date は "Y-m-d" の文字列で保存している（JobPosting::workDate()）ので、文字列の比較で日付の範囲になる
        $postings = JobPosting::query()
            ->with('location')
            ->withCount(self::COUNTS)
            ->when($filters['date_from'] ?? null, fn ($q, string $from) => $q->where('work_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, string $to) => $q->where('work_date', '<=', $to))
            ->when($filters['location_id'] ?? null, fn ($q, $locationId) => $q->where('location_id', (int) $locationId))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return JobPostingResource::collection($postings);
    }

    // POST /api/job-postings
    public function store(StoreJobPostingRequest $request): JsonResponse
    {
        $posting = JobPosting::create($request->validated());

        return (new JobPostingResource($this->withSummary($posting)))->response()->setStatusCode(201);
    }

    // GET /api/job-postings/{job_posting}
    public function show(JobPosting $jobPosting): JobPostingResource
    {
        return new JobPostingResource($this->withSummary($jobPosting));
    }

    // PUT /api/job-postings/{job_posting}
    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting, JobPostingService $service): JobPostingResource
    {
        return new JobPostingResource($this->withSummary($service->update($jobPosting, $request->validated())));
    }

    // POST /api/job-postings/{job_posting}/close
    public function close(JobPosting $jobPosting, JobPostingService $service): JobPostingResource
    {
        return new JobPostingResource($this->withSummary($service->close($jobPosting)));
    }

    // POST /api/job-postings/{job_posting}/cancel
    public function cancel(JobPosting $jobPosting, JobPostingService $service): JobPostingResource
    {
        return new JobPostingResource($this->withSummary($service->cancel($jobPosting)));
    }

    // POST /api/job-postings/{job_posting}/duplicate（work_date）→ 201
    public function duplicate(DuplicateJobPostingRequest $request, JobPosting $jobPosting, JobPostingService $service): JsonResponse
    {
        $copy = $service->duplicate($jobPosting, CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('work_date')));

        return (new JobPostingResource($this->withSummary($copy)))->response()->setStatusCode(201);
    }

    /**
     * 単体の応答用に拠点と件数を読み込む（更新・中止の後の最新の件数にするため、毎回 loadCount し直す）
     */
    private function withSummary(JobPosting $posting): JobPosting
    {
        return $posting->load('location')->loadCount(self::COUNTS);
    }
}
