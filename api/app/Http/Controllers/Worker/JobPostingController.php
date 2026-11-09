<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;

/**
 * ワーカーの募集の検索・詳細（F-30・F-31。docs/01 の 6.11）。issue #37 の T11 で実装する。
 * モデルを型宣言しているので、他社の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。
 */
class JobPostingController extends Controller
{
    /**
     * GET /api/worker/job-postings
     */
    public function index(): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * GET /api/worker/job-postings/{job_posting}
     */
    public function show(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }
}
