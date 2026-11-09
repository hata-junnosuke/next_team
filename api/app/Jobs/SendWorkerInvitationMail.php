<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * F-26 招待メールを 1 通送るジョブ（docs/01 の非同期の契約）。
 *
 * issue #37 の T4 では「積まれること」を確かめるための最小の形（招待の ID だけを受けて mails キューに載る）にとどめる。
 * 送信（claim → 送信 → markSent）・RateLimited / WithoutOverlapping・retryUntil・backoff・failed() は T5 で書く。
 * コンストラクタは招待の ID だけ。招待コードは渡さない（ペイロードに秘密を入れない。コードは実行時に code 列から読む）
 */
class SendWorkerInvitationMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $invitationId)
    {
        $this->onQueue('mails');
    }
}
