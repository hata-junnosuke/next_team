<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationKind;
use App\Mail\ApplicationConfirmedMail;
use App\Models\Application;
use App\Services\NotificationLogService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F-20 確定通知を 1 通送るジョブ（async-rules。Rails の ActiveJob + deliver_now に相当）。
 *
 * Mail::to()->queue() で Mailable をそのままキューに入れると、「実行時に最新の状態を見る」
 * 「claim → 送信 → markSent」を挟む場所がない（SendQueuedMailable がいきなり送る）ので、
 * 送る前後の手順を自分で書けるようにジョブにしている。
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

    /**
     * 試行の回数では打ち切らない（0 = 無制限）。打ち切りは「例外 3 回」（$maxExceptions）か「積んでから一定時間」（retryUntil()）。
     *
     * $tries = 3 だと、キューへ戻された回（流量制限の RateLimited など）も試行に数えられ、戻される回が続くと
     * 1 通も送ろうとしないまま failed になる。しかも claim の前なので notification_logs に行が残らず、
     * 「届いていない」が画面から見えない（要件 7 章に反する。中止通知で tester が再現）。
     * メールのジョブは tries=0 + maxExceptions + retryUntil にそろえる（async-rules）。
     * https://readouble.com/laravel/13.x/ja/queues.html#max-exceptions
     */
    public int $tries = 0;

    /** 送信などで例外が 3 回出たら失敗にする（キューへ戻された回は数えない） */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $applicationId)
    {
        $this->onQueue('mails');
    }

    /**
     * 積んでから config('myteam.mail_retry_until_minutes') 分（既定 60 分）を過ぎたら、それ以上は試さず failed() に入る。
     * 積んだときに計算されてペイロードに入る（queue:retry で再実行すると、その時点から数え直される）
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('myteam.mail_retry_until_minutes'));
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
     * - RateLimited('mails'): メールの 1 分あたりの送信数を抑える。流量はメールサーバー全体の制約なので、
     *   メールを送るジョブ（確定通知・中止通知・前日リマインド）は同じリミッターを使う（上限は AppServiceProvider の
     *   RateLimiter::for('mails')。超えた分は送らずにキューへ戻す）。ロックを取る前に判定するので先に置く
     *
     * 同じ通知のジョブを同時に 2 つ走らせない（キャッシュのロック。キーは dedupe_key）。notification_logs の UNIQUE が
     * 止めるのは「送信済みの後」だけなので、同時に送信待ちの行を受け取る 2 本目はここで止める。
     *
     * - dontRelease(): 重なった 2 本目は戻さずに捨てる（成功として終わる）。1 本目が送るので要らない。
     *   戻すと後回しの回が再試行を重ね、1 本目が送った後でも 2 本目が failed() まで行って誤った「失敗」が残りうる。
     *   1 本目が失敗したら 1 本目自身が backoff で再試行する
     * - expireAfter(120): ワーカーが落ちてロックが残っても 120 秒で外れる
     * - キーに企業ID を入れないのは、応募ID が全社の通し番号で企業をまたいでも重ならないため（dedupe_key と同じ）
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('mails'),
            (new WithoutOverlapping($this->dedupeKey()))->dontRelease()->expireAfter(120),
        ];
    }

    public function handle(NotificationLogService $logs): void
    {
        $application = $this->target();
        if ($application === null) {
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
     * 例外 3 回（maxExceptions）か期限切れ（retryUntil）で打ち切った後に 1 回だけ呼ばれる。業務の記録を failed にする:
     * - claim の後に失敗していれば、その行を failed にする
     * - 行がない（claim の前に打ち切られた。例: 流量制限で戻され続けて期限切れ）なら、まだ送るべき対象のときだけ
     *   recordFailure() で failed の行を作る（「届いていない」を画面から見えるように。要件 7 章）。対象でなければ作らない
     * failed() から例外が漏れるとワーカーの失敗処理が途中で止まるので、記録の失敗は report() してログに残すだけにする。
     * 生の例外は failed_jobs とログに残る
     */
    public function failed(?Throwable $e): void
    {
        try {
            $logs = app(NotificationLogService::class);
            if ($logs->markFailedByKey($this->dedupeKey(), $e)) {
                return;
            }

            $application = $this->target();
            if ($application !== null) {
                $logs->recordFailure(NotificationKind::ApplicationConfirmed, $application->worker, $application, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }

    /**
     * 送るべき応募（宛先と表示に要る関連を読み込み済み）。送らないなら null。handle() と failed() で同じ判定を使う。
     * 消えた・他社（スコープで見えない）・もう確定ではない応募には送らない（確定の直後にキャンセルされた等）
     */
    private function target(): ?Application
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);

        if ($application === null || $application->status !== ApplicationStatus::Confirmed) {
            return null;
        }

        return $application;
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
