<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 募集が中止された（F-07）。リスナーが、確定済みだった応募ごとに中止通知のジョブを積む（F-21）。
 *
 * - ShouldDispatchAfterCommit: DB::transaction がコミットされてから発火する。ロールバックされたら発火しない
 *   （Rails の after_commit に相当）。https://readouble.com/laravel/13.x/ja/events.html#dispatching-events-after-database-transactions
 * - モデルではなく ID を持つ。中止の後に応募を読むと全部キャンセル済みで、どれが今回の中止でキャンセルになったか
 *   区別できないので、「この中止で 確定 → 企業都合キャンセル になった応募の ID」を配列で持つ（却下になった応募中は含めない）
 */
class JobPostingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $cancelledApplicationIds  ID の昇順
     */
    public function __construct(
        public readonly int $jobPostingId,
        public readonly array $cancelledApplicationIds,
    ) {}
}
