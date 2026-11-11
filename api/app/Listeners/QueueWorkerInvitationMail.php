<?php

namespace App\Listeners;

use App\Events\WorkerInvited;
use App\Jobs\SendWorkerInvitationMail;

/**
 * 招待を発行したら（コミット後）招待メールのジョブを mails キューに積む。積むだけで、招待は読まない・送らない
 * （ジョブが実行時に読み直し、使えない招待や無効のワーカーには送らない）。
 *
 * app/Listeners の handle() の型からイベントが自動で結び付く（イベントディスカバリ）。
 * https://readouble.com/laravel/13.x/ja/events.html#event-discovery
 */
class QueueWorkerInvitationMail
{
    public function handle(WorkerInvited $event): void
    {
        SendWorkerInvitationMail::dispatch($event->invitationId);
    }
}
