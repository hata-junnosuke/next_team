<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\Worker\WorkerLocationResource;
use App\Models\Location;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ワーカーの拠点の一覧（F-30 の絞り込みの選択肢。docs/01 の 6.11）。ページなしで自社の拠点すべて。
 * 他社の拠点は Location のグローバルスコープで落ちる（worker ミドルウェアがワーカーの企業を CurrentCompany に入れている）。
 */
class LocationController extends Controller
{
    /**
     * GET /api/worker/locations
     */
    public function index(): AnonymousResourceCollection
    {
        return WorkerLocationResource::collection(Location::query()->orderBy('id')->get(['id', 'name']));
    }
}
