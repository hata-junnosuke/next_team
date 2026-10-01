<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelApplicationRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\WorkerSummaryResource;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Worker;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 応募・確定（F-09〜F-14、docs/01 6.5）と、応募できるワーカーの一覧（6.4 の applicable-workers）。
 * Rails の resources :applications（job_postings の下にネスト）+ member ルート（confirm / reject / cancel）に相当。
 * 他社の ID はグローバルスコープでルートモデルバインディングが見つけられず 404 になる。
 */
class ApplicationController extends Controller
{
    /**
     * ApplicationResource が使う関連。一覧でも with で 1 回ずつにまとめる（N+1 にしない）
     *
     * @var array<int, string>
     */
    private const RELATIONS = ['worker', 'attendance'];

    // GET /api/job-postings/{job_posting}/applications（ページなし。応募の古い順）
    public function index(JobPosting $jobPosting): AnonymousResourceCollection
    {
        return ApplicationResource::collection(
            $jobPosting->applications()->with(self::RELATIONS)->orderBy('id')->get(),
        );
    }

    // POST /api/job-postings/{job_posting}/applications（worker_id）→ 201
    public function store(StoreApplicationRequest $request, JobPosting $jobPosting, ApplicationService $service): JsonResponse
    {
        // 自社のワーカーであることは FormRequest の exists で確かめ済み（findOrFail はグローバルスコープも通る）
        $worker = Worker::query()->findOrFail($request->integer('worker_id'));

        $application = $service->apply($jobPosting, $worker);

        return (new ApplicationResource($application->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    // GET /api/job-postings/{job_posting}/applicable-workers（ページなし。有効で、まだ応募していないワーカー {id, name}）
    public function applicableWorkers(JobPosting $jobPosting, ApplicationService $service): AnonymousResourceCollection
    {
        return WorkerSummaryResource::collection($service->applicableWorkers($jobPosting));
    }

    // POST /api/applications/{application}/confirm
    public function confirm(Application $application, ApplicationService $service): ApplicationResource
    {
        return new ApplicationResource($service->confirm($application)->load(self::RELATIONS));
    }

    // POST /api/applications/{application}/reject
    public function reject(Application $application, ApplicationService $service): ApplicationResource
    {
        return new ApplicationResource($service->reject($application)->load(self::RELATIONS));
    }

    // POST /api/applications/{application}/cancel（reason: company / worker）
    public function cancel(CancelApplicationRequest $request, Application $application, ApplicationService $service): ApplicationResource
    {
        return new ApplicationResource($service->cancel($application, $request->reason())->load(self::RELATIONS));
    }
}
