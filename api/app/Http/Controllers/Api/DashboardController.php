<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ダッシュボード（F-19、docs/01 6.8）。担当 C（P2-06）が実装する。今は 501。
 */
class DashboardController extends Controller
{
    // GET /api/dashboard
    public function show(): JsonResponse
    {
        return $this->notImplemented();
    }
}
