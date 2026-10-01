<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 募集（F-05〜F-08、docs/01 6.4）。担当 B（P2-03）が実装する。今は全アクションが 501。
 * 削除はしない（中止する。要件 7 章）ので destroy はない。
 */
class JobPostingController extends Controller
{
    // GET /api/job-postings（date_from・date_to・location_id・status・page・per_page。勤務日の昇順）
    public function index(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/job-postings
    public function store(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    // GET /api/job-postings/{job_posting}
    public function show(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // PUT /api/job-postings/{job_posting}
    public function update(Request $request, JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/job-postings/{job_posting}/close
    public function close(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/job-postings/{job_posting}/cancel
    public function cancel(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/job-postings/{job_posting}/duplicate（work_date）→ 201
    public function duplicate(Request $request, JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }
}
