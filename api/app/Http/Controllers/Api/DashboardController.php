<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * ダッシュボード（F-19、docs/01 6.8）。形は DashboardService::summary() が 6.8 のとおりに作る。
 */
class DashboardController extends Controller
{
    // GET /api/dashboard
    public function show(DashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary()]);
    }
}
