<?php

namespace App\Listeners;

use App\Events\ApplicationConfirmed;
use App\Jobs\SendApplicationConfirmedMail;

/**
 * 応募が確定したら（コミット後）確定通知のジョブを mails キューに積む。積むだけで、応募は読まない・送らない
 * （ジョブが実行時に読み直す）。サービスは event() を呼ぶだけで、メールのことを知らない。
 *
 * app/Listeners の handle() の型からイベントが自動で結び付く（イベントディスカバリ。php artisan event:list で確認できる）。
 * https://readouble.com/laravel/13.x/ja/events.html#event-discovery
 */
class QueueApplicationConfirmedMail
{
    public function handle(ApplicationConfirmed $event): void
    {
        SendApplicationConfirmedMail::dispatch($event->applicationId);
    }
}
