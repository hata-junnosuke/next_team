<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ワーカーへの招待を発行した（F-26・F-27。登録・送り直し）。リスナーが招待メールのジョブを積む。
 *
 * - ShouldDispatchAfterCommit: 発行のトランザクション（ワーカーの登録と一緒のときは外側も）がコミットされてから発火する。
 *   巻き戻ったら発火しない（Rails の after_commit に相当）。
 *   https://readouble.com/laravel/13.x/ja/events.html#dispatching-events-after-database-transactions
 * - 招待の ID だけを持つ。招待コードは持たない（キューのペイロードとログに秘密を入れない。docs/01 の 3.2、security.md）。
 *   コードはジョブが実行時に暗号化した code 列から読む
 */
class WorkerInvited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $invitationId,
    ) {}
}
