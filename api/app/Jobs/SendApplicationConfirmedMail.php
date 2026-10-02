<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationKind;
use App\Mail\ApplicationConfirmedMail;
use App\Models\Application;
use App\Services\NotificationLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F-20 確定通知を 1 通送るジョブ（docs/03 3.1、async-rules。Rails の ActiveJob + deliver_now に相当）。
 *
 * 最初は Mail::to()->queue() で Mailable をそのままキューに入れていた（docs/03 3.1 の学習手順）。
 * それだと「実行時に最新の状態を見る」「claim → 送信 → markSent」を挟む場所がない（SendQueuedMailable が
 * いきなり送る）ので、送る前後の手順を自分で書けるようにジョブに包み直した。
 *
 * - コンストラクタは応募ID だけ。実行時に読み直し、もう確定でなければ送らない（確定の直後にキャンセルされた等）
 * - 企業は積んだときの Context（CurrentCompany）がワーカーで戻る。auth() は使わない。
 *   Context が戻っていなければ Application の読み込みがグローバルスコープで LogicException になる（閉じた側に倒れる）
 * - メール送信はトランザクションの外。claim / markSent の書き込みはそれぞれ短い
 * https://readouble.com/laravel/13.x/ja/queues.html
 */
class SendApplicationConfirmedMail implements ShouldQueue
{
    use Queueable;

    /** 3 回まで試す */
    public int $tries = 3;

    public function __construct(public readonly int $applicationId)
    {
        $this->onQueue('mails');
    }

    /**
     * 1 回目の再試行は 10 秒後、2 回目は 60 秒後（すぐ再試行しても同じ理由で失敗するため）
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * 同じ通知のジョブを同時に 2 つ走らせない（キャッシュのロック。キーは dedupe_key）。notification_logs の UNIQUE が
     * 止めるのは「送信済みの後」だけなので、同時に送信待ちの行を受け取る 2 本目はここで止める（docs/03 3.3）。
     *
     * - dontRelease(): 重なった 2 本目は戻さずに捨てる（成功として終わる）。1 本目が送るので要らない。
     *   戻すと後回しの回が tries を食い、1 本目が送った後でも 2 本目が failed() まで行って誤った「失敗」が残りうる。
     *   1 本目が失敗したら 1 本目自身が backoff で再試行する
     * - expireAfter(120): ワーカーが落ちてロックが残っても 120 秒で外れる
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->dedupeKey()))->dontRelease()->expireAfter(120)];
    }

    public function handle(NotificationLogService $logs): void
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);

        // 消えた・他社（スコープで見えない）・もう確定ではない応募には送らない
        if ($application === null || $application->status !== ApplicationStatus::Confirmed) {
            return;
        }

        $log = $logs->claim(NotificationKind::ApplicationConfirmed, $application->worker, $application);
        if ($log === null) {
            return; // 送信済み（再試行・二重投入・手動の再実行）
        }

        Mail::to($log->recipient_email)->send(new ApplicationConfirmedMail($application));
        $logs->markSent($log);
    }

    /**
     * 全部の試行が失敗した後に 1 回だけ呼ばれる。claim の後に失敗していれば業務の記録を failed にする
     * （claim の前の失敗なら行がないので何もしない）。生の例外は failed_jobs とログに残る
     */
    public function failed(?Throwable $e): void
    {
        app(NotificationLogService::class)->markFailedByKey($this->dedupeKey(), $e);
    }

    /**
     * application_confirmed:application:{応募ID}。dedupeKey() は対象のモデルを受け取るので、ID だけを持つ未保存の
     * インスタンスを作って渡す（DB は読まない。failed() は応募が消えていても呼ばれる）
     */
    private function dedupeKey(): string
    {
        $subject = new Application;
        $subject->setAttribute($subject->getKeyName(), $this->applicationId);

        return NotificationLogService::dedupeKey(NotificationKind::ApplicationConfirmed, $subject);
    }
}
