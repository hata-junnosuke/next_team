<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 応募・確定（F-09〜F-14、docs/01 6.5）と、応募できるワーカーの一覧（6.4 の applicable-workers）。
 * 担当 B（P2-04）が実装する。今は全アクションが 501。
 */
class ApplicationController extends Controller
{
    // GET /api/job-postings/{job_posting}/applications（ページなし）
    public function index(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/job-postings/{job_posting}/applications（worker_id）→ 201
    public function store(Request $request, JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // GET /api/job-postings/{job_posting}/applicable-workers（ページなし。有効で、まだ応募していないワーカー {id, name}）
    public function applicableWorkers(JobPosting $jobPosting): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/applications/{application}/confirm
    public function confirm(Application $application): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/applications/{application}/reject
    public function reject(Application $application): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/applications/{application}/cancel（reason: company / worker）
    public function cancel(Request $request, Application $application): JsonResponse
    {
        return $this->notImplemented();
    }
}
