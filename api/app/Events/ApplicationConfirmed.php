<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 応募が確定した（F-11）。P4 のリスナーが確定通知のジョブを積む（F-20、docs/03）。
 *
 * - ShouldDispatchAfterCommit: 承認の DB::transaction がコミットされてから発火する。ロールバックされたら発火しない
 *   （Rails の after_commit に相当）。https://readouble.com/laravel/13.x/ja/events.html#dispatching-events-after-database-transactions
 * - モデルではなく ID だけを持つ（docs/01 12 章「ジョブに ID を渡す」）。通知のジョブは実行時に最新の応募を読み直すので、
 *   確定の直後にキャンセルされた応募に確定通知を送らずに済む
 */
class ApplicationConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $applicationId,
    ) {}
}
