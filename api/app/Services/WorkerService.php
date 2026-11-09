<?php

namespace App\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * ワーカーの登録と編集（F-02。docs/01 の 4.5）。登録すると招待を発行する（F-26）。
 *
 * - 登録と招待の発行は同じトランザクション。どちらかが失敗したら両方なかったことにする
 * - 招待メールのジョブは WorkerInvited（ShouldDispatchAfterCommit）がコミットの後に積む。応答はメールの送信を待たない
 * https://readouble.com/laravel/13.x/ja/database.html#database-transactions
 */
class WorkerService
{
    public function __construct(private readonly WorkerInvitationService $invitations) {}

    /**
     * 登録時は常に有効（Worker の $attributes の既定値）。company_id は BelongsToCompany が CurrentCompany::id() で埋める
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function create(array $data): Worker
    {
        return DB::transaction(function () use ($data) {
            $worker = Worker::create($data);

            $this->invitations->issue($worker);

            return $worker;
        });
    }
}
