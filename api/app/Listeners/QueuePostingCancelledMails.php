<?php

namespace App\Listeners;

use App\Events\JobPostingCancelled;
use App\Jobs\SendPostingCancelledMail;

/**
 * 募集が中止されたら（コミット後）、この中止で 確定 → 企業都合キャンセル になった応募 1 件ごとに中止通知のジョブを積む。
 * 1 ジョブで全員に送らない（1 人の失敗が他の人に響かないように。docs/03 3.2）。積むだけで、応募は読まない・送らない。
 * イベントディスカバリで JobPostingCancelled に結び付く（php artisan event:list で確認できる）。
 * https://readouble.com/laravel/13.x/ja/events.html#event-discovery
 */
class QueuePostingCancelledMails
{
    public function handle(JobPostingCancelled $event): void
    {
        foreach ($event->cancelledApplicationIds as $applicationId) {
            SendPostingCancelledMail::dispatch($applicationId);
        }
    }
}
