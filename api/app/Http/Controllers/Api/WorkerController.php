<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ワーカー（F-02〜F-04、docs/01 6.3）。担当 A（P2-02）が実装する。今は全アクションが 501。
 * 削除はしない（無効化する。要件 7 章）ので destroy はない。
 * モデルを型宣言しているので、他社の ID はルートモデルバインディングで 404 になる。
 */
class WorkerController extends Controller
{
    // GET /api/workers（q・is_active・page・per_page）
    public function index(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    // POST /api/workers
    public function store(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    // GET /api/workers/{worker}
    public function show(Worker $worker): JsonResponse
    {
        return $this->notImplemented();
    }

    // PUT /api/workers/{worker}
    public function update(Request $request, Worker $worker): JsonResponse
    {
        return $this->notImplemented();
    }

    // PATCH /api/workers/{worker}/active（is_active）
    public function toggleActive(Request $request, Worker $worker): JsonResponse
    {
        return $this->notImplemented();
    }
}
