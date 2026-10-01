<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexWorkerRequest;
use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\ToggleWorkerActiveRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ワーカー（F-02〜F-04、docs/01 6.3）。業務ルールのない CRUD なのでサービスを作らず Eloquent を直接呼ぶ。
 * 削除はしない（無効化する。要件 7 章）ので destroy はない。
 * モデルを型宣言しているので、他社の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。
 * https://readouble.com/laravel/13.x/ja/controllers.html#api-resource-routes
 */
class WorkerController extends Controller
{
    /**
     * GET /api/workers（q・is_active・page・per_page）
     */
    public function index(IndexWorkerRequest $request): AnonymousResourceCollection
    {
        $keyword = $request->keyword();
        $isActive = $request->isActive();

        $workers = Worker::query()
            ->when($keyword !== null, fn ($query) => $query->nameLike($keyword))
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return WorkerResource::collection($workers);
    }

    /**
     * POST /api/workers。登録時は常に有効（$attributes の既定値）
     */
    public function store(StoreWorkerRequest $request): JsonResponse
    {
        $worker = Worker::create($request->validated());

        return (new WorkerResource($worker))->response()->setStatusCode(201);
    }

    /**
     * GET /api/workers/{worker}
     */
    public function show(Worker $worker): WorkerResource
    {
        return new WorkerResource($worker);
    }

    /**
     * PUT /api/workers/{worker}
     */
    public function update(UpdateWorkerRequest $request, Worker $worker): WorkerResource
    {
        $worker->update($request->validated());

        return new WorkerResource($worker);
    }

    /**
     * PATCH /api/workers/{worker}/active（is_active）。無効にしても既存の応募・確定は変えない（要件 F-03）
     */
    public function toggleActive(ToggleWorkerActiveRequest $request, Worker $worker): WorkerResource
    {
        $worker->update(['is_active' => $request->boolean('is_active')]);

        return new WorkerResource($worker);
    }
}
