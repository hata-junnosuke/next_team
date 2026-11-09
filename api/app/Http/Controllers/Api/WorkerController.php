<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexWorkerRequest;
use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\ToggleWorkerActiveRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use App\Services\WorkerInvitationService;
use App\Services\WorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ワーカー（F-02〜F-04・F-26・F-27、docs/01 の API 契約（ワーカー））。
 * 登録は招待の発行を伴うので WorkerService、招待の送り直しは WorkerInvitationService に任せる（docs/01 の 4.5）。
 * 編集（T8 で WorkerService へ移す）と有効/無効の切り替えは、今は Eloquent を直接呼ぶ。
 * 削除はしない（無効化する。要件の非機能要件）ので destroy はない。
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
            ->with('usableInvitation') // WorkerResource の invitation_expires_at。1 回のクエリで先読みする（N+1 にしない）
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return WorkerResource::collection($workers);
    }

    /**
     * POST /api/workers。登録と同じトランザクションで招待を発行し、コミットの後に招待メールを積む（F-26。応答は送信を待たない）
     */
    public function store(StoreWorkerRequest $request, WorkerService $service): JsonResponse
    {
        $worker = $service->create($request->validated())->load('usableInvitation');

        return (new WorkerResource($worker))->response()->setStatusCode(201);
    }

    /**
     * GET /api/workers/{worker}
     */
    public function show(Worker $worker): WorkerResource
    {
        return new WorkerResource($worker->loadMissing('usableInvitation'));
    }

    /**
     * PUT /api/workers/{worker}
     */
    public function update(UpdateWorkerRequest $request, Worker $worker): WorkerResource
    {
        $worker->update($request->validated());

        return new WorkerResource($worker->loadMissing('usableInvitation'));
    }

    /**
     * PATCH /api/workers/{worker}/active（is_active）。無効にしても既存の応募・確定は変えない（要件 F-03）
     */
    public function toggleActive(ToggleWorkerActiveRequest $request, Worker $worker): WorkerResource
    {
        $worker->update(['is_active' => $request->boolean('is_active')]);

        return new WorkerResource($worker->loadMissing('usableInvitation'));
    }

    /**
     * POST /api/workers/{worker}/invitation。招待の送り直し（F-27）。200 で WorkerResource を返す（前の招待は使えなくなる）。
     * 無効・登録済みはサービスの DomainException が 422 になる。他社の ID はルートモデルバインディングで 404
     */
    public function invite(Worker $worker, WorkerInvitationService $service): WorkerResource
    {
        $service->issue($worker);

        // サービスはロックして読み直した行で判定する。応答もその後の最新の値と新しい招待で作る
        return new WorkerResource($worker->refresh()->load('usableInvitation'));
    }
}
