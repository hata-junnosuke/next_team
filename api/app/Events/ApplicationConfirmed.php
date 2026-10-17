<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 応募が確定した（F-11）。リスナーが確定通知のジョブを積む（F-20）。
 *
 * - ShouldDispatchAfterCommit: 承認の DB::transaction がコミットされてから発火する。ロールバックされたら発火しない
 *   （Rails の after_commit に相当）。https://readouble.com/laravel/13.x/ja/events.html#dispatching-events-after-database-transactions
 * - モデルではなく ID だけを持つ（docs/01 の設計判断「ジョブに ID を渡す」）。通知のジョブは実行時に最新の応募を読み直すので、
 *   確定の直後にキャンセルされた応募に確定通知を送らずに済む
 */
class ApplicationConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $applicationId,
    ) {}
}
