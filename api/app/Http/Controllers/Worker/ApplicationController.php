<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;

/**
 * ワーカー本人の応募と、自分の応募の一覧（F-32・F-33。docs/01 の 6.11）。issue #37 の T12・T13 で実装する。
 */
class ApplicationController extends Controller
{
    /**
     * GET /api/worker/applications
     */
    public function index(): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * POST /api/worker/job-postings/{job_posting}/applications
     */
    public function store(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }
}
