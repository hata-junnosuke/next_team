<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ワーカーの拠点の一覧（F-30 の絞り込みの選択肢。docs/01 の 6.11）。issue #37 の T11 で実装する。
 */
class LocationController extends Controller
{
    /**
     * GET /api/worker/locations
     */
    public function index(): JsonResponse
    {
        return $this->notImplemented();
    }
}
